<?php

declare(strict_types=1);

namespace VeyroPHP\Database\Query;

use Closure;
use Generator;
use VeyroPHP\Database\Connection\Connection;
use VeyroPHP\Database\Exception\DatabaseException;
use VeyroPHP\Database\Exception\InvalidCursorException;

/**
 * Fluent SQL query builder.
 *
 * Values are always bound as parameters. Table and column names are quoted
 * and validated by the dialect; anything that is not a plain identifier must
 * be passed explicitly as an Expression (Connection::raw()).
 *
 * Safety rules:
 *
 * - where('col', null) throws; use whereNull(), because "= NULL" never matches.
 * - Only these operators are accepted: =, !=, <>, <, >, <=, >=, like, not like.
 * - update() and delete() without any effective where clause throw (an empty
 *   closure group does not count); use updateAll() or deleteAll() when every row
 *   is really meant.
 * - whereIn() with an empty list matches nothing; whereNotIn() with an empty
 *   list matches everything.
 *
 * One shape per where type (DB-11), so a missing key is a PHPStan error, not a compiler default:
 *
 * @phpstan-type BasicWhere array{type: 'basic', boolean: string, column: string|Expression, operator: string, value: mixed}
 * @phpstan-type InWhere array{type: 'in', boolean: string, column: string|Expression, values: list<mixed>, not: bool}
 * @phpstan-type NullWhere array{type: 'null', boolean: string, column: string|Expression, not: bool}
 * @phpstan-type BetweenWhere array{type: 'between', boolean: string, column: string|Expression, values: list<mixed>, not: bool}
 * @phpstan-type ColumnWhere array{type: 'column', boolean: string, column: string, operator: string, second: string}
 * @phpstan-type RawWhere array{type: 'raw', boolean: string, sql: string, bindings: list<mixed>}
 * @phpstan-type JsonWhere array{type: 'json', boolean: string, column: string|Expression, segments: list<string|int>, numeric: bool, operator: string, value: mixed}
 * @phpstan-type FullTextWhere array{type: 'fulltext', boolean: string, columns: non-empty-list<string>, value: string}
 * @phpstan-type NestedWhere array{type: 'nested', boolean: string, query: Builder}
 * @phpstan-type Where BasicWhere|InWhere|NullWhere|BetweenWhere|ColumnWhere|RawWhere|JsonWhere|FullTextWhere|NestedWhere
 * @phpstan-type Lock array{mode: 'update'|'share', skip: bool, nowait: bool}
 */
final class Builder
{
    public const OPERATORS = ['=', '!=', '<>', '<', '>', '<=', '>=', 'like', 'not like'];

    private string|Expression|null $table = null;

    /** @var list<string|Expression> */
    private array $columns = [];

    private bool $distinct = false;

    /** @var list<array{type: string, table: string|Expression, first: string, operator: string, second: string}> */
    private array $joins = [];

    /** @var list<Where> */
    private array $wheres = [];

    /** @var list<Builder> groups ANDed with all where clauses when the query is compiled */
    private array $constraints = [];

    /** @var list<string|Expression> */
    private array $groups = [];

    /** @var list<Where> */
    private array $havings = [];

    /** @var list<array{column: string|Expression, direction: string}> */
    private array $orders = [];

    private ?int $limit = null;

    private ?int $offset = null;

    /** @var Lock|null */
    private ?array $lock = null;

    /** "address.city", "tags[1]", "items[0].sku": keys of letters, digits and "_", array indexes in brackets. */
    private const JSON_PATH = '/^[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*|\[\d{1,9}\])*$/D';

    public function __construct(private readonly Connection $connection) {}

    public function from(string|Expression $table): self
    {
        $this->table = $table;

        return $this;
    }

    /**
     * @param string|Expression|list<string|Expression> ...$columns select('a', 'b') or select(['a', 'b'])
     */
    public function select(string|Expression|array ...$columns): self
    {
        $this->columns = [];

        foreach ($columns as $column) {
            foreach (is_array($column) ? $column : [$column] as $item) {
                $this->columns[] = $item;
            }
        }

        return $this;
    }

