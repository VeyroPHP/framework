<?php

declare(strict_types=1);

namespace VeyroPHP\Database\Schema\Grammar;

use VeyroPHP\Database\Connection\Connection;
use VeyroPHP\Database\Schema\Blueprint;
use VeyroPHP\Database\Schema\ColumnDefinition;

/**
 * MySQL 8 and MariaDB. Tables use InnoDB and utf8mb4.
 *
 * renameColumn() needs MySQL 8.0+ or MariaDB 10.5+.
 *
 * @internal Framework implementation detail, not covered by the backward-compatibility promise.
 */
final class MySqlSchemaGrammar extends SchemaGrammar
{
    public function compileRename(string $from, string $to): string
    {
        return sprintf('rename table %s to %s', $this->quote($from), $this->quote($to));
    }

    public function hasTable(Connection $connection, string $table): bool
    {
        return self::countIsPositive($connection, 'select count(*) from information_schema.tables where table_schema = database() and table_name = ?', [$table]);
    }

    public function hasColumn(Connection $connection, string $table, string $column): bool
    {
        return self::countIsPositive(
            $connection,
            'select count(*) from information_schema.columns where table_schema = database() and table_name = ? and column_name = ?',
            [$table, $column],
        );
    }

    protected function createTableSuffix(): string
    {
        return ' engine=InnoDB default charset=utf8mb4 collate=utf8mb4_unicode_ci';
    }

    protected function supportsUnsigned(): bool
    {
        return true;
    }

    protected function idColumnSql(ColumnDefinition $column): string
    {
        return 'bigint unsigned not null auto_increment primary key';
    }

    protected function typeSql(ColumnDefinition $column): string
    {
        return match ($column->type) {
            'char' => sprintf('char(%d)', $column->options['length'] ?? 1),
            'string', 'enum' => sprintf('varchar(%d)', $column->options['length'] ?? 255),
            'text' => 'text',
            'mediumText' => 'mediumtext',
            'longText' => 'longtext',
            'tinyInteger' => 'tinyint',
            'smallInteger' => 'smallint',
            'integer' => 'int',
            'bigInteger' => 'bigint',
            'boolean' => 'tinyint(1)',
            'decimal' => sprintf('decimal(%d, %d)', $column->options['precision'] ?? 10, $column->options['scale'] ?? 2),
            'float' => 'float',
            'double' => 'double',
            'date' => 'date',
            'dateTime', 'timestamp' => 'datetime',
            'json' => 'json',
            default => throw new \LogicException('Unknown column type ' . $column->type),
        };
    }

    protected function addedColumnSql(Blueprint $blueprint, ColumnDefinition $column): string
    {
        $sql = $this->columnSql($column);

        return $column->after === null ? $sql : $sql . ' after ' . $this->quote($column->after);
    }

    protected function compileDropIndex(string $table, string $index): string
    {
        return sprintf('drop index %s on %s', $this->quote($index), $this->quote($table));
    }

    protected function compileFullTextIndex(string $table, string $name, array $columns): string
    {
        return sprintf('create fulltext index %s on %s (%s)', $this->quote($name), $this->quote($table), implode(', ', array_map($this->quote(...), $columns)));
    }

    protected function escapeString(string $value): string
    {
        // MySQL treats backslash as an escape character in string literals by default.
        return str_replace('\\', '\\\\', parent::escapeString($value));
    }
}
