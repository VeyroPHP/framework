<?php

declare(strict_types=1);

namespace VeyroPHP\Database\Schema;

use Closure;
use VeyroPHP\Database\Connection\Connection;
use VeyroPHP\Database\Schema\Grammar\SchemaGrammar;

/**
 * Creates, changes and drops tables.
 *
 *     $schema->create('posts', function (Blueprint $table): void {
 *         $table->id();
 *         $table->ulid()->unique();
 *         $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
 *         $table->string('title');
 *         $table->timestamps();
 *     });
 *
 * Each statement runs separately. Use Migrator (or a transaction on databases
 * with transactional DDL) to make a group of changes atomic.
 */
final class Schema
{
    private readonly SchemaGrammar $grammar;

    public function __construct(private readonly Connection $connection)
    {
        $this->grammar = $connection->dialect()->schemaGrammar();
    }

    /**
     * @param Closure(Blueprint): mixed $definition the return value is ignored
     */
    public function create(string $table, Closure $definition): void
    {
        $this->run($this->createSql($table, $definition));
    }

    /**
     * @param Closure(Blueprint): mixed $definition the return value is ignored
     */
    public function table(string $table, Closure $definition): void
    {
        $this->run($this->alterSql($table, $definition));
    }

    public function drop(string $table): void
    {
        $this->connection->statement($this->grammar->compileDrop($table, false));
    }

    public function dropIfExists(string $table): void
    {
        $this->connection->statement($this->grammar->compileDrop($table, true));
    }

    public function rename(string $from, string $to): void
    {
        $this->connection->statement($this->grammar->compileRename($from, $to));
    }

    public function hasTable(string $table): bool
    {
        return $this->grammar->hasTable($this->connection, $table);
    }

    public function hasColumn(string $table, string $column): bool
    {
        return $this->grammar->hasColumn($this->connection, $table, $column);
    }

    /**
     * The statements create() would run, without running them.
     *
     * @param Closure(Blueprint): mixed $definition the return value is ignored
     *
     * @return list<string>
     */
    public function createSql(string $table, Closure $definition): array
    {
        $blueprint = new Blueprint($table, true);
        $definition($blueprint);

        return $this->grammar->compileCreate($blueprint);
    }

    /**
     * The statements table() would run, without running them.
     *
     * @param Closure(Blueprint): mixed $definition the return value is ignored
     *
     * @return list<string>
     */
    public function alterSql(string $table, Closure $definition): array
    {
        $blueprint = new Blueprint($table, false);
        $definition($blueprint);

        return $this->grammar->compileAlter($blueprint);
    }

    public function connection(): Connection
    {
        return $this->connection;
    }

    /**
     * @param list<string> $statements
     */
    private function run(array $statements): void
    {
        foreach ($statements as $statement) {
            $this->connection->statement($statement);
        }
    }
}
