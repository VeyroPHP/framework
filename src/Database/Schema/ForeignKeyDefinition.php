<?php

declare(strict_types=1);

namespace VeyroPHP\Database\Schema;

use VeyroPHP\Database\Exception\DatabaseException;

/**
 * A foreign key constraint. Default action on delete and update: restrict.
 */
final class ForeignKeyDefinition
{
    public const ACTIONS = ['cascade', 'restrict', 'set null', 'no action'];

    public string $referencedTable = '';

    public string $referencedColumn = 'id';

    public string $onDelete = 'restrict';

    public string $onUpdate = 'restrict';

    public function __construct(
        public readonly string $column,
        public string $name,
    ) {}

    public function references(string $column): self
    {
        $this->referencedColumn = $column;

        return $this;
    }

    public function on(string $table): self
    {
        $this->referencedTable = $table;

        return $this;
    }

    public function onDelete(string $action): self
    {
        $this->onDelete = self::action($action);

        return $this;
    }

    public function onUpdate(string $action): self
    {
        $this->onUpdate = self::action($action);

        return $this;
    }

    public function cascadeOnDelete(): self
    {
        return $this->onDelete('cascade');
    }

    public function nullOnDelete(): self
    {
        return $this->onDelete('set null');
    }

    private static function action(string $action): string
    {
        $normalized = strtolower(trim($action));

        if (!in_array($normalized, self::ACTIONS, true)) {
            throw DatabaseException::invalidQuery(sprintf('Foreign key action must be one of %s, "%s" given.', implode(', ', self::ACTIONS), $action));
        }

        return $normalized;
    }
}
