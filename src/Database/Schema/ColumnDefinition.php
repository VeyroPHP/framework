<?php

declare(strict_types=1);

namespace VeyroPHP\Database\Schema;

use VeyroPHP\Database\Exception\DatabaseException;
use VeyroPHP\Database\Query\Expression;

/**
 * One column in a Blueprint. Columns are NOT NULL unless nullable() is called.
 */
final class ColumnDefinition
{
    public bool $nullable = false;

    public bool $hasDefault = false;

    public mixed $default = null;

    public bool $unsigned = false;

    public bool $autoIncrement = false;

    public bool $primary = false;

    /** @var list<string>|null values an enum() column accepts */
    public ?array $allowed = null;

    /** Column to place this one after (MySQL/MariaDB, when adding columns) */
    public ?string $after = null;

    /**
     * @param array<string, int> $options e.g. ['length' => 255] or ['precision' => 10, 'scale' => 2]
     */
    public function __construct(
        private readonly Blueprint $blueprint,
        public readonly string $name,
        public readonly string $type,
        public readonly array $options = [],
    ) {}

    public function nullable(bool $nullable = true): self
    {
        $this->nullable = $nullable;

        return $this;
    }

    /**
     * A literal default (null, bool, int, float, string) or an Expression such as CURRENT_TIMESTAMP.
     */
    public function default(mixed $value): self
    {
        if (!($value === null || is_scalar($value) || $value instanceof Expression)) {
            throw DatabaseException::invalidQuery(sprintf('Default for column "%s" must be a scalar, null or an Expression.', $this->name));
        }

        $this->hasDefault = true;
        $this->default = $value;

        return $this;
    }

    /**
     * MySQL only; ignored by PostgreSQL and SQLite, which have no unsigned integers.
     */
    public function unsigned(): self
    {
        $this->unsigned = true;

        return $this;
    }

    /**
     * Places an added column after another one. MySQL and MariaDB only;
     * PostgreSQL and SQLite always add columns at the end, so it is ignored there.
     */
    public function after(string $column): self
    {
        $this->after = $column;

        return $this;
    }

    /**
     * Defaults to the current date and time when the row is inserted.
     */
    public function useCurrent(): self
    {
        return $this->default(new Expression('CURRENT_TIMESTAMP'));
    }

    /**
     * Makes this column the table's primary key (for tables without id()).
     */
    public function primary(?string $name = null): self
    {
        $this->blueprint->primary([$this->name], $name);

        return $this;
    }

    public function unique(?string $indexName = null): self
    {
        $this->blueprint->unique([$this->name], $indexName);

        return $this;
    }

    public function index(?string $indexName = null): self
    {
        $this->blueprint->index([$this->name], $indexName);

        return $this;
    }

    /**
     * Adds a foreign key from this column to $table.$column.
     */
    public function constrained(string $table, string $column = 'id'): ForeignKeyDefinition
    {
        return $this->blueprint->foreign($this->name)->references($column)->on($table);
    }
}
