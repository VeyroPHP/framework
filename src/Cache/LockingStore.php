<?php

declare(strict_types=1);

namespace VeyroPHP\Cache;

/**
 * A store that can hold short-lived exclusive locks, used by Cache::remember()
 * to let only one process compute a missing value.
 */
interface LockingStore extends Store
{
    /**
     * Tries once to acquire the lock. The lock expires after $seconds even if
     * it is never released, so a crashed process cannot block others forever.
     *
     * @return string|null an owner token for release(), or null when the lock is held elsewhere
     */
    public function acquireLock(string $name, int $seconds): ?string;

    /**
     * Releases the lock only if $token still owns it.
     */
    public function releaseLock(string $name, string $token): void;
}
