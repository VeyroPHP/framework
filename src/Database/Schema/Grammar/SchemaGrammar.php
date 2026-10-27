<?php

declare(strict_types=1);

namespace VeyroPHP\Database\Schema\Grammar;

use VeyroPHP\Database\Connection\Connection;
use VeyroPHP\Database\Dialect\Dialect;
use VeyroPHP\Database\Exception\DatabaseException;
use VeyroPHP\Database\Query\Expression;
use VeyroPHP\Database\Schema\Blueprint;
use VeyroPHP\Database\Schema\ColumnDefinition;
use VeyroPHP\Database\Schema\ForeignKeyDefinition;

/**
 * Compiles Blueprints into DDL statements for one database.
 *
 * All identifiers go through the dialect's validated quoting. DDL cannot use
 * bound parameters, so default values are rendered as escaped literals.
 *
 * @internal Framework implementation detail, not covered by the backward-compatibility promise.
 */
abstract class SchemaGrammar
{
    public function __construct(protected readonly Dialect $dialect) {}

    /**
     * @return list<string>
     */
    public function compileCreate(Blueprint $blueprint): array
    {
        $definitions = array_map(fn(ColumnDefinition $column): string => $this->columnSql($column), $blueprint->columns());

        foreach ($blueprint->foreignKeys() as $foreign) {
            $definitions[] = $this->foreignKeySql($foreign);
        }

        foreach ($blueprint->indexes() as $index) {
            if ($index['type'] === 'primary') {
                $definitions[] = sprintf('constraint %s primary key (%s)', $this->quote($index['name']), implode(', ', array_map($this->quote(...), $index['columns'])));
            }
        }

        if ($definitions === []) {
            throw DatabaseException::invalidQuery(sprintf('Table "%s" needs at least one column.', $blueprint->table));
        }

        $create = sprintf('create table %s (%s)%s', $this->quote($blueprint->table), implode(', ', $definitions), $this->createTableSuffix());

        return [$create, ...$this->indexStatements($blueprint)];
    }

    /**
     * @return list<string>
     */
    public function compileAlter(Blueprint $blueprint): array
    {
        $table = $this->quote($blueprint->table);
        $statements = [];

        foreach ($blueprint->columns() as $column) {
            $statements[] = sprintf('alter table %s add column %s', $table, $this->addedColumnSql($blueprint, $column));
        }

        foreach ($blueprint->foreignKeys() as $foreign) {
            $statement = $this->compileAddForeignKey($blueprint, $foreign);
            if ($statement !== null) {
                $statements[] = $statement;
            }
        }

        foreach ($blueprint->indexes() as $index) {
            if ($index['type'] === 'primary') {
                $statements[] = $this->compileAddPrimary($blueprint, $index['name'], $index['columns']);
            }
        }

        foreach ($blueprint->renamedColumns() as $rename) {
            $statements[] = sprintf('alter table %s rename column %s to %s', $table, $this->quote($rename['from']), $this->quote($rename['to']));
        }

        foreach ($blueprint->droppedIndexes() as $index) {
            $statements[] = $this->compileDropIndex($blueprint->table, $index);
        }

        foreach ($blueprint->droppedColumns() as $column) {
            $statements[] = sprintf('alter table %s drop column %s', $table, $this->quote($column));
        }

        return [...$statements, ...$this->indexStatements($blueprint)];
    }

    public function compileDrop(string $table, bool $ifExists): string
    {
        return 'drop table ' . ($ifExists ? 'if exists ' : '') . $this->quote($table);
    }

    public function compileRename(string $from, string $to): string
    {
        return sprintf('alter table %s rename to %s', $this->quote($from), $this->quote($to));
    }

    abstract public function hasTable(Connection $connection, string $table): bool;

    abstract public function hasColumn(Connection $connection, string $table, string $column): bool;

    abstract protected function typeSql(ColumnDefinition $column): string;

    abstract protected function idColumnSql(ColumnDefinition $column): string;

    abstract protected function compileDropIndex(string $table, string $index): string;

    protected function createTableSuffix(): string
    {
        return '';
    }

    protected function supportsUnsigned(): bool
    {
        return false;
    }

    /**
     * Column SQL used in ALTER TABLE ... ADD COLUMN.
     */
    protected function addedColumnSql(Blueprint $blueprint, ColumnDefinition $column): string
    {
        return $this->columnSql($column);
    }

