<?php

declare(strict_types=1);

namespace VeyroPHP\Database\Dialect;

use VeyroPHP\Database\Schema\Grammar\MySqlSchemaGrammar;
use VeyroPHP\Database\Schema\Grammar\SchemaGrammar;

/**
 * MySQL and MariaDB.
 *
 * @internal Framework implementation detail, not covered by the backward-compatibility promise.
 */
final class MySqlDialect extends Dialect
{
    public function name(): string
    {
        return 'mysql';
    }

    public function schemaGrammar(): SchemaGrammar
    {
        return new MySqlSchemaGrammar($this);
    }

    protected function quoteSegment(string $segment): string
    {
        return '`' . str_replace('`', '``', $segment) . '`';
    }

    public function compileUpsertSuffix(array $uniqueBy, array $update): string
    {
        // MySQL uses the table's unique indexes; $uniqueBy is not part of the syntax.
        // VALUES() works on MySQL 5.7/8.x and MariaDB.
        return ' on duplicate key update ' . implode(', ', array_map(
            static fn(string $column): string => sprintf('%s = values(%s)', $column, $column),
            $update,
        ));
    }

    public function supportsTransactionalDdl(): bool
    {
        return false; // DDL commits implicitly
    }

    public function compileLock(string $mode, bool $skipLocked, bool $noWait): string
    {
        // MariaDB has no "for share"; "lock in share mode" works on MySQL and MariaDB.
        return $mode === 'share' ? ' lock in share mode' : parent::compileLock($mode, $skipLocked, $noWait);
    }

    public function compileJsonPath(string $quotedColumn, array $segments, bool $numeric): array
    {
        // Unquoted text; MySQL compares it numerically with a number on its own.
        return ['json_unquote(json_extract(' . $quotedColumn . ', ?))', self::jsonPathString($segments)];
    }

    public function compileFullText(array $quotedColumns): string
    {
        // Needs a FULLTEXT index on exactly these columns (Blueprint::fullText()).
        return 'match (' . implode(', ', $quotedColumns) . ') against (? in natural language mode)';
    }

    protected function limitForOffsetOnly(): string
    {
        return ' limit 18446744073709551615';
    }
}
