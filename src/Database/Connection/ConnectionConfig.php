<?php

declare(strict_types=1);

namespace VeyroPHP\Database\Connection;

use SensitiveParameter;
use VeyroPHP\Database\Exception\DatabaseException;

/**
 * Validated settings for one database connection.
 *
 * The password is excluded from stack traces (SensitiveParameter) and from
 * var_dump()/print_r() output.
 */
final class ConnectionConfig
{
    public const DRIVERS = ['mysql', 'pgsql', 'sqlite'];

    /**
     * @param array<int, mixed> $options extra PDO attributes; ATTR_ERRMODE is always exception mode
     */
    public function __construct(
        public readonly string $name,
        public readonly string $driver,
        public readonly string $database,
        public readonly ?string $host = null,
        public readonly ?int $port = null,
        public readonly ?string $username = null,
        #[SensitiveParameter]
        private readonly ?string $password = null,
        public readonly string $charset = 'utf8mb4',
        public readonly bool $strict = true,
        public readonly array $options = [],
        public readonly int $timeout = 5,
        public readonly int $connectRetries = 2,
    ) {
        if ($timeout < 1 || $timeout > 600) {
            throw DatabaseException::invalidConfig($name, 'timeout', 'must be between 1 and 600 seconds');
        }

        if ($connectRetries < 0 || $connectRetries > 10) {
            throw DatabaseException::invalidConfig($name, 'connect_retries', 'must be between 0 and 10');
        }

        if (!in_array($driver, self::DRIVERS, true)) {
            throw DatabaseException::invalidConfig($name, 'driver', sprintf('must be one of %s', implode(', ', self::DRIVERS)));
        }

        if ($database === '') {
            throw DatabaseException::invalidConfig($name, 'database', 'must not be empty');
        }

        if ($driver !== 'sqlite' && ($host === null || $host === '')) {
            throw DatabaseException::invalidConfig($name, 'host', 'is required for ' . $driver);
        }

        if ($port !== null && ($port < 1 || $port > 65535)) {
            throw DatabaseException::invalidConfig($name, 'port', 'must be between 1 and 65535');
        }

        foreach (['database' => $database, 'host' => $host, 'charset' => $charset] as $key => $value) {
            if ($value !== null && (str_contains($value, ';') || str_contains($value, "\0"))) {
                throw DatabaseException::invalidConfig($name, $key, 'must not contain ";" or null bytes');
            }
        }
    }

    /**
     * @param array<array-key, mixed> $config keys: driver, database, host, port, username, password, charset, strict, options,
     *                                     timeout (connect timeout in seconds, default 5), connect_retries (default 2)
     */
    public static function fromArray(string $name, array $config): self
    {
        $string = static function (string $key, ?string $default = null) use ($name, $config): ?string {
            $value = $config[$key] ?? $default;

            if ($value !== null && !is_string($value)) {
                throw DatabaseException::invalidConfig($name, $key, 'must be a string');
            }

            return $value;
        };

        $port = $config['port'] ?? null;
        if (is_string($port) && preg_match('/^\d+$/D', $port) === 1) {
            $port = (int) $port;
        }
        if ($port !== null && !is_int($port)) {
            throw DatabaseException::invalidConfig($name, 'port', 'must be an integer');
        }

        $strict = $config['strict'] ?? true;
        if (!is_bool($strict)) {
            throw DatabaseException::invalidConfig($name, 'strict', 'must be a boolean');
        }

        $options = $config['options'] ?? [];
        if (!is_array($options) || array_filter(array_keys($options), 'is_string') !== []) {
            throw DatabaseException::invalidConfig($name, 'options', 'must be an array of PDO attributes keyed by PDO::ATTR_* constants');
        }

        $int = static function (string $key, int $default) use ($name, $config): int {
            $value = $config[$key] ?? $default;
            if (is_string($value) && preg_match('/^\d+$/D', $value) === 1) {
                $value = (int) $value;
            }

            return is_int($value) ? $value : throw DatabaseException::invalidConfig($name, $key, 'must be an integer');
        };

        /** @var array<int, mixed> $options */
        return new self(
            $name,
            $string('driver') ?? throw DatabaseException::invalidConfig($name, 'driver', 'is required'),
            $string('database') ?? throw DatabaseException::invalidConfig($name, 'database', 'is required'),
            $string('host'),
            $port,
            $string('username'),
            $string('password'),
            $string('charset', 'utf8mb4') ?? 'utf8mb4',
            $strict,
            $options,
            $int('timeout', 5),
            $int('connect_retries', 2),
        );
    }

    public function dsn(): string
    {
        return match ($this->driver) {
            'mysql' => sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $this->host, $this->port ?? 3306, $this->database, $this->charset),
            'pgsql' => sprintf("pgsql:host=%s;port=%d;dbname=%s;connect_timeout=%d;options='--client_encoding=UTF8'", $this->host, $this->port ?? 5432, $this->database, $this->timeout),
            default => 'sqlite:' . $this->database,
        };
    }

    /**
     * @internal used by ConnectionFactory only
     */
    public function password(): ?string
    {
        return $this->password;
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'name' => $this->name,
            'driver' => $this->driver,
            'database' => $this->database,
            'host' => $this->host,
            'port' => $this->port,
            'username' => $this->username,
            'password' => $this->password === null ? null : '[REDACTED]',
            'charset' => $this->charset,
            'strict' => $this->strict,
        ];
    }
}
