<?php

declare(strict_types=1);

namespace VeyroPHP\Database\Dialect;

use VeyroPHP\Database\Schema\Grammar\SchemaGrammar;
use VeyroPHP\Database\Schema\Grammar\SqliteSchemaGrammar;

/**
 * @internal Framework implementation detail, not covered by the backward-compatibility promise.
 */
final class SqliteDialect extends Dialect
{
    /**
     * SQLite builds before 3.32 allow only 999 variables, and Linux PHP often uses
     * the system's SQLite, so the safe limit is used everywhere.
     */
    public function maxBindings(): int
    {
        return 999;
    }

    public function name(): string
    {
        return 'sqlite';
    }

    public function schemaGrammar(): SchemaGrammar
    {
        return new SqliteSchemaGrammar($this);
    }

    protected function quoteSegment(string $segment): string
    {
        return '"' . str_replace('"', '""', $segment) . '"';
    }

    /**
     * SQLite has no row locks: a writing transaction locks the whole database file.
     */
    public function compileLock(string $mode, bool $skipLocked, bool $noWait): string
    {
        return '';
    }

    public function compileExplain(string $sql): string
    {
        return 'explain query plan ' . $sql;
    }

    protected function limitForOffsetOnly(): string
    {
        return ' limit -1';
    }
}
