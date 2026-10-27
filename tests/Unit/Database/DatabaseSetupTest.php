<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Unit\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VeyroPHP\Application\Application;
use VeyroPHP\Config\Config;
use VeyroPHP\Database\Connection\Connection;
use VeyroPHP\Database\Connection\ConnectionConfig;
use VeyroPHP\Database\Connection\ConnectionFactory;
use VeyroPHP\Database\DatabaseManager;
use VeyroPHP\Database\DatabaseServiceProvider;
use VeyroPHP\Database\Dialect\MySqlDialect;
use VeyroPHP\Database\Exception\ConnectionException;
use VeyroPHP\Database\Exception\DatabaseException;

#[CoversClass(ConnectionConfig::class)]
#[CoversClass(ConnectionFactory::class)]
#[CoversClass(DatabaseManager::class)]
#[CoversClass(DatabaseServiceProvider::class)]
#[CoversClass(ConnectionException::class)]
final class DatabaseSetupTest extends TestCase
{
    public function testBuildsDsnPerDriver(): void
    {
        self::assertSame(
            'mysql:host=127.0.0.1;port=3307;dbname=shop;charset=utf8mb4',
            (new ConnectionConfig('m', 'mysql', 'shop', '127.0.0.1', 3307))->dsn(),
        );
        self::assertSame(
            "pgsql:host=db;port=5432;dbname=shop;connect_timeout=5;options='--client_encoding=UTF8'",
            (new ConnectionConfig('p', 'pgsql', 'shop', 'db'))->dsn(),
        );
        self::assertSame('sqlite::memory:', (new ConnectionConfig('s', 'sqlite', ':memory:'))->dsn());
    }

    public function testPasswordIsHiddenFromDumps(): void
    {
        $config = new ConnectionConfig('m', 'mysql', 'shop', 'localhost', null, 'app', 'hunter2');

        self::assertStringNotContainsString('hunter2', print_r($config, true));
        self::assertSame('[REDACTED]', $config->__debugInfo()['password']);
        self::assertSame('hunter2', $config->password());
    }

    public function testFromArrayAcceptsStringPortAndOptions(): void
    {
        $config = ConnectionConfig::fromArray('m', [
            'driver' => 'mysql',
            'host' => 'localhost',
            'port' => '3306',
            'database' => 'shop',
            'options' => [\PDO::ATTR_TIMEOUT => 5],
        ]);

        self::assertSame(3306, $config->port);
        self::assertSame([\PDO::ATTR_TIMEOUT => 5], $config->options);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidConfigs(): iterable
    {
        yield 'unknown driver' => [['driver' => 'oracle', 'database' => 'x'], 'driver'];
        yield 'missing driver' => [['database' => 'x'], 'driver'];
        yield 'missing database' => [['driver' => 'sqlite'], 'database'];
        yield 'empty database' => [['driver' => 'sqlite', 'database' => ''], 'database'];
        yield 'mysql without host' => [['driver' => 'mysql', 'database' => 'x'], 'host'];
        yield 'port out of range' => [['driver' => 'mysql', 'host' => 'h', 'database' => 'x', 'port' => 70000], 'port'];
        yield 'port not numeric' => [['driver' => 'mysql', 'host' => 'h', 'database' => 'x', 'port' => 'abc'], 'port'];
        yield 'dsn injection in database' => [['driver' => 'mysql', 'host' => 'h', 'database' => 'x;host=evil'], 'database'];
        yield 'dsn injection in host' => [['driver' => 'mysql', 'host' => 'h;port=1', 'database' => 'x'], 'host'];
        yield 'strict not bool' => [['driver' => 'sqlite', 'database' => 'x', 'strict' => 'yes'], 'strict'];
        yield 'options with string keys' => [['driver' => 'sqlite', 'database' => 'x', 'options' => ['timeout' => 5]], 'options'];
        yield 'password not string' => [['driver' => 'sqlite', 'database' => 'x', 'password' => 123], 'password'];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('invalidConfigs')]
    public function testRejectsInvalidConfig(array $config, string $key): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage(sprintf('key "%s"', $key));

        ConnectionConfig::fromArray('test', $config);
    }

    public function testConnectionFailureMessageHasNoPassword(): void
    {
        $config = new ConnectionConfig('broken', 'sqlite', sys_get_temp_dir() . '/missing-dir-' . bin2hex(random_bytes(4)) . '/db.sqlite', null, null, null, 'hunter2');

        try {
            (new ConnectionFactory())->create($config);
            self::fail('Expected an exception.');
        } catch (DatabaseException $e) {
            self::assertStringContainsString('does not exist', $e->getMessage());
            self::assertStringNotContainsString('hunter2', $e->getMessage());
        }
    }

    public function testManagerCachesConnectionsAndOpensLazily(): void
    {
        $manager = DatabaseManager::fromConfig([
            'default' => 'main',
            'connections' => [
                'main' => ['driver' => 'sqlite', 'database' => ':memory:'],
                'other' => ['driver' => 'mysql', 'host' => 'db.invalid', 'database' => 'x'],
            ],
        ]);

        $main = $manager->connection();
        self::assertSame($main, $manager->connection('main'));
        self::assertFalse($main->isConnected());
        self::assertSame(['main', 'other'], $manager->connectionNames());
        self::assertSame('main', $manager->defaultConnectionName());

        // Creating the "other" connection does not contact db.invalid.
        self::assertInstanceOf(MySqlDialect::class, $manager->connection('other')->dialect());
        self::assertFalse($manager->connection('other')->isConnected());

        self::assertEquals(1, $main->selectOne('select 1 as one')['one'] ?? 0);
        self::assertTrue($main->isConnected());
    }

    public function testManagerRejectsUnknownConnections(): void
    {
        try {
            DatabaseManager::fromConfig(['default' => 'missing', 'connections' => ['a' => ['driver' => 'sqlite', 'database' => ':memory:']]]);
            self::fail('Expected unknown default.');
        } catch (DatabaseException) {
        }

        $manager = DatabaseManager::fromConfig(['default' => 'a', 'connections' => ['a' => ['driver' => 'sqlite', 'database' => ':memory:']]]);

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('"nope" is not configured');
        $manager->connection('nope');
    }

    public function testManagerRejectsInvalidStructure(): void
    {
        foreach ([[], ['default' => 'a'], ['default' => 'a', 'connections' => []], ['default' => '', 'connections' => ['a' => []]]] as $config) {
            try {
                DatabaseManager::fromConfig($config);
                self::fail('Expected DatabaseException.');
            } catch (DatabaseException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testServiceProviderResolvesRelativeSqlitePathInBasePath(): void
    {
        $root = (string) realpath(sys_get_temp_dir()) . DIRECTORY_SEPARATOR . 'veyrophp-dbprov-' . bin2hex(random_bytes(6));
        mkdir($root . DIRECTORY_SEPARATOR . 'database', 0777, true);

        try {
            $app = new Application($root);
            $app->container()->instance(Config::class, new Config(['database' => [
                'default' => 'sqlite',
                'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => 'database/app.sqlite']],
            ]]));
            $app->register(new DatabaseServiceProvider());

            $connection = $app->container()->make(Connection::class);
            $connection->statement('create table t (id integer)');
            $connection->disconnect();

            self::assertFileExists($root . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'app.sqlite');
            self::assertSame($app->container()->get(DatabaseManager::class), $app->container()->get(DatabaseManager::class));
        } finally {
            @unlink($root . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'app.sqlite');
            rmdir($root . DIRECTORY_SEPARATOR . 'database');
            rmdir($root);
        }
    }
}
