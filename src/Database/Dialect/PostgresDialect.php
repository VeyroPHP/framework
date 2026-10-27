<?php

declare(strict_types=1);

namespace VeyroPHP\Database\Dialect;

use PDO;
use VeyroPHP\Database\Schema\Grammar\PostgresSchemaGrammar;
use VeyroPHP\Database\Schema\Grammar\SchemaGrammar;

/**
 * @internal Framework implementation detail, not covered by the backward-compatibility promise.
 */
final class PostgresDialect extends Dialect
{
    public function name(): string
    {
        return 'pgsql';
    }

    public function schemaGrammar(): SchemaGrammar
    {
        return new PostgresSchemaGrammar($this);
    }

    /**
     * PostgreSQL boolean columns do not accept integers.
     */
    public function bindBoolean(bool $value): array
    {
        return [$value, PDO::PARAM_BOOL];
    }

    protected function quoteSegment(string $segment): string
    {
        return '"' . str_replace('"', '""', $segment) . '"';
    }

    /**
     * The document a full-text index and a full-text query must share exactly, or the
     * index is not used: the "simple" configuration (no language-specific stemming, so
     * Hindi and English text are treated alike) over the columns joined with spaces.
     *
     * @param non-empty-list<string> $quotedColumns
     */
    public static function fullTextDocument(array $quotedColumns): string
    {
        return "to_tsvector('simple', " . implode(" || ' ' || ", array_map(static fn(string $c): string => 'coalesce(' . $c . ", '')", $quotedColumns)) . ')';
    }

    public function compileJsonPath(string $quotedColumn, array $segments, bool $numeric): array
    {
        // #>> reads text at a path given as text[]: {address,city} or {tags,1}.
        $sql = '(' . $quotedColumn . ' #>> ?::text[])' . ($numeric ? '::numeric' : '');

        return [$sql, '{' . implode(',', $segments) . '}'];
    }

    public function compileFullText(array $quotedColumns): string
    {
        return self::fullTextDocument($quotedColumns) . " @@ plainto_tsquery('simple', ?)";
    }

}
