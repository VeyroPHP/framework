<?php

declare(strict_types=1);

namespace VeyroPHP\Cache;

/**
 * Low-level storage behind Cache. Keys arrive already validated and prefixed.
 *
 * Contract for implementations:
 * - A read error (missing, expired or corrupt entry) is a miss, not an exception.
 * - A write error returns false.
 * - clear() removes only keys starting with the given prefix.
 */
interface Store
{
    /**
     * @return array{mixed}|null a one-element array holding the value, or null on a miss
     */
    public function fetch(string $key): ?array;

    /**
     * @param int|null $expiresAt Unix timestamp, or null for no expiry
     */
    public function put(string $key, mixed $value, ?int $expiresAt): bool;

    public function delete(string $key): bool;

    public function clear(string $prefix): bool;
}