    public function addSelect(string|Expression ...$columns): self
    {
        foreach ($columns as $column) {
            $this->columns[] = $column;
        }

        return $this;
    }

    public function distinct(): self
    {
        $this->distinct = true;

        return $this;
    }

    // Joins

    public function join(string|Expression $table, string $first, string $operator, string $second): self
    {
        return $this->addJoin('inner', $table, $first, $operator, $second);
    }

    public function leftJoin(string|Expression $table, string $first, string $operator, string $second): self
    {
        return $this->addJoin('left', $table, $first, $operator, $second);
    }

    // Where clauses

    /**
     * where('age', '>', 18), where('status', 'active') or where(fn (Builder $q) => ...) for a nested group.
     */
    public function where(string|Expression|Closure $column, mixed $operator = null, mixed $value = null): self
    {
        return $this->addWhere('and', $column, $operator, $value, func_num_args());
    }

    public function orWhere(string|Expression|Closure $column, mixed $operator = null, mixed $value = null): self
    {
        return $this->addWhere('or', $column, $operator, $value, func_num_args());
    }

    /**
     * @param list<mixed> $values
     */
    public function whereIn(string|Expression $column, array $values): self
    {
        return $this->addIn('and', $column, $values, false);
    }

    /**
     * @param list<mixed> $values
     */
    public function whereNotIn(string|Expression $column, array $values): self
    {
        return $this->addIn('and', $column, $values, true);
    }

    /**
     * @param list<mixed> $values
     */
    public function orWhereIn(string|Expression $column, array $values): self
    {
        return $this->addIn('or', $column, $values, false);
    }

    public function whereNull(string|Expression $column): self
    {
        $this->wheres[] = ['type' => 'null', 'boolean' => 'and', 'column' => $column, 'not' => false];

        return $this;
    }

    public function whereNotNull(string|Expression $column): self
    {
        $this->wheres[] = ['type' => 'null', 'boolean' => 'and', 'column' => $column, 'not' => true];

        return $this;
    }

    public function orWhereNull(string|Expression $column): self
    {
        $this->wheres[] = ['type' => 'null', 'boolean' => 'or', 'column' => $column, 'not' => false];

        return $this;
    }

    /**
     * @param array{mixed, mixed} $range
     */
    public function whereBetween(string|Expression $column, array $range): self
    {
        return $this->addBetween($column, $range, false);
    }

    /**
     * @param array{mixed, mixed} $range
     */
    public function whereNotBetween(string|Expression $column, array $range): self
    {
        return $this->addBetween($column, $range, true);
    }

    /**
     * Compares two columns: whereColumn('updated_at', '>', 'created_at').
     */
    public function whereColumn(string $first, string $operator, string $second): self
    {
        $this->wheres[] = ['type' => 'column', 'boolean' => 'and', 'column' => $first, 'operator' => self::operator($operator), 'second' => $second];

        return $this;
    }

    /**
     * Raw SQL condition with positional bindings. Never put user input into $sql.
     *
     * @param list<mixed> $bindings
     */
    public function whereRaw(string $sql, array $bindings = []): self
    {
        $this->wheres[] = ['type' => 'raw', 'boolean' => 'and', 'sql' => $sql, 'bindings' => $bindings];

        return $this;
    }

    /**
     * @param list<mixed> $bindings
     */
    public function orWhereRaw(string $sql, array $bindings = []): self
    {
        $this->wheres[] = ['type' => 'raw', 'boolean' => 'or', 'sql' => $sql, 'bindings' => $bindings];

        return $this;
    }

    // Grouping and ordering

    public function groupBy(string|Expression ...$columns): self
    {
        foreach ($columns as $column) {
            $this->groups[] = $column;
        }

        return $this;
    }

    public function having(string|Expression $column, string $operator, mixed $value): self
    {
        self::assertBindable($value, 'having');
        $this->havings[] = ['type' => 'basic', 'boolean' => 'and', 'column' => $column, 'operator' => self::operator($operator), 'value' => $value];

        return $this;
    }

