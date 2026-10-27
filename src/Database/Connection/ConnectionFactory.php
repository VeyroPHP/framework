<?php

declare(strict_types=1);

namespace VeyroPHP\Database\Connection;

use Closure;
use PDO;
use PDOException;
use VeyroPHP\Database\Exception\ConnectionException;
use VeyroPHP\Database\Exception\DatabaseException;
use VeyroPHP\Support\Extensions;

/**
 * Opens PDO connections with safe defaults.
 *
 * - Errors always throw (ATTR_ERRMODE cannot be overridden).
 * - Real prepared statements (no emulation) and native types in results.
 * - Rows are fetched as associative arrays.
 * - MySQL: strict SQL mode when "strict" is true. SQLite: foreign keys on.
 *
 * @internal Framework implementation detail, not covered by the backward-compatibility promise.
 */
final class ConnectionFactory
{
    private const MYSQL_STRICT_MODE = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

    /** @var Closure(int): void */
    private readonly Closure $sleep;

    /** @var Closure(ConnectionConfig, array<int, mixed>): PDO */
    private readonly Closure $open;

    /**
     * @param (Closure(int): void)|null $sleep waits the given milliseconds between connect attempts; replaced in tests
     * @param (Closure(ConnectionConfig, array<int, mixed>): PDO)|null $open opens the PDO; replaced in tests
     */
    public function __construct(?Closure $sleep = null, ?Closure $open = null)
    {
        $this->sleep = $sleep ?? static function (int $milliseconds): void {
            usleep($milliseconds * 1000);
        };
        $this->open = $open ?? static fn(ConnectionConfig $config, array $options): PDO => new PDO($config->dsn(), $config->username, $config->password(), $options);
    }

    /**
     * Connect errors that may be temporary (server restarting, network blip,
     * too many connections) are retried "connect_retries" times, waiting 100 ms,
     * then 200 ms, 400 ms, ... Wrong credentials or unknown databases fail at once.
     *
     * @throws ConnectionException when the connection cannot be opened
     */
    public function create(ConnectionConfig $config): PDO
    {
        for ($attempt = 0; ; $attempt++) {
            try {
                return $this->connect($config);
            } catch (ConnectionException $e) {
                $cause = $e->getPrevious();

                if ($attempt >= $config->connectRetries || !$cause instanceof PDOException || !LostConnection::isTransientConnectError($cause)) {
                    throw $e;
                }

                ($this->sleep)(100 * (2 ** $attempt));
            }
        }
    }

    private function connect(ConnectionConfig $config): PDO
    {
        Extensions::require('pdo', 'the database layer');

        if (!in_array($config->driver, PDO::getAvailableDrivers(), true)) {
            throw DatabaseException::invalidConfig($config->name, 'driver', sprintf('the PHP extension pdo_%s is not installed', $config->driver));
        }

        if ($config->driver === 'sqlite' && $config->database !== ':memory:' && !is_dir(dirname($config->database))) {
            throw DatabaseException::invalidConfig($config->name, 'database', sprintf('directory of SQLite file "%s" does not exist', $config->database));
        }

        $options = [
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ];

        // MySQL: connect timeout. SQLite: how long to wait for a locked database.
        // PostgreSQL gets connect_timeout in the DSN instead.
        if ($config->driver !== 'pgsql') {
            $options[PDO::ATTR_TIMEOUT] = $config->timeout;
        }

        foreach ($config->options as $attribute => $value) {
            $options[$attribute] = $value;
        }

        $options[PDO::ATTR_ERRMODE] = PDO::ERRMODE_EXCEPTION;

        try {
            $pdo = ($this->open)($config, $options);

            match ($config->driver) {
                'mysql' => $config->strict ? $pdo->exec("SET SESSION sql_mode = '" . self::MYSQL_STRICT_MODE . "'") : null,
                'sqlite' => $pdo->exec('PRAGMA foreign_keys = ON'),
                default => null,
            };
        } catch (PDOException $e) {
            throw ConnectionException::failed($config->name, $config->driver, $e);
        }

        return $pdo;
    }
}
