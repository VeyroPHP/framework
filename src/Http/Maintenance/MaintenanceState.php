<?php

declare(strict_types=1);

namespace VeyroPHP\Http\Maintenance;

use VeyroPHP\Http\ClientIp;

/**
 * @internal one read of the maintenance file, so a request asks every question
 * (allowed? secret? retry and message?) of the same state (HTTP-7)
 */
final class MaintenanceState
{
    /**
     * @param list<string> $allow valid addresses and CIDR ranges
     * @param string|null $secretHash SHA-256 of the bypass secret
     */
    public function __construct(
        public readonly int $since,
        public readonly int $retry,
        public readonly string $message,
        public readonly array $allow,
        public readonly ?string $secretHash,
    ) {}

    /**
     * Ranges are matched like trusted proxies.
     */
    public function allows(string $ip): bool
    {
        return $this->allow !== [] && (new ClientIp($this->allow))->isTrusted($ip);
    }

    public function matchesSecret(string $secret): bool
    {
        return $this->secretHash !== null && $secret !== '' && hash_equals($this->secretHash, hash('sha256', $secret));
    }
}