    public function orderBy(string|Expression $column, string $direction = 'asc'): self
    {
        $direction = strtolower($direction);

        if ($direction !== 'asc' && $direction !== 'desc') {
            throw DatabaseException::invalidQuery(sprintf('Order direction must be "asc" or "desc", "%s" given.', $direction));
        }

        $this->orders[] = ['column' => $column, 'direction' => $direction];

        return $this;
    }

    public function orderByDesc(string|Expression $column): self
    {
        return $this->orderBy($column, 'desc');
    }

    public function limit(int $limit): self
    {
        if ($limit < 0) {
            throw DatabaseException::invalidQuery('Limit must not be negative.');
        }

        $this->limit = $limit;

        return $this;
    }

    public function offset(int $offset): self
    {
        if ($offset < 0) {
            throw DatabaseException::invalidQuery('Offset must not be negative.');
        }

        $this->offset = $offset;

        return $this;
    }

    public function forPage(int $page, int $perPage): self
    {
        if ($page < 1 || $perPage < 1) {
            throw DatabaseException::invalidQuery('Page and per-page values must be at least 1.');
        }

        return $this->limit($perPage)->offset(($page - 1) * $perPage);
    }

    /**
     * Compares the value at a path inside a JSON column:
     *
     *     ->whereJsonPath('meta', 'address.city', 'Jaipur')
     *     ->whereJsonPath('meta', 'stock', '>', 5)       // numbers compare as numbers
     *     ->whereJsonPath('meta', 'tags[0]', 'sale')
     *
     * The path is validated (keys of letters, digits and "_"; indexes in brackets) and
     * bound as a parameter. Booleans and null are refused: databases read them differently.
     *
     * @throws DatabaseException for an invalid path or value
     */
    public function whereJsonPath(string $column, string $path, mixed $operator, mixed $value = null): self
    {
        if (func_num_args() === 3) {
            [$value, $operator] = [$operator, '='];
        }

        if (preg_match(self::JSON_PATH, $path) !== 1) {
            throw DatabaseException::invalidQuery(sprintf('Invalid JSON path "%s": use keys of letters, digits and "_" separated by dots, and [n] for array items.', $path));
        }

        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            throw DatabaseException::invalidQuery(sprintf('whereJsonPath() compares with a string or a number, %s given.', get_debug_type($value)));
        }

        if (!is_string($operator)) {
            throw DatabaseException::invalidOperator(get_debug_type($operator));
        }

        preg_match_all('/([A-Za-z_][A-Za-z0-9_]*)|\[(\d+)\]/', $path, $matches, PREG_SET_ORDER);
        $segments = [];

        foreach ($matches as $match) {
            $segments[] = ($match[1] ?? '') !== '' ? $match[1] : (int) ($match[2] ?? 0);
        }

        $this->wheres[] = [
            'type' => 'json',
            'boolean' => 'and',
            'column' => $column,
            'segments' => $segments,
            'numeric' => !is_string($value),
            'operator' => self::operator($operator),
            'value' => $value,
        ];

