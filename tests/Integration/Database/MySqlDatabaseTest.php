<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Integration\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use VeyroPHP\Database\Connection\Connection;
use VeyroPHP\Database\Connection\ConnectionConfig;
use VeyroPHP\Database\DatabaseManager;
use VeyroPHP\Database\Dialect\MySqlDialect;

/**
 * Runs only when VEYROPHP_TEST_MYSQL_HOST is set. Use a dedicated, empty test
 * database: the tests create and drop the tables "users" and "posts".
 *
 * Variables: VEYROPHP_TEST_MYSQL_HOST, _PORT, _DATABASE, _USERNAME, _PASSWORD.
 */
#[CoversClass(MySqlDialect::class)]
final class MySqlDatabaseTest extends DatabaseTestCase
{
    protected function createConnection(): Connection
    {
        $host = getenv('VEYROPHP_TEST_MYSQL_HOST');

        if (!is_string($host) || $host === '') {
            self::markTestSkipped('Set VEYROPHP_TEST_MYSQL_HOST (and _DATABASE, _USERNAME, _PASSWORD) to run MySQL tests.');
        }

        if (!in_array('mysql', \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_mysql is not installed.');
        }

        $config = ConnectionConfig::fromArray('mysql', [
            'driver' => 'mysql',
            'host' => $host,
            'port' => getenv('VEYROPHP_TEST_MYSQL_PORT') ?: '3306',
            'database' => getenv('VEYROPHP_TEST_MYSQL_DATABASE') ?: 'veyrophp_test',
            'username' => getenv('VEYROPHP_TEST_MYSQL_USERNAME') ?: 'root',
            'password' => getenv('VEYROPHP_TEST_MYSQL_PASSWORD') ?: '',
        ]);

        return (new DatabaseManager(['mysql' => $config], 'mysql'))->connection();
    }

    protected function createSchema(): void
    {
        $this->db->statement('create table users (
            id bigint unsigned auto_increment primary key,
            public_id char(26) null,
            name varchar(255) not null,
            email varchar(255) not null unique,
            age int not null default 0,
            active tinyint(1) not null default 1,
            status varchar(20) null,
            priority int null,
            created_at datetime null
        ) engine=InnoDB default charset=utf8mb4');
        $this->db->statement('create table posts (
            id bigint unsigned auto_increment primary key,
            user_id bigint unsigned not null,
            title varchar(255) not null,
            views int not null default 0
        ) engine=InnoDB default charset=utf8mb4');
    }

    public function testStrictModeIsEnabled(): void
    {
        $mode = $this->db->selectOne('select @@SESSION.sql_mode as mode')['mode'] ?? null;

        self::assertIsString($mode);
        self::assertStringContainsString('STRICT_TRANS_TABLES', $mode);
    }

    public function testDdlImplicitCommitIsDetected(): void
    {
        $this->db->beginTransaction();
        $this->db->statement('create table implicit_commit_probe (id int)');

        try {
            $this->expectException(\VeyroPHP\Database\Exception\TransactionException::class);
            $this->expectExceptionMessage('ended by the database');
            $this->db->commit();
        } finally {
            $this->db->statement('drop table if exists implicit_commit_probe');
        }
    }
}
