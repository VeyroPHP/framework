<?php

declare(strict_types=1);

namespace VeyroPHP\Database\Schema\Grammar;

use VeyroPHP\Database\Connection\Connection;
use VeyroPHP\Database\Dialect\PostgresDialect;
use VeyroPHP\Database\Exception\DatabaseException;
use VeyroPHP\Database\Schema\ColumnDefinition;

/**
 * @internal Framework implementation detail, not covered by the backward-compatibility promise.
 */
final class PostgresSchemaGrammar extends SchemaGrammar
{
    public function hasTable(Connection $connection, string $table): bool
    {
        return self::countIsPositive($connection, 'select count(*) from information_schema.tables where table_schema = current_schema() and table_name = ?', [$table]);
    }

    public function hasColumn(Connection $connection, string $table, string $column): bool
    {
        return self::countIsPositive(
            $connection,
            'select count(*) from information_schema.columns where table_schema = current_schema() and table_name = ? and column_name = ?',
            [$table, $column],
        );
    }

    protected function idColumnSql(ColumnDefinition $column): string
    {
        return 'bigserial primary key';
    }

    protected function typeSql(ColumnDefinition $column): string
    {
        return match ($column->type) {
            'char' => sprintf('char(%d)', $column->options['length'] ?? 1),
            'string', 'enum' => sprintf('varchar(%d)', $column->options['length'] ?? 255),
            'text', 'mediumText', 'longText' => 'text',
            'tinyInteger', 'smallInteger' => 'smallint',
            'integer' => 'integer',
            'bigInteger' => 'bigint',
            'boolean' => 'boolean',
            'decimal' => sprintf('decimal(%d, %d)', $column->options['precision'] ?? 10, $column->options['scale'] ?? 2),
            'float' => 'real',
            'double' => 'double precision',
            'date' => 'date',
            'dateTime', 'timestamp' => 'timestamp(0) without time zone',
            'json' => 'jsonb',
            default => throw new \LogicException('Unknown column type ' . $column->type),
        };
    }

    protected function compileDropIndex(string $table, string $index): string
    {
        return 'drop index ' . $this->quote($index);
    }

    protected function compileFullTextIndex(string $table, string $name, array $columns): string
    {
        if ($columns === []) {
            throw DatabaseException::invalidQuery(sprintf('Full-text index "%s" needs at least one column.', $name));
        }

        // The same document as PostgresDialect::compileFullText(), or the index is not used.
        return sprintf('create index %s on %s using gin (%s)', $this->quote($name), $this->quote($table), PostgresDialect::fullTextDocument(array_map($this->quote(...), $columns)));
    }

    protected function literal(mixed $value): string
    {
        return is_bool($value) ? ($value ? 'true' : 'false') : parent::literal($value);
    }
}
