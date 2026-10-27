<?php

declare(strict_types=1);

namespace VeyroPHP\Cache;

use Closure;
use DateInterval;
use DateTimeImmutable;
use Psr\SimpleCache\CacheInterface;
use VeyroPHP\Cache\Exception\InvalidCacheKeyException;
use VeyroPHP\Cache\Exception\LockTimeoutException;

/**
 * PSR-16 cache with key prefixing and remember().
 *
 * Semantics:
 * - Keys follow PSR-16: non-empty, without {}()/\@:. Invalid keys throw.
 * - TTL: null uses the default TTL (null = forever); zero or negative deletes the entry.
 * - Storing null is allowed: has() is then true and get() returns null.
 * - Storage failures never throw: reads become misses, writes return false.
 * - Invalidation is explicit (delete/clear) or by TTL. Nothing is cached automatically.
 *
 * remember() protects against stampedes when the store supports locks: on a
 * miss, one caller computes the value while others wait up to $lockWaitMs for
 * it. If the wait times out, the waiting caller computes the value itself
 * rather than failing.
 */
final class Cache implements CacheInterface
{
    private const RESERVED = '{}()/\\@:';

    /** @var Closure(): int */
    private readonly Closure $clock;

    /**
     * @param string $prefix namespace prepended as "prefix:" to every key; clear() only affects this namespace
     * @param Closure(): int|null $clock
     */
    public function __construct(
        private readonly Store $store,
        private readonly string $prefix = '',
        private readonly int|DateInterval|null $defaultTtl = null,
        private readonly int $lockSeconds = 10,
        private readonly int $lockWaitMs = 3000,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn(): int => time();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $found = $this->store->fetch($this->key($key));

        return $found === null ? $default : $found[0];
    }

    public function set(string $key, mixed $value, int|DateInterval|null $ttl = null): bool
    {
        $fullKey = $this->key($key);
        $expiresAt = $this->expiresAt($ttl ?? $this->defaultTtl);

        if ($expiresAt !== null && $expiresAt <= ($this->clock)()) {
            return $this->store->delete($fullKey);
        }

        return $this->store->put($fullKey, $value, $expiresAt);
    }

    public function delete(string $key): bool
    {
        return $this->store->delete($this->key($key));
    }

    public function clear(): bool
    {
        return $this->store->clear($this->namespace());
    }

    /**
     * Removes expired entries from the whole store (all prefixes; nothing that is
     * still valid). Returns 0 for stores that expire entries themselves, such as Redis.
     *
     * @return int number of removed entries
     */
    public function prune(): int
    {
        return $this->store instanceof PrunableStore ? $this->store->prune() : 0;
    }

    public function has(string $key): bool
    {
        return $this->store->fetch($this->key($key)) !== null;
    }

    /**
     * @param iterable<string> $keys
     *
     * @return array<string, mixed>
     */
    public function getMultiple(iterable $keys, mixed $default = null): array
    {
        $values = [];

        foreach ($keys as $key) {
            $values[$key] = $this->get($key, $default);
        }

        return $values;
    }

    /**
     * @param iterable<mixed, mixed> $values
     */
    public function setMultiple(iterable $values, int|DateInterval|null $ttl = null): bool
    {
        $ok = true;

        foreach ($values as $key => $value) {
            if (!is_string($key)) {
                throw InvalidCacheKeyException::forKey((string) (is_scalar($key) ? $key : get_debug_type($key)));
            }

            $ok = $this->set($key, $value, $ttl) && $ok;
        }

        return $ok;
    }

    /**
     * @param iterable<string> $keys
     */
    public function deleteMultiple(iterable $keys): bool
    {
        $ok = true;

        foreach ($keys as $key) {
            $ok = $this->delete($key) && $ok;
        }

        return $ok;
    }

