<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Integration\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use VeyroPHP\Database\Connection\Connection;
use VeyroPHP\Database\Connection\ConnectionConfig;
use VeyroPHP\Database\DatabaseManager;
use VeyroPHP\Database\Dialect\PostgresDialect;

/**
 * Runs only when VEYROPHP_TEST_PGSQL_HOST is set and pdo_pgsql is installed.
 * Use a dedicated, empty test database.
 *
 * Variables: VEYROPHP_TEST_PGSQL_HOST, _PORT, _DATABASE, _USERNAME, _PASSWORD.
 */
#[CoversClass(PostgresDialect::class)]
final class PostgresDatabaseTest extends DatabaseTestCase
{
    protected function createConnection(): Connection
    {
        $host = getenv('VEYROPHP_TEST_PGSQL_HOST');

        if (!is_string($host) || $host === '') {
            self::markTestSkipped('Set VEYROPHP_TEST_PGSQL_HOST (and _DATABASE, _USERNAME, _PASSWORD) to run PostgreSQL tests.');
        }

        if (!in_array('pgsql', \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_pgsql is not installed.');
        }

        $config = ConnectionConfig::fromArray('pgsql', [
            'driver' => 'pgsql',
            'host' => $host,
            'port' => getenv('VEYROPHP_TEST_PGSQL_PORT') ?: '5432',
            'database' => getenv('VEYROPHP_TEST_PGSQL_DATABASE') ?: 'veyrophp_test',
            'username' => getenv('VEYROPHP_TEST_PGSQL_USERNAME') ?: 'postgres',
            'password' => getenv('VEYROPHP_TEST_PGSQL_PASSWORD') ?: '',
        ]);

        return (new DatabaseManager(['pgsql' => $config], 'pgsql'))->connection();
    }

    protected function createSchema(): void
    {
        $this->db->statement('create table users (
            id bigserial primary key,
            public_id char(26) null,
            name varchar(255) not null,
            email varchar(255) not null unique,
            age int not null default 0,
            active boolean not null default true,
            status varchar(20) null,
            priority int null,
            created_at timestamp null
        )');
        $this->db->statement('create table posts (
            id bigserial primary key,
            user_id bigint not null,
            title varchar(255) not null,
            views int not null default 0
        )');
    }

    // Transaction retry (batch B). PostgreSQL only: its REPEATABLE READ raises a serialization
    // failure (40001) without blocking, so one process can produce it. Deadlocks on MySQL need
    // two blocking processes; the retry logic itself is covered by TransactionRetryTest.

    public function testATransactionIsRetriedAfterARealSerializationFailure(): void
    {
        $this->users()->insert(['name' => 'Asha', 'email' => 'asha@example.test', 'age' => 1]);
        $other = $this->createConnection();
        $attempts = 0;

        try {
            $this->db->transaction(function (Connection $db) use (&$attempts, $other): void {
                $attempts++;
                $db->statement('set transaction isolation level repeatable read');
                $age = $db->table('users')->where('name', 'Asha')->value('age');

                if ($attempts === 1) {
                    // Another transaction changes the row after this one took its snapshot.
                    $other->table('users')->where('name', 'Asha')->update(['age' => 50]);
                }

                $db->table('users')->where('name', 'Asha')->update(['age' => (is_numeric($age) ? (int) $age : 0) + 1]);
            }, attempts: 3);
        } finally {
            $other->disconnect();
        }

        self::assertSame(2, $attempts, 'the first attempt failed with 40001 and was retried');
        self::assertEquals(51, $this->users()->where('name', 'Asha')->value('age'), 'the retry saw the other update');
        self::assertSame(0, $this->db->transactionLevel());
    }
}
