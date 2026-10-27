<?php

declare(strict_types=1);

namespace VeyroPHP\Support;

use Closure;

/**
 * @internal opens the phpredis connections of the Redis cache store and the Redis queue (MISC-5)
 *
 * Settings: host, port (6379), timeout (connect, seconds), read_timeout (seconds, 5),
 * password (null or "" for none), database (0).
 *
 * - Settings are checked before the extension or a connection is needed, and an error
 *   names the key, never its value, so a password cannot appear in a message.
 * - A failed authentication or database selection throws: a database that cannot be
 *   selected must not silently become database 0, where another application's data may be.
 * - A read timeout is always set, so a Redis server that stops answering cannot hold a
 *   request or a worker for PHP's default socket timeout.
 * - Errors are built by the caller's $fail, so the cache and the queue keep their own
 *   exception types.
 */
final class RedisConnector
{
    public const DEFAULT_READ_TIMEOUT = 5.0;

    /**
     * @param array<array-key, mixed> $settings
     * @param Closure(string, ?\Throwable=): \Throwable $fail builds the exception for a problem
     * @param string|null $defaultHost null makes "host" required
     *
     * @throws \Throwable built by $fail
     */
    public static function connect(array $settings, Closure $fail, ?string $defaultHost, float $defaultTimeout): \Redis
    {
        $host = $settings['host'] ?? $defaultHost;
        $port = $settings['port'] ?? 6379;
        $timeout = self::seconds($settings['timeout'] ?? $defaultTimeout);
        $readTimeout = self::seconds($settings['read_timeout'] ?? self::DEFAULT_READ_TIMEOUT);
        $password = $settings['password'] ?? null;
        $database = $settings['database'] ?? 0;

        if (!is_string($host) || $host === '') {
            throw $fail('"host" must be a non-empty string');
        }

        if (!is_int($port) || $port < 1 || $port > 65535) {
            throw $fail('"port" must be an int from 1 to 65535');
        }

        if ($timeout === null || $readTimeout === null) {
            throw $fail(sprintf('"%s" must be a number of seconds above 0', $timeout === null ? 'timeout' : 'read_timeout'));
        }

        if ($password !== null && !is_string($password)) {
            throw $fail('"password" must be a string or null');
        }

        if (!is_int($database) || $database < 0) {
            throw $fail('"database" must be an int of 0 or more');
        }

        if (!extension_loaded('redis')) {
            throw $fail('the redis driver needs the PHP extension ext-redis');
        }

        $redis = new \Redis();

        try {
            $redis->connect($host, $port, $timeout);
            $redis->setOption(\Redis::OPT_READ_TIMEOUT, $readTimeout);

            // phpredis 6 throws on a wrong password; older versions return false.
            if (is_string($password) && $password !== '' && $redis->auth($password) !== true) {
                throw $fail('Redis refused the password');
            }

            if ($database !== 0 && $redis->select($database) !== true) {
                throw $fail(sprintf('Redis could not select database %d (check "database" and the server\'s "databases" setting)', $database));
            }
        } catch (\RedisException $e) {
            throw $fail('could not connect to Redis: ' . $e->getMessage(), $e);
        }

        return $redis;
    }

    /**
     * A positive number of seconds as a float, or null when $value is not one.
     */
    private static function seconds(mixed $value): ?float
    {
        return (is_int($value) || is_float($value)) && $value > 0 ? (float) $value : null;
    }
}
