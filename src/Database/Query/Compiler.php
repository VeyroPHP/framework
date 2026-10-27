<?php

declare(strict_types=1);

namespace VeyroPHP\Database\Query;

use VeyroPHP\Database\Dialect\Dialect;
use VeyroPHP\Database\Exception\DatabaseException;

/**
 * Turns a Builder into SQL and a list of positional bindings.
 *
 * @phpstan-import-type Where from Builder
 *
 * @internal Framework implementation detail, not covered by the backward-compatibility promise.
 */
final class Compiler
{
    public function __construct(private readonly Dialect $dialect) {}

    /**
     * @return array{string, list<mixed>}
     */
    public function select(Builder $query): array
    {
        $parts = $query->parts();
        $bindings = [];

        $columns = $parts['columns'] === [] ? '*' : implode(', ', array_map($this->dialect->quoteIdentifier(...), $parts['columns']));
        $sql = 'select ' . ($parts['distinct'] ? 'distinct ' : '') . $columns . ' from ' . $this->table($parts['table']);

        foreach ($parts['joins'] as $join) {
            $sql .= sprintf(
                ' %s join %s on %s %s %s',
                $join['type'],
                $this->dialect->quoteIdentifier($join['table']),
                $this->dialect->quoteIdentifier($join['first']),
                $join['operator'],
                $this->dialect->quoteIdentifier($join['second']),
            );
        }

        $sql .= $this->whereClause($parts['wheres'], $bindings);

        if ($parts['groups'] !== []) {
            $sql .= ' group by ' . implode(', ', array_map($this->dialect->quoteIdentifier(...), $parts['groups']));
        }

        if ($parts['havings'] !== []) {
            $sql .= ' having ' . $this->conditions($parts['havings'], $bindings);
        }

        if ($parts['orders'] !== []) {
            $sql .= ' order by ' . implode(', ', array_map(
                fn(array $order): string => $this->dialect->quoteIdentifier($order['column']) . ' ' . $order['direction'],
                $parts['orders'],
            ));
        }

        $sql .= $this->dialect->compileLimit($parts['limit'], $parts['offset']);

        if ($parts['lock'] !== null) {
            $sql .= $this->dialect->compileLock($parts['lock']['mode'], $parts['lock']['skip'], $parts['lock']['nowait']);
        }

        return [$sql, $bindings];
    }

    /**
     * @return array{string, list<mixed>}
     */
    public function aggregate(Builder $query, string $function, string $column): array
    {
        $parts = $query->parts();
        $base = $query->withoutPaging();

        // With grouping or distinct, count the rows of the full query as a subquery.
        if ($parts['groups'] !== [] || $parts['distinct']) {
            [$inner, $bindings] = $this->select($base);

            return [sprintf('select %s(*) as %s from (%s) as %s', $function, $this->dialect->quoteIdentifier('aggregate'), $inner, $this->dialect->quoteIdentifier('aggregate_table')), $bindings];
        }

        $expression = sprintf('%s(%s) as %s', $function, $column === '*' ? '*' : $this->dialect->quoteIdentifier($column), $this->dialect->quoteIdentifier('aggregate'));

        return $this->select($base->select(new Expression($expression)));
    }

    /**
     * @return array{string, list<mixed>}
     */
    public function exists(Builder $query): array
    {
        [$inner, $bindings] = $this->select($query->withoutPaging());

        return [sprintf('select exists(%s) as %s', $inner, $this->dialect->quoteIdentifier('exists')), $bindings];
    }

    /**
     * @param non-empty-list<array<string, mixed>> $rows
     *
     * @return array{string, list<mixed>}
     */
    public function insert(Builder $query, array $rows): array
    {
        $columns = array_keys($rows[0]);
        $bindings = [];
        $placeholders = [];

        foreach ($rows as $row) {
            $values = [];
            foreach ($columns as $column) {
                $values[] = $this->valueSql($row[$column], $bindings);
            }
            $placeholders[] = '(' . implode(', ', $values) . ')';
        }

        $sql = sprintf(
            'insert into %s (%s) values %s',
            $this->table($query->parts()['table']),
            implode(', ', array_map($this->dialect->quoteIdentifier(...), $columns)),
            implode(', ', $placeholders),
        );

        return [$sql, $bindings];
    }