    /**
     * @param list<string> $columns
     */
    protected function compileAddPrimary(Blueprint $blueprint, string $name, array $columns): string
    {
        return sprintf('alter table %s add constraint %s primary key (%s)', $this->quote($blueprint->table), $this->quote($name), implode(', ', array_map($this->quote(...), $columns)));
    }

    /**
     * @return string|null null when the constraint was already included elsewhere
     */
    protected function compileAddForeignKey(Blueprint $blueprint, ForeignKeyDefinition $foreign): ?string
    {
        return sprintf('alter table %s add %s', $this->quote($blueprint->table), $this->foreignKeySql($foreign));
    }

    protected function columnSql(ColumnDefinition $column): string
    {
        if ($column->type === 'id') {
            return $this->quote($column->name) . ' ' . $this->idColumnSql($column);
        }

        $sql = $this->quote($column->name) . ' ' . $this->typeSql($column);

        if ($column->unsigned && $this->supportsUnsigned()) {
            $sql .= ' unsigned';
        }

        $sql .= $column->nullable ? ' null' : ' not null';

        if ($column->hasDefault) {
            $sql .= ' default ' . $this->literal($column->default);
        }

        if ($column->allowed !== null) {
            $sql .= sprintf(' check (%s in (%s))', $this->quote($column->name), implode(', ', array_map($this->literal(...), $column->allowed)));
        }

        return $sql;
    }

    protected function foreignKeySql(ForeignKeyDefinition $foreign): string
    {
        return sprintf(
            'constraint %s foreign key (%s) references %s (%s) on delete %s on update %s',
            $this->quote($foreign->name),
            $this->quote($foreign->column),
            $this->quote($foreign->referencedTable),
            $this->quote($foreign->referencedColumn),
            $foreign->onDelete,
            $foreign->onUpdate,
        );
    }

    protected function literal(mixed $value): string
    {
        return match (true) {
            $value instanceof Expression => $value->sql,
            $value === null => 'null',
            is_bool($value) => $value ? '1' : '0',
            is_int($value) => (string) $value,
            is_float($value) => is_finite($value) ? var_export($value, true) : throw DatabaseException::invalidQuery('Default values must be finite numbers.'),
            is_string($value) => "'" . $this->escapeString($value) . "'",
            default => throw DatabaseException::invalidQuery(sprintf('Unsupported default value of type %s.', get_debug_type($value))),
        };
    }

    protected function escapeString(string $value): string
    {
        if (str_contains($value, "\0")) {
            throw DatabaseException::invalidQuery('Default values must not contain null bytes.');
        }

        return str_replace("'", "''", $value);
    }

    protected function quote(string $identifier): string
    {
        return $this->dialect->quoteIdentifier($identifier);
    }

    /**
     * @param list<string> $columns
     *
     * @throws DatabaseException when the database has no supported full-text index
     */
    protected function compileFullTextIndex(string $table, string $name, array $columns): string
    {
        throw DatabaseException::invalidQuery(sprintf('Full-text indexes are not supported on %s (index "%s"); use MySQL/MariaDB or PostgreSQL.', $this->dialect->displayName(), $name));
    }

    /**
     * @return list<string>
     */
    protected function indexStatements(Blueprint $blueprint): array
    {
        $statements = [];

        foreach ($blueprint->indexes() as $index) {
            if ($index['type'] === 'primary') {
                continue; // part of the table definition or compileAddPrimary()
            }

            if ($index['type'] === 'fulltext') {
                $statements[] = $this->compileFullTextIndex($blueprint->table, $index['name'], $index['columns']);
                continue;
            }

            $statements[] = sprintf(
                'create %sindex %s on %s (%s)',
                $index['type'] === 'unique' ? 'unique ' : '',
                $this->quote($index['name']),
                $this->quote($blueprint->table),
                implode(', ', array_map($this->quote(...), $index['columns'])),
            );
        }

        return $statements;
    }

    /**
     * @param list<mixed> $bindings
     */
    protected static function countIsPositive(Connection $connection, string $sql, array $bindings): bool
    {
        $row = $connection->selectOne($sql, $bindings);
        $value = $row === null ? 0 : (array_values($row)[0] ?? 0);

        return is_numeric($value) && (int) $value > 0;
    }
}
