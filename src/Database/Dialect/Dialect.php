<?php

declare(strict_types=1);

namespace VeyroPHP\Database\Dialect;

use PDO;
use VeyroPHP\Database\Exception\DatabaseException;
use VeyroPHP\Database\Query\Expression;
use VeyroPHP\Database\Schema\Grammar\SchemaGrammar;

/**
 * SQL differences between database systems.
 *
 * @internal Framework implementation detail, not covered by the backward-compatibility promise.
 */
abstract class Dialect
{
    private const SEGMENT = '/^[A-Za-z_][A-Za-z0-9_]*$/D';

    /**
     * Most bound values one statement may have. MySQL and PostgreSQL allow 65,535
     * placeholders per prepared statement; bulk inserts are split to stay below it.
     */
    public function maxBindings(): int
    {
        return 65535;
    }

    abstract public function name(): string;

    /**
     * The DDL grammar for this database.
     */
    abstract public function schemaGrammar(): SchemaGrammar;

    /**
     * How a boolean is bound: as 1/0 by default, as a real boolean where the column type needs it.
     *
     * @return array{bool|int, int} value and PDO::PARAM_* type
     */
    public function bindBoolean(bool $value): array
    {
        return [(int) $value, PDO::PARAM_INT];
    }

    /**
     * Quotes one already-validated identifier segment.
     */
    abstract protected function quoteSegment(string $segment): string;

    /**
     * Quotes an identifier such as "users", "users.email", "users.*", "*" or "email as address".
     *
     * Only letters, digits and "_" are accepted in each segment, so identifiers
     * cannot carry SQL. Use an Expression for anything else.
     *
     * @throws DatabaseException when the identifier is not valid
     */
    public function quoteIdentifier(string|Expression $identifier): string
    {
        if ($identifier instanceof Expression) {
            return $identifier->sql;
        }

        if (preg_match('/^(.+?)\s+as\s+(.+)$/iD', trim($identifier), $match) === 1) {
            return $this->quoteIdentifier($match[1]) . ' as ' . $this->quoteName($match[2], $identifier);
        }

        $segments = explode('.', $identifier);
        $quoted = [];

        foreach ($segments as $index => $segment) {
            $quoted[] = $segment === '*' && $index === count($segments) - 1
                ? '*'
                : $this->quoteName($segment, $identifier);
        }

        if (count($quoted) > 3) {
            throw DatabaseException::invalidIdentifier($identifier);
        }

        return implode('.', $quoted);
    }

    /**
     * LIMIT/OFFSET clause, with a leading space, or an empty string.
     */
    public function compileLimit(?int $limit, ?int $offset): string
    {
        $sql = '';

        if ($limit !== null) {
            $sql .= ' limit ' . $limit;
        }

        if ($offset !== null && $offset > 0) {
            $sql .= ($limit === null ? $this->limitForOffsetOnly() : '') . ' offset ' . $offset;
        }

        return $sql;
    }

    /**
     * Upsert for a multi-row insert whose VALUES part is already compiled. The standard
     * "on conflict ... do update" form (PostgreSQL, SQLite); MySQL overrides it.
     *
     * @param list<string> $uniqueBy quoted column names that identify a row
     * @param list<string> $update quoted column names to overwrite on conflict
     */
    public function compileUpsertSuffix(array $uniqueBy, array $update): string
    {
        return sprintf(
            ' on conflict (%s) do update set %s',
            implode(', ', $uniqueBy),
            implode(', ', array_map(static fn(string $column): string => sprintf('%s = excluded.%s', $column, $column), $update)),
        );
    }

    /**
     * True when CREATE/ALTER/DROP statements can be rolled back inside a transaction.
     */
    public function supportsTransactionalDdl(): bool
    {
        return true;
    }

    /**
     * Wraps a SELECT so the database returns its execution plan instead of rows.
     */
    public function compileExplain(string $sql): string
    {
        return 'explain ' . $sql;
    }

    public function compileSavepoint(string $name): string
    {
        return 'savepoint ' . $this->quoteSegment($name);
    }

    public function compileReleaseSavepoint(string $name): string
    {
        return 'release savepoint ' . $this->quoteSegment($name);
    }

    public function compileRollbackToSavepoint(string $name): string
    {
        return 'rollback to savepoint ' . $this->quoteSegment($name);
    }

    /**
     * Row-lock clause for a SELECT, with a leading space.
     *
     * @param 'update'|'share' $mode
     */
    public function compileLock(string $mode, bool $skipLocked, bool $noWait): string
    {
        return ($mode === 'share' ? ' for share' : ' for update') . ($skipLocked ? ' skip locked' : '') . ($noWait ? ' nowait' : '');
    }

    /**
     * SQL that reads one value at a JSON path, and the path to bind for its placeholder.
     *
     * @param list<string|int> $segments validated keys (string) and array indexes (int)
     *
     * @return array{string, string}
     */
    public function compileJsonPath(string $quotedColumn, array $segments, bool $numeric): array
    {
        return ['json_extract(' . $quotedColumn . ', ?)', self::jsonPathString($segments)];
    }

    /**
     * A full-text match of $quotedColumns against one bound search text.
     *
     * @param non-empty-list<string> $quotedColumns
     *
     * @throws DatabaseException when the database has no portable full-text search
     */
    public function compileFullText(array $quotedColumns): string
    {
        throw DatabaseException::invalidQuery(sprintf('Full-text search is not supported on %s. It is available on MySQL/MariaDB (FULLTEXT index) and PostgreSQL; SQLite needs an FTS5 virtual table, which VeyroPHP does not create.', $this->displayName()));
    }

    /**
     * "$.address.city", "$.tags[1]"
     *
     * @param list<string|int> $segments
     */
    protected static function jsonPathString(array $segments): string
    {
        $path = '$';

        foreach ($segments as $segment) {
            $path .= is_int($segment) ? '[' . $segment . ']' : '.' . $segment;
        }

        return $path;
    }

    public function displayName(): string
    {
        return match ($this->name()) {
            'mysql' => 'MySQL',
            'pgsql' => 'PostgreSQL',
            'sqlite' => 'SQLite',
            default => $this->name(),
        };
    }

    /**
     * Some databases need a LIMIT whenever OFFSET is used.
     */
    protected function limitForOffsetOnly(): string
    {
        return '';
    }

    private function quoteName(string $segment, string $full): string
    {
        if (preg_match(self::SEGMENT, $segment) !== 1) {
            throw DatabaseException::invalidIdentifier($full);
        }

        return $this->quoteSegment($segment);
    }
}