        return $this;
    }

    /**
     * Full-text search over one or more text columns (natural language on MySQL/MariaDB,
     * plainto_tsquery with the "simple" configuration on PostgreSQL). Needs a matching
     * index from Blueprint::fullText() over the same columns in the same order.
     * SQLite is refused rather than imitated with LIKE.
     *
     * @param list<string> $columns
     *
     * @throws DatabaseException without columns or search text
     */
    public function whereFullText(array $columns, string $search): self
    {
        if ($columns === [] || trim($search) === '') {
            throw DatabaseException::invalidQuery('whereFullText() needs at least one column and a search text.');
        }

        $this->wheres[] = ['type' => 'fulltext', 'boolean' => 'and', 'columns' => $columns, 'value' => $search];

        return $this;
    }

    /**
     * Locks the selected rows until the transaction ends ("select ... for update"), so a
     * read-check-write cannot be overtaken by another transaction. With $skipLocked,
     * rows locked elsewhere are left out (work queues); with $noWait, a locked row is
     * an error instead of a wait. SQLite has no row locks and ignores it.
     *
     * Reading outside a transaction throws: the lock would end with the statement.
     */
    public function lockForUpdate(bool $skipLocked = false, bool $noWait = false): self
    {
        if ($skipLocked && $noWait) {
            throw DatabaseException::invalidQuery('Use either skipLocked or noWait, not both.');
        }

        $this->lock = ['mode' => 'update', 'skip' => $skipLocked, 'nowait' => $noWait];

        return $this;
    }

    /**
     * Locks the selected rows against changes, but not against other shared locks,
     * until the transaction ends. Reading outside a transaction throws.
     */
    public function sharedLock(): self
    {
        $this->lock = ['mode' => 'share', 'skip' => false, 'nowait' => false];

        return $this;
    }

    /**
     * A copy whose where clauses form one group, so conditions added to the copy apply
     * to every row the original matched: "a or b" becomes "(a or b) and ...". Used for
     * keyset pagination, soft deletes and global scopes.
     */
    public function withGroupedWheres(): self
    {
        $query = clone $this;

        foreach ($query->wheres as $index => $where) {
            if ($index > 0 && $where['boolean'] === 'or') {
                $group = new self($this->connection);
                $group->wheres = $query->wheres;
                $query->wheres = [['type' => 'nested', 'boolean' => 'and', 'query' => $group]];
                break;
            }
        }

        return $query;
    }

    /**
     * @internal a condition every row must meet, used by the ORM for global scopes and
     * soft deletes. It is ANDed with all where clauses at compile time, so an orWhere()
     * added later cannot widen it. It is not the where clause update() and delete()
     * insist on: a constrained query still needs a condition of its own (DB-1).
     *
     * @param Closure(Builder): mixed $condition
     */
    public function constrain(Closure $condition): self
    {
        $group = new self($this->connection);
        $condition($group);
        $this->constraints[] = $group;

        return $this;
    }

    // Reading

    /**
     * @return list<array<string, mixed>>
     */
    public function get(): array
    {
        $this->assertLockInTransaction();
        [$sql, $bindings] = $this->compiler()->select($this);

        return $this->connection->select($sql, $bindings);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function first(): ?array
    {
        $this->assertLockInTransaction();
        [$sql, $bindings] = $this->compiler()->select((clone $this)->limit(1));

        return $this->connection->selectOne($sql, $bindings);
    }

    /**
     * The value of one column in the first row, or null when there is no row.
     */
    public function value(string $column): mixed
    {
        $row = (clone $this)->select($column)->first();

        return $row === null ? null : (array_values($row)[0] ?? null);
    }

    /**
     * @return list<mixed>
     */
    public function pluck(string $column): array
    {
        return array_map(
            static fn(array $row): mixed => array_values($row)[0] ?? null,
            (clone $this)->select($column)->get(),
        );
    }

    public function exists(): bool
    {
        [$sql, $bindings] = $this->compiler()->exists($this);
        $row = $this->connection->selectOne($sql, $bindings);

        return $row !== null && (bool) ($row['exists'] ?? false);
    }

    public function count(string $column = '*'): int
    {
        $value = $this->aggregate('count', $column);

        return is_numeric($value) ? (int) $value : 0;
    }

    public function sum(string $column): int|float
    {
        $value = $this->aggregate('sum', $column);

        return is_numeric($value) ? $value + 0 : 0;
    }

    public function avg(string $column): ?float
    {
        $value = $this->aggregate('avg', $column);

        return is_numeric($value) ? (float) $value : null;
    }

    public function min(string $column): mixed
    {
        return $this->aggregate('min', $column);
    }

    public function max(string $column): mixed
    {
        return $this->aggregate('max', $column);
    }

    /**
     * Runs a count query and a page query. Fine for small and medium tables; on
     * millions of rows the COUNT(*) and the OFFSET of deep pages get slow (both
     * read every skipped row), so use cursorPaginate() there.
     */
    public function paginate(int $perPage = 15, int $page = 1): Paginator
    {
        $total = $this->count();
        $items = (clone $this)->forPage($page, $perPage)->get();

        return new Paginator($items, $total, $perPage, $page);
    }

    /**
     * Pages through rows ordered by a unique column ("keyset" pagination): no
     * COUNT(*) and no OFFSET, so every page costs the same on any table size.
     * Pass the previous page's next_cursor to get the following page.
     *
     * @param string $column a unique, indexed column that every row has (usually the primary key)
     *
     * @return CursorPaginator<array<string, mixed>>
     *
     * @throws InvalidCursorException for a cursor that was not produced here (400 over HTTP)
     */
    public function cursorPaginate(int $perPage = 15, ?string $cursor = null, string $column = 'id', string $direction = 'asc'): CursorPaginator
    {
        if ($perPage < 1) {
            throw DatabaseException::invalidQuery('Items per page must be at least 1.');
        }

        $query = $this->keysetQuery('cursorPaginate', $column, $direction);

        if ($cursor !== null) {
            $query->where($column, $direction === 'asc' ? '>' : '<', CursorPaginator::decode($cursor));
        }

        $rows = $query->limit($perPage + 1)->get();
        $hasMore = count($rows) > $perPage;
        $items = array_slice($rows, 0, $perPage);
        $next = $hasMore ? CursorPaginator::encode(self::keyOf($items[$perPage - 1], $column)) : null;

        return new CursorPaginator($items, $perPage, $next);
    }

    /**
     * Calls $callback with consecutive chunks of rows ordered by $column, reading
     * one chunk at a time, so memory stays flat on tables of any size. Each chunk
     * is fetched with "where $column > last" instead of OFFSET. Return false from
     * the callback to stop.
     *
     * @param Closure(list<array<string, mixed>>): (bool|null|void) $callback
     *
     * @return bool false when the callback stopped early
     */
    public function chunkById(int $size, Closure $callback, string $column = 'id'): bool
    {
        foreach ($this->chunksById($size, $column) as $chunk) {
            if ($callback($chunk) === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Yields rows one by one, fetching $chunkSize rows per query (see chunkById()).
     *
     * @return Generator<int, array<string, mixed>>
     */
    public function lazyById(int $chunkSize = 1000, string $column = 'id'): Generator
    {
        $index = 0;

        foreach ($this->chunksById($chunkSize, $column) as $chunk) {
            foreach ($chunk as $row) {
                yield $index++ => $row;
            }
        }
    }

    /**
     * The chunks used by chunkById() and lazyById().
     *
     * @return Generator<int, list<array<string, mixed>>>
     */
    public function chunksById(int $size, string $column = 'id'): Generator
    {
        if ($size < 1) {
            throw DatabaseException::invalidQuery('Chunk size must be at least 1.');
        }

        $base = $this->keysetQuery('chunkById', $column, 'asc');
        $last = null;

        do {
            $query = clone $base;
            if ($last !== null) {
                $query->where($column, '>', $last);
            }

            $rows = $query->limit($size)->get();

            if ($rows === []) {
                return;
            }

            yield $rows;

            $last = self::keyOf($rows[count($rows) - 1], $column);
        } while (count($rows) === $size);
    }

    private function keysetQuery(string $method, string $column, string $direction): self
    {
        if ($this->orders !== [] || $this->limit !== null || $this->offset !== null) {
            throw DatabaseException::invalidQuery(sprintf('%s() orders by "%s" itself; remove orderBy(), limit() and offset() from the query.', $method, $column));
        }

        // The key condition ("id > last") is appended with "and". With a top-level "or",
        // "a or b and id > ?" would apply it to b only, so group the filter first.
        return $this->withGroupedWheres()->orderBy($column, $direction);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function keyOf(array $row, string $column): int|string
    {
        // "users.id" is returned as "id".
        $key = str_contains($column, '.') ? substr($column, (int) strrpos($column, '.') + 1) : $column;
        $value = $row[$key] ?? null;

        return is_int($value) || is_string($value)
            ? $value
            : throw DatabaseException::invalidQuery(sprintf('The key column "%s" must be selected and be an integer or string.', $column));
    }

    // Writing

    /**
     * Inserts one row (an associative array) or several rows with identical keys.
     *
     * Large batches are split into several statements so that no statement
     * exceeds the database's limit on bound values; the statements run in one
     * transaction, so either all rows are inserted or none.
     *
     * @param array<string, mixed>|list<array<string, mixed>> $rows
     *
     * @return int affected rows
     */
    public function insert(array $rows): int
    {
        return $this->writeInChunks(self::normalizeRows($rows), fn(array $chunk): array => $this->compiler()->insert($this, $chunk));
    }

    /**
     * Inserts one row and returns the generated id (an int when numeric).
     *
     * @param array<string, mixed> $row
     */
    public function insertGetId(array $row, ?string $sequence = null): int|string
    {
        $this->insert($row);
        $id = $this->connection->lastInsertId($sequence);

        return preg_match('/^\d+$/D', $id) === 1 && filter_var($id, FILTER_VALIDATE_INT) !== false ? (int) $id : $id;
    }

    /**
     * Inserts rows, or updates the given columns when a row with the same unique key exists.
     *
     * Large batches are split like insert(), in one transaction (DB-2). Give each unique
     * key once: within one statement PostgreSQL refuses a key twice, and across statements
     * the later row wins.
     *
     * @param array<string, mixed>|list<array<string, mixed>> $rows
     * @param list<string> $uniqueBy columns of the unique index (ignored by MySQL, which uses the table's unique indexes)
     * @param list<string>|null $update columns to update on conflict; null updates every inserted column except $uniqueBy
     */
    public function upsert(array $rows, array $uniqueBy, ?array $update = null): int
    {
        $rows = self::normalizeRows($rows);

        if ($uniqueBy === []) {
            throw DatabaseException::invalidQuery('upsert() needs at least one unique column.');
        }

        $update ??= array_values(array_diff(array_keys($rows[0]), $uniqueBy));

        if ($update === []) {
            throw DatabaseException::invalidQuery('upsert() needs at least one column to update.');
        }

        return $this->writeInChunks($rows, fn(array $chunk): array => $this->compiler()->upsert($this, $chunk, $uniqueBy, $update));
    }

    /**
     * Runs one statement per chunk of rows, sized so that no statement exceeds the
     * database's limit on bound values; several statements run in one transaction.
     *
     * @param non-empty-list<array<string, mixed>> $rows
     * @param Closure(non-empty-list<array<string, mixed>>): array{string, list<mixed>} $compile
     *
     * @return int affected rows
     */
    private function writeInChunks(array $rows, Closure $compile): int
    {
        $perStatement = max(1, intdiv($this->connection->dialect()->maxBindings(), count($rows[0])));

        if (count($rows) <= $perStatement) {
            [$sql, $bindings] = $compile($rows);

            return $this->connection->affectingStatement($sql, $bindings);
        }

        return $this->connection->transaction(function () use ($rows, $perStatement, $compile): int {
            $affected = 0;

            foreach (array_chunk($rows, $perStatement) as $chunk) {
                [$sql, $bindings] = $compile($chunk);
                $affected += $this->connection->affectingStatement($sql, $bindings);
            }

            return $affected;
        });
    }

    /**
     * Updates the rows matched by the where clauses. Throws when there is no where clause.
     *
     * @param array<string, mixed> $values
     *
     * @return int affected rows
     */
    public function update(array $values): int
    {
        $this->assertHasWhere('update');

        return $this->updateAll($values);
    }

    /**
     * Updates every row matched by the query, including all rows when there is no where clause.
     *
     * @param array<string, mixed> $values
     */
    public function updateAll(array $values): int
    {
        if ($values === []) {
            throw DatabaseException::invalidQuery('update() needs at least one column.');
        }

        foreach ($values as $value) {
            self::assertBindable($value, 'update');
        }

        [$sql, $bindings] = $this->compiler()->update($this, $values);

        return $this->connection->affectingStatement($sql, $bindings);
    }

    /**
     * Deletes the rows matched by the where clauses. Throws when there is no where clause.
     */
    public function delete(): int
    {
        $this->assertHasWhere('delete');

        return $this->deleteAll();
    }

    /**
     * Deletes every row matched by the query, including all rows when there is no where clause.
     */
    public function deleteAll(): int
    {
        [$sql, $bindings] = $this->compiler()->delete($this);

        return $this->connection->affectingStatement($sql, $bindings);
    }

    // Inspection

    /**
     * Returns the database's execution plan for this SELECT. The row format
     * depends on the database (see docs/database/diagnostics.md).
     *
     * @return list<array<string, mixed>>
     */
    public function explain(): array
    {
        [$sql, $bindings] = $this->compiler()->select($this);

        return $this->connection->select($this->connection->dialect()->compileExplain($sql), $bindings);
    }

    public function toSql(): string
    {
        return $this->compiler()->select($this)[0];
    }

    /**
     * @return list<mixed>
     */
    public function getBindings(): array
    {
        return $this->compiler()->select($this)[1];
    }

    /**
     * @internal read by Compiler
     *
     * @return array{table: string|Expression|null, columns: list<string|Expression>, distinct: bool, joins: list<array{type: string, table: string|Expression, first: string, operator: string, second: string}>, wheres: list<Where>, groups: list<string|Expression>, havings: list<Where>, orders: list<array{column: string|Expression, direction: string}>, limit: ?int, offset: ?int, lock: Lock|null}
     */
    public function parts(): array
    {
        return [
            'table' => $this->table,
            'columns' => $this->columns,
            'distinct' => $this->distinct,
            'joins' => $this->joins,
            'wheres' => $this->effectiveWheres(),
            'groups' => $this->groups,
            'havings' => $this->havings,
            'orders' => $this->orders,
            'limit' => $this->limit,
            'offset' => $this->offset,
            'lock' => $this->lock,
        ];
    }

    /**
     * @internal a copy without ordering, paging and row locks, used for count and exists
     * queries (PostgreSQL refuses FOR UPDATE with aggregates)
     */
    public function withoutPaging(): self
    {
        $clone = clone $this;
        $clone->orders = [];
        $clone->limit = null;
        $clone->offset = null;
        $clone->lock = null;

        return $clone;
    }

    /**
     * The where clauses as compiled: the query's own as one group, then each constraint
     * that has a condition. Without constraints they are returned unchanged.
     *
     * @return list<Where>
     */
    private function effectiveWheres(): array
    {
        $constraints = array_values(array_filter($this->constraints, static fn(Builder $group): bool => self::hasEffectiveCondition($group->wheres)));

        if ($constraints === []) {
            return $this->wheres;
        }

        $wheres = [];

        if ($this->wheres !== []) {
            $own = new self($this->connection);
            $own->wheres = $this->wheres;
            $wheres[] = ['type' => 'nested', 'boolean' => 'and', 'query' => $own];
        }

        foreach ($constraints as $group) {
            $wheres[] = ['type' => 'nested', 'boolean' => 'and', 'query' => $group];
        }

        return $wheres;
    }

    private function aggregate(string $function, string $column): mixed
    {
        [$sql, $bindings] = $this->compiler()->aggregate($this, $function, $column);
        $row = $this->connection->selectOne($sql, $bindings);

        return $row['aggregate'] ?? null;
    }

    private function compiler(): Compiler
    {
        return new Compiler($this->connection->dialect());
    }

    private function addJoin(string $type, string|Expression $table, string $first, string $operator, string $second): self
    {
        $this->joins[] = ['type' => $type, 'table' => $table, 'first' => $first, 'operator' => self::operator($operator), 'second' => $second];

        return $this;
    }

    private function addWhere(string $boolean, string|Expression|Closure $column, mixed $operator, mixed $value, int $argumentCount): self
    {
        if ($column instanceof Closure) {
            $nested = new self($this->connection);
            $column($nested);
            $this->wheres[] = ['type' => 'nested', 'boolean' => $boolean, 'query' => $nested];

            return $this;
        }

        if ($argumentCount === 2) {
            [$value, $operator] = [$operator, '='];
        }

        if (!is_string($operator)) {
            throw DatabaseException::invalidOperator(get_debug_type($operator));
        }

        if ($value === null) {
            throw DatabaseException::invalidQuery('Comparing with null never matches in SQL; use whereNull() or whereNotNull().');
        }

        self::assertBindable($value, 'where');

        $this->wheres[] = ['type' => 'basic', 'boolean' => $boolean, 'column' => $column, 'operator' => self::operator($operator), 'value' => $value];

        return $this;
    }

    /**
     * @param array<mixed> $values re-indexed, so string keys from callers are ignored
     */
    private function addIn(string $boolean, string|Expression $column, array $values, bool $not): self
    {
        foreach ($values as $value) {
            self::assertBindable($value, 'whereIn');
        }

        $this->wheres[] = ['type' => 'in', 'boolean' => $boolean, 'column' => $column, 'values' => array_values($values), 'not' => $not];

        return $this;
    }

    /**
     * @param array<mixed> $range
     */
    private function addBetween(string|Expression $column, array $range, bool $not): self
    {
        if (count($range) !== 2 || !array_is_list($range)) {
            throw DatabaseException::invalidQuery('whereBetween() needs a list of exactly two values.');
        }

        foreach ($range as $value) {
            if ($value === null) {
                throw DatabaseException::invalidQuery('whereBetween() bounds must not be null.');
            }
            self::assertBindable($value, 'whereBetween');
        }

        $this->wheres[] = ['type' => 'between', 'boolean' => 'and', 'column' => $column, 'values' => $range, 'not' => $not];

        return $this;
    }

    private function assertLockInTransaction(): void
    {
        if ($this->lock !== null && $this->connection->transactionLevel() === 0) {
            throw DatabaseException::invalidQuery('lockForUpdate() and sharedLock() only hold rows until the transaction ends, so they must run inside transaction(); outside one the lock would end with the statement.');
        }
    }

    private function assertHasWhere(string $operation): void
    {
        // Not just "was where() called": a closure group that stayed empty (a filter loop
        // with no filters) compiles to "1 = 1" and would change every row (DB-1).
        if (!self::hasEffectiveCondition($this->wheres)) {
            throw DatabaseException::invalidQuery(sprintf(
                'Refusing to %s without a where clause. Use %sAll() if every row is intended.',
                $operation,
                $operation,
            ));
        }
    }

    /**
     * Whether the where clauses contain at least one real condition. Nested groups count
     * only through their own conditions, the same way the compiler skips empty groups.
     * Every other clause counts, including explicit ones that match all rows, such as
     * whereNotIn() with an empty list.
     *
     * @param list<Where> $wheres
     */
    private static function hasEffectiveCondition(array $wheres): bool
    {
        foreach ($wheres as $where) {
            if ($where['type'] !== 'nested') {
                return true;
            }

            if (self::hasEffectiveCondition($where['query']->wheres)) {
                return true;
            }
        }

        return false;
    }

    private static function operator(string $operator): string
    {
        $normalized = strtolower(trim($operator));

        if (!in_array($normalized, self::OPERATORS, true)) {
            throw DatabaseException::invalidOperator($operator);
        }

        return $normalized;
    }

    private static function assertBindable(mixed $value, string $context): void
    {
        if (is_array($value)) {
            throw DatabaseException::invalidQuery(sprintf('An array cannot be used as a single value in %s(); use whereIn() for lists.', $context));
        }
    }

    /**
     * Validates rows at runtime: callers without static analysis may pass anything.
     *
     * @param array<mixed> $rows one associative row, or a list of rows
     *
     * @return non-empty-list<array<string, mixed>>
     */
    private static function normalizeRows(array $rows): array
    {
        $list = array_is_list($rows) ? $rows : [$rows];
        $validated = [];
        $keys = null;

        foreach ($list as $index => $row) {
            if (!is_array($row) || $row === []) {
                throw DatabaseException::invalidQuery(sprintf('Row %d to insert must be a non-empty associative array.', $index));
            }

            $clean = [];
            foreach ($row as $column => $value) {
                if (!is_string($column)) {
                    throw DatabaseException::invalidQuery(sprintf('Row %d to insert must use column names as keys.', $index));
                }

                self::assertBindable($value, 'insert');
                $clean[$column] = $value;
            }

            $rowKeys = array_keys($clean);
            sort($rowKeys);

            if ($keys !== null && $rowKeys !== $keys) {
                throw DatabaseException::invalidQuery('All rows in a bulk insert must have the same columns.');
            }

            $keys = $rowKeys;
            $validated[] = $clean;
        }

        if ($validated === []) {
            throw DatabaseException::invalidQuery('Nothing to insert.');
        }

        return $validated;
    }
}
