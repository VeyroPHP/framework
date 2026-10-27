<?php

declare(strict_types=1);

namespace VeyroPHP\Security;

use Closure;
use VeyroPHP\Cache\Cache;
use VeyroPHP\Cache\Exception\LockTimeoutException;

/**
 * Counts attempts per key in fixed time windows, stored in the cache.
 *
 *     $key = 'login:' . strtolower($email) . '|' . $ip;
 *     if (!$limiter->attempt($key, 5, 60)) { ... 429, retry in $limiter->availableIn($key) s }
 *     // on success: $limiter->clear($key);
 *
 * attempt() checks and counts in one locked step. Do not combine tooManyAttempts()
 * with hit() for limits: parallel requests can all pass the check before any is
 * counted (VQ-020).
 *
 * Fixed windows allow up to 2 × max attempts around a window boundary; choose
 * limits with that in mind. When the cache lock cannot be acquired in time,
 * attempt() refuses and hit() throws (fail closed, VQ-057). When the cache itself
 * is unavailable, limits are not enforced (ADR 0016; see the threat model).
 */
final class RateLimiter
{
    /** @var Closure(): int */
    private readonly Closure $clock;

    /**
     * @param Closure(): int|null $clock
     */
    public function __construct(private readonly Cache $cache, ?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn(): int => time();
    }

    /**
     * Records one attempt and returns the number of attempts in the current window.
     *
     * @throws LockTimeoutException when the counter's lock cannot be acquired in time;
     *                              nothing is counted
     */
    public function hit(string $key, int $decaySeconds = 60): int
    {
        $cacheKey = self::cacheKey($key);

        return $this->cache->lock($cacheKey, function () use ($cacheKey, $decaySeconds): int {
            $now = ($this->clock)();
            $state = $this->state($cacheKey);

            if ($state === null || $state['reset_at'] <= $now) {
                $state = ['count' => 0, 'reset_at' => $now + max(1, $decaySeconds)];
            }

            $state['count']++;
            $this->cache->set($cacheKey, $state, max(1, $state['reset_at'] - $now));

            return $state['count'];
        });
    }

    /**
     * Counts one attempt and returns true when it is within $maxAttempts, or returns
     * false (and counts nothing) when the limit is reached. The check and the count
     * happen under one cache lock, so parallel requests cannot all pass the check
     * before any is counted, as they can with tooManyAttempts() followed by hit().
     *
     * Atomic only where the cache store supports locks (array, file, Redis). When the
     * lock cannot be acquired in time it fails closed: the attempt is refused (false).
     */
    public function attempt(string $key, int $maxAttempts, int $decaySeconds = 60): bool
    {
        $cacheKey = self::cacheKey($key);

        try {
            return $this->attemptLocked($cacheKey, $maxAttempts, $decaySeconds);
        } catch (LockTimeoutException) {
            return false;
        }
    }

    private function attemptLocked(string $cacheKey, int $maxAttempts, int $decaySeconds): bool
    {
        return $this->cache->lock($cacheKey, function () use ($cacheKey, $maxAttempts, $decaySeconds): bool {
            $now = ($this->clock)();
            $state = $this->state($cacheKey);

            if ($state === null || $state['reset_at'] <= $now) {
                $state = ['count' => 0, 'reset_at' => $now + max(1, $decaySeconds)];
            }

            if ($state['count'] >= $maxAttempts) {
                return false;
            }

            $state['count']++;
            $this->cache->set($cacheKey, $state, max(1, $state['reset_at'] - $now));

            return true;
        });
    }

    public function attempts(string $key): int
    {
        $state = $this->state(self::cacheKey($key));

        return $state === null || $state['reset_at'] <= ($this->clock)() ? 0 : $state['count'];
    }

    public function tooManyAttempts(string $key, int $maxAttempts): bool
    {
        return $this->attempts($key) >= $maxAttempts;
    }

    public function remaining(string $key, int $maxAttempts): int
    {
        return max(0, $maxAttempts - $this->attempts($key));
    }

    /**
     * Seconds until the current window ends (0 when there is no active window).
     */
    public function availableIn(string $key): int
    {
        $state = $this->state(self::cacheKey($key));

        return $state === null ? 0 : max(0, $state['reset_at'] - ($this->clock)());
    }

    public function clear(string $key): void
    {
        $this->cache->delete(self::cacheKey($key));
    }

    /**
     * @return array{count: int, reset_at: int}|null
     */
    private function state(string $cacheKey): ?array
    {
        $state = $this->cache->get($cacheKey);

        if (!is_array($state) || !is_int($state['count'] ?? null) || !is_int($state['reset_at'] ?? null)) {
            return null;
        }

        return ['count' => $state['count'], 'reset_at' => $state['reset_at']];
    }

    /**
     * Keys may contain anything (emails, IPs); hash them into a valid PSR-16 key.
     */
    private static function cacheKey(string $key): string
    {
        return 'ratelimit.' . hash('sha256', $key);
    }
}
