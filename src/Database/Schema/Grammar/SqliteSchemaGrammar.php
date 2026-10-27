<?php

declare(strict_types=1);

namespace VeyroPHP\Database\Schema\Grammar;

use VeyroPHP\Database\Connection\Connection;
use VeyroPHP\Database\Exception\DatabaseException;
use VeyroPHP\Database\Schema\Blueprint;
use VeyroPHP\Database\Schema\ColumnDefinition;
use VeyroPHP\Database\Schema\ForeignKeyDefinition;

/**
 * SQLite 3.35+ (DROP COLUMN). SQLite cannot add a foreign key to an existing
 * column; a foreign key on a newly added column is written inline instead.
 *
 * @internal Framework implementation detail, not covered by the backward-compatibility promise.
 */
final class SqliteSchemaGrammar extends SchemaGrammar
{
    public function hasTable(Connection $connection, string $table): bool
    {
        return self::countIsPositive($connection, "select count(*) from sqlite_master where type = 'table' and name = ?", [$table]);
    }

    public function hasColumn(Connection $connection, string $table, string $column): bool
    {
        foreach ($connection->select('pragma table_info(' . $this->quote($table) . ')') as $row) {
            if (($row['name'] ?? null) === $column) {
                return true;
            }
        }

        return false;
    }

    protected function idColumnSql(ColumnDefinition $column): string
    {
        return 'integer primary key autoincrement not null';
    }

    protected function typeSql(ColumnDefinition $column): string
    {
        return match ($column->type) {
            'char', 'string', 'enum' => sprintf('varchar(%d)', $column->options['length'] ?? 255),
            'text', 'mediumText', 'longText', 'json' => 'text',
            'tinyInteger', 'smallInteger', 'integer', 'bigInteger', 'boolean' => 'integer',
            'decimal' => 'numeric',
            'float', 'double' => 'real',
            'date' => 'date',
            'dateTime', 'timestamp' => 'datetime',
            default => throw new \LogicException('Unknown column type ' . $column->type),
        };
    }

    protected function compileAddPrimary(Blueprint $blueprint, string $name, array $columns): string
    {
        throw DatabaseException::invalidQuery(sprintf('SQLite cannot add a primary key to the existing table "%s"; set it with primary() when creating the table.', $blueprint->table));
    }

    protected function compileDropIndex(string $table, string $index): string
    {
        return 'drop index ' . $this->quote($index);
    }

    protected function addedColumnSql(Blueprint $blueprint, ColumnDefinition $column): string
    {
        $sql = $this->columnSql($column);

        foreach ($blueprint->foreignKeys() as $foreign) {
            if ($foreign->column === $column->name) {
                $sql .= sprintf(
                    ' references %s (%s) on delete %s on update %s',
                    $this->quote($foreign->referencedTable),
                    $this->quote($foreign->referencedColumn),
                    $foreign->onDelete,
                    $foreign->onUpdate,
                );
            }
        }

        return $sql;
    }

    protected function compileAddForeignKey(Blueprint $blueprint, ForeignKeyDefinition $foreign): ?string
    {
        foreach ($blueprint->columns() as $column) {
            if ($column->name === $foreign->column) {
                return null; // written inline by addedColumnSql()
            }
        }

        throw DatabaseException::invalidQuery(sprintf(
            'SQLite cannot add a foreign key to the existing column "%s.%s"; add the column and its foreign key together, or recreate the table.',
            $blueprint->table,
            $foreign->column,
        ));
    }
}
