<?php

declare(strict_types=1);

namespace VeyroPHP\Database\Migration;

use Throwable;
use VeyroPHP\Database\Connection\Connection;
use VeyroPHP\Database\Schema\Blueprint;
use VeyroPHP\Database\Schema\Schema;

/**
 * Runs and rolls back migration files, recording them in a "migrations" table.
 *
 * - Files run in file-name order; each run() call is one batch.
 * - rollback() undoes the latest batch(es) in reverse order.
 * - On PostgreSQL and SQLite each migration and its record run in one
 *   transaction, so a failing migration leaves nothing behind. MySQL commits
 *   DDL implicitly; a failing migration may leave earlier statements applied,
 *   and the MigrationException says so.
 * - Concurrent runs are not coordinated; run migrations from one process.
 */
final class Migrator
{
    private const FILE_PATTERN = '/^\d{4}_\d{2}_\d{2}_\d{6}_[a-z0-9_]+$/D';

    private readonly Schema $schema;

    public function __construct(
        private readonly Connection $connection,
        private readonly string $directory,
        private readonly string $table = 'migrations',
    ) {
        $this->schema = new Schema($connection);
    }

    /**
     * Runs all pending migrations as a new batch.
     *
     * @return list<string> names of the migrations that ran
     */
    public function run(): array
    {
        $this->ensureTable();
        $ran = $this->ranMigrations();
        $pending = array_diff_key($this->files(), $ran);

        if ($pending === []) {
            return [];
        }

        $batch = ($ran === [] ? 0 : max($ran)) + 1;
        $done = [];

        foreach ($pending as $name => $path) {
            $migration = $this->load($name, $path);

            $this->execute($name, 'up', function () use ($migration, $name, $batch): void {
                $migration->up($this->schema);
                $this->connection->table($this->table)->insert(['migration' => $name, 'batch' => $batch]);
            });

            $done[] = $name;
        }

        return $done;
    }

    /**
     * Rolls back the latest $batches batches.
     *
     * @return list<string> names of the migrations that were rolled back
     */
    public function rollback(int $batches = 1): array
    {
        $this->ensureTable();
        $ran = $this->ranMigrations();

        if ($ran === [] || $batches < 1) {
            return [];
        }

        $batchNumbers = array_values(array_unique($ran));
        rsort($batchNumbers);
        $targets = array_slice($batchNumbers, 0, $batches);

        $toRollBack = array_keys(array_filter($ran, static fn(int $batch): bool => in_array($batch, $targets, true)));
        rsort($toRollBack, SORT_STRING);

        $files = $this->files();
        $done = [];

        foreach ($toRollBack as $name) {
            $path = $files[$name] ?? throw MigrationException::missingFile($name);
            $migration = $this->load($name, $path);

            $this->execute($name, 'down', function () use ($migration, $name): void {
                $migration->down($this->schema);
                $this->connection->table($this->table)->where('migration', $name)->delete();
            });

            $done[] = $name;
        }

        return $done;
    }

    /**
     * @return list<array{migration: string, ran: bool, batch: int|null}>
     */
    public function status(): array
    {
        $this->ensureTable();
        $ran = $this->ranMigrations();
        $status = [];

        foreach (array_keys($this->files()) as $name) {
            $status[] = ['migration' => $name, 'ran' => isset($ran[$name]), 'batch' => $ran[$name] ?? null];
        }

        return $status;
    }

    /**
     * @return list<string>
     */
    public function pending(): array
    {
        $this->ensureTable();

        return array_keys(array_diff_key($this->files(), $this->ranMigrations()));
    }

    /**
     * Migration files sorted by name.
     *
     * @return array<string, string> name => path
     */
    public function files(): array
    {
        if (!is_dir($this->directory)) {
            throw MigrationException::directoryNotFound($this->directory);
        }

        $files = [];

        foreach (glob($this->directory . DIRECTORY_SEPARATOR . '*.php') ?: [] as $path) {
            $name = basename($path, '.php');

            if (preg_match(self::FILE_PATTERN, $name) !== 1) {
                throw MigrationException::invalidFileName(basename($path));
            }

            $files[$name] = $path;
        }

        ksort($files, SORT_STRING);

        return $files;
    }

    private function ensureTable(): void
    {
        if ($this->schema->hasTable($this->table)) {
            return;
        }

        $this->schema->create($this->table, static function (Blueprint $table): void {
            $table->id();
            $table->string('migration')->unique();
            $table->integer('batch');
        });
    }

    /**
     * @return array<string, int> migration => batch
     */
    private function ranMigrations(): array
    {
        $ran = [];

        foreach ($this->connection->table($this->table)->select('migration', 'batch')->orderBy('migration')->get() as $row) {
            if (is_string($row['migration'] ?? null) && is_numeric($row['batch'] ?? null)) {
                $ran[$row['migration']] = (int) $row['batch'];
            }
        }

        return $ran;
    }

    private function load(string $name, string $path): Migration
    {
        $migration = (static fn(string $__path): mixed => require $__path)($path);

        if (!$migration instanceof Migration) {
            throw MigrationException::notAMigration($name, get_debug_type($migration));
        }

        return $migration;
    }

    /**
     * @param \Closure(): void $work
     */
    private function execute(string $name, string $direction, \Closure $work): void
    {
        $transactional = $this->connection->dialect()->supportsTransactionalDdl();

        try {
            $transactional ? $this->connection->transaction(static fn() => $work()) : $work();
        } catch (Throwable $e) {
            throw MigrationException::failed($name, $direction, $transactional, $e);
        }
    }
}
