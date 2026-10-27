<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Integration\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use VeyroPHP\Database\Connection\Connection;
use VeyroPHP\Database\Connection\ConnectionConfig;
use VeyroPHP\Database\Connection\ConnectionFactory;
use VeyroPHP\Database\DatabaseManager;
use VeyroPHP\Database\Query\Builder;
use VeyroPHP\Database\Query\Compiler;
use VeyroPHP\Database\Query\Paginator;

/**
 * Runs the shared database tests against a SQLite file (not :memory:, so
 * disconnect and reconnect behave like a server database).
 */
#[CoversClass(Connection::class)]
#[CoversClass(ConnectionFactory::class)]
#[CoversClass(Builder::class)]
#[CoversClass(Compiler::class)]
#[CoversClass(Paginator::class)]
final class SqliteDatabaseTest extends DatabaseTestCase
{
    private string $file;

    protected function createConnection(): Connection
    {
        $this->file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'veyrophp-db-' . bin2hex(random_bytes(6)) . '.sqlite';

        return (new DatabaseManager(['sqlite' => new ConnectionConfig('sqlite', 'sqlite', $this->file)], 'sqlite'))->connection();
    }

    protected function createSchema(): void
    {
        $this->db->statement('create table users (
            id integer primary key autoincrement,
            public_id text null,
            name text not null,
            email text not null unique,
            age integer not null default 0,
            active integer not null default 1,
            status text null,
            priority integer null,
            created_at text null
        )');
        $this->db->statement('create table posts (
            id integer primary key autoincrement,
            user_id integer not null,
            title text not null,
            views integer not null default 0
        )');
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if (isset($this->file) && is_file($this->file)) {
            unlink($this->file);
        }
    }

    public function testForeignKeysAreEnabled(): void
    {
        self::assertEquals(1, $this->db->selectOne('pragma foreign_keys')['foreign_keys'] ?? 0);
    }
}
