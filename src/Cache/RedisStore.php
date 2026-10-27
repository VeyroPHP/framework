<?php

declare(strict_types=1);

namespace VeyroPHP\Cache;

use Redis;
use RedisException;

/**
 * Redis store using the phpredis extension (ext-redis). Optional.
 *
 * - Values are serialized with serialize() and restored with the same
 *   allowed-classes rule as FileStore.
 * - Expiry uses Redis TTLs; locks use SET NX PX and a token check on release.
 * - clear() deletes only keys with the given prefix, using SCAN. It never runs FLUSHDB.
 * - Connection or command errors count as misses (reads) or false (writes).
 */
final class RedisStore implements LockingStore
{
    /**
     * @param list<class-string>|bool $allowedClasses
     */
    public function __construct(
        private readonly Redis $redis,
        private readonly array|bool $allowedClasses = false,
    ) {}

    public function fetch(string $key): ?array
    {
        try {
            $raw = $this->redis->get($key);
        } catch (RedisException) {
            return null;
        }

        if (!is_string($raw)) {
            return null;
        }

        $entry = @unserialize($raw, ['allowed_classes' => $this->allowedClasses]);

        return is_array($entry) && array_key_exists('v', $entry) ? [$entry['v']] : null;
    }

    public function put(string $key, mixed $value, ?int $expiresAt): bool
    {
        try {
            $payload = serialize(['v' => $value]);
        } catch (\Throwable) {
            return false;
        }

        try {
            if ($expiresAt === null) {
                return $this->redis->set($key, $payload) === true;
            }

            $seconds = $expiresAt - time();

            return $seconds > 0 ? $this->redis->setex($key, $seconds, $payload) === true : $this->delete($key);
        } catch (RedisException) {
            return false;
        }
    }

    public function delete(string $key): bool
    {
        try {
            $this->redis->del($key);

            return true;
        } catch (RedisException) {
            return false;
        }
    }

    public function clear(string $prefix): bool
    {
        if ($prefix === '') {
            return false; // refuse to delete every key in the database
        }

        try {
            $iterator = null;
            $pattern = str_replace(['\\', '*', '?', '[', ']'], ['\\\\', '\*', '\?', '\[', '\]'], $prefix) . '*';

            do {
                $keys = $this->redis->scan($iterator, $pattern, 500);

                if (is_array($keys) && $keys !== []) {
                    $this->redis->del($keys);
                }
            } while ($iterator > 0);

            return true;
        } catch (RedisException) {
            return false;
        }
    }

    public function acquireLock(string $name, int $seconds): ?string
    {
        $token = bin2hex(random_bytes(8));

        try {
            return $this->redis->set('lock:' . $name, $token, ['nx', 'px' => max(1, $seconds) * 1000]) === true ? $token : null;
        } catch (RedisException) {
            return null;
        }
    }

    public function releaseLock(string $name, string $token): void
    {
        $script = "if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) else return 0 end";

        try {
            $this->redis->eval($script, ['lock:' . $name, $token], 1);
        } catch (RedisException) {
            // The lock expires on its own; nothing else to do.
        }
    }
}
