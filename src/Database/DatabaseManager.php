<?php

declare(strict_types=1);

namespace VeyroPHP\Database;

use Closure;
use VeyroPHP\Database\Connection\Connection;
use VeyroPHP\Database\Connection\ConnectionConfig;
use VeyroPHP\Database\Connection\ConnectionFactory;
use VeyroPHP\Database\Dialect\Dialect;
use VeyroPHP\Database\Dialect\MySqlDialect;
use VeyroPHP\Database\Dialect\PostgresDialect;
use VeyroPHP\Database\Dialect\SqliteDialect;
use VeyroPHP\Database\Exception\DatabaseException;
use VeyroPHP\Database\Profiling\QueryExecuted;

/**
 * Creates and caches named connections. Connections open lazily on first query.
 */
final class DatabaseManager
{
    /** @var array<string, Connection> */
    private array $connections = [];

    /** @var list<Closure(QueryExecuted): void> */
    private array $listeners = [];

    /**
     * @param array<string, ConnectionConfig> $configs
     */
    public function __construct(
        private readonly array $configs,
        private readonly string $default,
        private readonly ConnectionFactory $factory = new ConnectionFactory(),
    ) {
        if (!isset($configs[$default])) {
            throw DatabaseException::unknownConnection($default);
        }
    }

    /**
     * Builds a manager from the "database" config section:
     *
     *     ['default' => 'mysql', 'connections' => ['mysql' => [...], 'sqlite' => [...]]]
     *
     * @param array<array-key, mixed> $config
     */
    public static function fromConfig(array $config, ConnectionFactory $factory = new ConnectionFactory()): self
    {
        $default = $config['default'] ?? null;
        $connections = $config['connections'] ?? null;

        if (!is_string($default) || $default === '') {
            throw DatabaseException::invalidConfig('*', 'database.default', 'must be a connection name');
        }

        if (!is_array($connections) || $connections === []) {
            throw DatabaseException::invalidConfig('*', 'database.connections', 'must define at least one connection');
        }

        $configs = [];

        foreach ($connections as $name => $settings) {
            if (!is_string($name) || !is_array($settings)) {
                throw DatabaseException::invalidConfig((string) $name, 'database.connections', 'each connection must be an array keyed by its name');
            }

            $configs[$name] = ConnectionConfig::fromArray($name, $settings);
        }

        return new self($configs, $default, $factory);
    }

    public function connection(?string $name = null): Connection
    {
        $name ??= $this->default;

        if (isset($this->connections[$name])) {
            return $this->connections[$name];
        }

        $config = $this->configs[$name] ?? throw DatabaseException::unknownConnection($name);
        $factory = $this->factory;

        $connection = new Connection(
            $name,
            self::dialectFor($config->driver),
            static fn(): \PDO => $factory->create($config),
        );

        foreach ($this->listeners as $listener) {
            $connection->listen($listener);
        }

        return $this->connections[$name] = $connection;
    }

    /**
     * Adds a query listener to every connection, including ones created later.
     *
     * @param Closure(QueryExecuted): void $listener
     */
    public function listen(Closure $listener): void
    {
        $this->listeners[] = $listener;

        foreach ($this->connections as $connection) {
            $connection->listen($listener);
        }
    }

    public function defaultConnectionName(): string
    {
        return $this->default;
    }

    /**
     * @return list<string>
     */
    public function connectionNames(): array
    {
        return array_keys($this->configs);
    }

    public function disconnect(?string $name = null): void
    {
        $this->connection($name)->disconnect();
    }

    public static function dialectFor(string $driver): Dialect
    {
        return match ($driver) {
            'mysql' => new MySqlDialect(),
            'pgsql' => new PostgresDialect(),
            'sqlite' => new SqliteDialect(),
            default => throw DatabaseException::invalidConfig('*', 'driver', sprintf('unsupported driver "%s"', $driver)),
        };
    }
}