    /**
     * @param non-empty-list<array<string, mixed>> $rows
     * @param list<string> $uniqueBy
     * @param list<string> $update
     *
     * @return array{string, list<mixed>}
     */
    public function upsert(Builder $query, array $rows, array $uniqueBy, array $update): array
    {
        [$sql, $bindings] = $this->insert($query, $rows);

        $sql .= $this->dialect->compileUpsertSuffix(
            array_map($this->dialect->quoteIdentifier(...), $uniqueBy),
            array_map($this->dialect->quoteIdentifier(...), $update),
        );

        return [$sql, $bindings];
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array{string, list<mixed>}
     */
    public function update(Builder $query, array $values): array
    {
        $parts = $query->parts();
        $this->assertNoJoinsOrPaging($parts, 'update');
        $bindings = [];
        $sets = [];

        foreach ($values as $column => $value) {
            $sets[] = $this->dialect->quoteIdentifier($column) . ' = ' . $this->valueSql($value, $bindings);
        }

        $sql = sprintf('update %s set %s', $this->table($parts['table']), implode(', ', $sets));
        $sql .= $this->whereClause($parts['wheres'], $bindings);

        return [$sql, $bindings];
    }

    /**
     * @return array{string, list<mixed>}
     */
    public function delete(Builder $query): array
    {
        $parts = $query->parts();
        $this->assertNoJoinsOrPaging($parts, 'delete');
        $bindings = [];

        $sql = 'delete from ' . $this->table($parts['table']) . $this->whereClause($parts['wheres'], $bindings);

        return [$sql, $bindings];
    }

    private function table(string|Expression|null $table): string
    {
        if ($table === null) {
            throw DatabaseException::invalidQuery('No table given; call table() or from() first.');
        }

        return $this->dialect->quoteIdentifier($table);
    }

    /**
     * @param list<Where> $wheres
     * @param list<mixed> $bindings
     */
    private function whereClause(array $wheres, array &$bindings): string
    {
        return $wheres === [] ? '' : ' where ' . $this->conditions($wheres, $bindings);
    }

    /**
     * @param list<Where> $wheres
     * @param list<mixed> $bindings
     */
    private function conditions(array $wheres, array &$bindings): string
    {
        $sql = '';

        foreach ($wheres as $index => $where) {
            $condition = $this->condition($where, $bindings);

            if ($condition === null) {
                continue;
            }

            $sql .= ($sql === '' ? '' : ' ' . $where['boolean'] . ' ') . $condition;
        }

        return $sql === '' ? '1 = 1' : $sql;
    }

    /**
     * @param Where $where
     * @param list<mixed> $bindings
     */
    private function condition(array $where, array &$bindings): ?string
    {
        switch ($where['type']) {
            case 'basic':
                return sprintf('%s %s %s', $this->dialect->quoteIdentifier($where['column']), $where['operator'], $this->valueSql($where['value'], $bindings));

            case 'in':
                if ($where['values'] === []) {
                    return $where['not'] ? '1 = 1' : '1 = 0';
                }
                // A loop, not array_map(fn ...): arrow functions capture $bindings by value and would drop the bindings.
                $placeholders = [];
                foreach ($where['values'] as $value) {
                    $placeholders[] = $this->valueSql($value, $bindings);
                }

                return sprintf('%s %sin (%s)', $this->dialect->quoteIdentifier($where['column']), $where['not'] ? 'not ' : '', implode(', ', $placeholders));

            case 'null':
                return $this->dialect->quoteIdentifier($where['column']) . ($where['not'] ? ' is not null' : ' is null');

            case 'between':
                return sprintf(
                    '%s %sbetween %s and %s',
                    $this->dialect->quoteIdentifier($where['column']),
                    $where['not'] ? 'not ' : '',
                    $this->valueSql($where['values'][0] ?? null, $bindings),
                    $this->valueSql($where['values'][1] ?? null, $bindings),
                );

            case 'column':
                return sprintf('%s %s %s', $this->dialect->quoteIdentifier($where['column']), $where['operator'], $this->dialect->quoteIdentifier($where['second']));

            case 'raw':
                foreach ($where['bindings'] as $binding) {
                    $bindings[] = $binding;
                }

                return '(' . $where['sql'] . ')';

            case 'json':
                [$expression, $path] = $this->dialect->compileJsonPath($this->dialect->quoteIdentifier($where['column']), $where['segments'], $where['numeric']);
                $bindings[] = $path;

                return sprintf('%s %s %s', $expression, $where['operator'], $this->valueSql($where['value'], $bindings));

            case 'fulltext':
                $sql = $this->dialect->compileFullText(array_map($this->dialect->quoteIdentifier(...), $where['columns']));
                $bindings[] = $where['value'];

                return $sql;

            case 'nested':
                $nestedWheres = $where['query']->parts()['wheres'];

                return $nestedWheres === [] ? null : '(' . $this->conditions($nestedWheres, $bindings) . ')';
        }
    }

    /**
     * @param list<mixed> $bindings
     */
    private function valueSql(mixed $value, array &$bindings): string
    {
        if ($value instanceof Expression) {
            return $value->sql;
        }

        $bindings[] = $value;

        return '?';
    }

    /**
     * @param array{joins: list<mixed>, orders: list<mixed>, limit: ?int, offset: ?int} $parts
     */
    private function assertNoJoinsOrPaging(array $parts, string $operation): void
    {
        if ($parts['joins'] !== [] || $parts['orders'] !== [] || $parts['limit'] !== null || $parts['offset'] !== null) {
            throw DatabaseException::invalidQuery(sprintf(
                '%s() does not support joins, order by, limit or offset, because their behavior differs between databases.',
                $operation,
            ));
        }
    }
}