    /**
     * Returns the cached value, or computes, stores and returns it.
     *
     * @template T
     *
     * @param Closure(): T $callback
     *
     * @return T|mixed
     */
    public function remember(string $key, int|DateInterval|null $ttl, Closure $callback): mixed
    {
        $fullKey = $this->key($key);
        $found = $this->store->fetch($fullKey);

        if ($found !== null) {
            return $found[0];
        }

        if (!$this->store instanceof LockingStore) {
            return $this->compute($key, $ttl, $callback);
        }

        $deadline = hrtime(true) + $this->lockWaitMs * 1_000_000;

        do {
            $token = $this->store->acquireLock($fullKey, $this->lockSeconds);

            if ($token !== null) {
                try {
                    // Another process may have stored the value while we waited.
                    $found = $this->store->fetch($fullKey);

                    return $found !== null ? $found[0] : $this->compute($key, $ttl, $callback);
                } finally {
                    $this->store->releaseLock($fullKey, $token);
                }
            }

            usleep(20_000);
            $found = $this->store->fetch($fullKey);

            if ($found !== null) {
                return $found[0];
            }
        } while (hrtime(true) < $deadline);

        return $this->compute($key, $ttl, $callback);
    }

    /**
     * Runs $callback only if no other process holds the lock $key; does not wait.
     *
     * The lock expires after $seconds even if the process dies, so choose more
     * than the callback can take. Stores without locks run the callback.
     *
     * @param Closure(): void $callback
     *
     * @return bool false when the lock was held elsewhere and the callback did not run
     */
    public function tryLock(string $key, int $seconds, Closure $callback): bool
    {
        if (!$this->store instanceof LockingStore) {
            $callback();

            return true;
        }

        $fullKey = $this->key($key) . '#lock';
        $token = $this->store->acquireLock($fullKey, max(1, $seconds));

        if ($token === null) {
            return false;
        }

        try {
            $callback();
        } finally {
            $this->store->releaseLock($fullKey, $token);
        }

        return true;
    }

    /**
     * Runs $callback while holding an exclusive lock on $key, for read-modify-write
     * updates such as counters. Waits up to the lock wait time. If the lock cannot be
     * acquired in that time, the callback does not run and LockTimeoutException is
     * thrown (fail closed). Stores without locks run the callback without one.
     *
     * @throws LockTimeoutException
     *
     * @template T
     *
     * @param Closure(): T $callback
     *
     * @return T
     */
    public function lock(string $key, Closure $callback): mixed
    {
        $fullKey = $this->key($key) . '#lock';

        if (!$this->store instanceof LockingStore) {
            return $callback();
        }

        $deadline = hrtime(true) + $this->lockWaitMs * 1_000_000;

        do {
            $token = $this->store->acquireLock($fullKey, $this->lockSeconds);

            if ($token !== null) {
                try {
                    return $callback();
                } finally {
                    $this->store->releaseLock($fullKey, $token);
                }
            }

            usleep(5_000);
        } while (hrtime(true) < $deadline);

        throw LockTimeoutException::forKey($key, $this->lockWaitMs);
    }

    /**
     * remember() without expiry.
     *
     * @template T
     *
     * @param Closure(): T $callback
     *
     * @return T|mixed
     */
    public function rememberForever(string $key, Closure $callback): mixed
    {
        return $this->remember($key, null, $callback);
    }

    /**
     * @template T
     *
     * @param Closure(): T $callback
     *
     * @return T
     */
    private function compute(string $key, int|DateInterval|null $ttl, Closure $callback): mixed
    {
        $value = $callback();
        $this->set($key, $value, $ttl);

        return $value;
    }

    private function key(string $key): string
    {
        if ($key === '' || strpbrk($key, self::RESERVED) !== false) {
            throw InvalidCacheKeyException::forKey($key);
        }

        return $this->namespace() . $key;
    }

    private function namespace(): string
    {
        return $this->prefix === '' ? '' : $this->prefix . ':';
    }

    private function expiresAt(int|DateInterval|null $ttl): ?int
    {
        $now = ($this->clock)();

        return match (true) {
            $ttl === null => null,
            is_int($ttl) => $now + $ttl,
            default => (new DateTimeImmutable('@' . $now))->add($ttl)->getTimestamp(),
        };
    }
}
