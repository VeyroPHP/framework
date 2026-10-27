<?php

declare(strict_types=1);

namespace VeyroPHP\Auth;

use VeyroPHP\Http\ClientIp;
use VeyroPHP\Security\RateLimiter;

/**
 * Limits login attempts with two counters (VQ-020), in the framework so that every
 * application gets fixes (MISC-9):
 *
 * - per identifier and client address (IPv6 by its /64): guessing one account's
 *   password is slow, while other people on the same network can still log in;
 * - per identifier across all addresses: guessing spread over many IPs is slow too.
 *
 *     if (($wait = $throttle->attempt($email, $ip)) !== null) { ... "try again in $wait s" }
 *     if ($auth->attempt($email, $password)) { $throttle->clear($email, $ip); ... }
 *
 * Unknown identifiers are counted the same way, so the answer reveals nothing.
 * attempt() checks and counts in one locked step (RateLimiter::attempt()), so
 * parallel requests get no extra guesses. Config "security.login": max_attempts (5),
 * decay_seconds (60), account_max_attempts (20), account_decay_seconds (900), and
 * "security.throttle.ipv6_prefix" (64).
 */
final class LoginThrottle
{
    public function __construct(
        private readonly RateLimiter $limiter,
        private readonly int $maxAttempts = 5,
        private readonly int $decaySeconds = 60,
        private readonly int $accountMaxAttempts = 20,
        private readonly int $accountDecaySeconds = 900,
        private readonly int $ipv6Prefix = 64,
    ) {
        if (min($maxAttempts, $decaySeconds, $accountMaxAttempts, $accountDecaySeconds) < 1) {
            throw new \InvalidArgumentException('Login limits and decay times must be at least 1.');
        }
    }

    /**
     * Counts one login attempt. Null when it may go ahead; otherwise the seconds until
     * the next attempt is allowed.
     */
    public function attempt(string $identifier, string $clientIp): ?int
    {
        foreach ($this->limits($identifier, $clientIp) as [$key, $max, $decay]) {
            if (!$this->limiter->attempt($key, $max, $decay)) {
                return max(1, $this->limiter->availableIn($key));
            }
        }

        return null;
    }

    /**
     * Resets both counters after a successful login.
     */
    public function clear(string $identifier, string $clientIp): void
    {
        foreach ($this->limits($identifier, $clientIp) as [$key]) {
            $this->limiter->clear($key);
        }
    }

    /**
     * @return list<array{string, int, int}> key, maximum attempts, decay seconds
     */
    private function limits(string $identifier, string $clientIp): array
    {
        $identifier = mb_strtolower(trim($identifier));

        return [
            ['login:' . $identifier . '|' . ClientIp::forRateLimit($clientIp, $this->ipv6Prefix), $this->maxAttempts, $this->decaySeconds],
            ['login-account:' . $identifier, $this->accountMaxAttempts, $this->accountDecaySeconds],
        ];
    }
}
