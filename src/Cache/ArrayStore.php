<?php

declare(strict_types=1);

namespace VeyroPHP\Cache;

use Closure;

/**
 * In-memory store for tests and per-request caching. Values live only as long as the object.
 */
final class ArrayStore implements LockingStore, PrunableStore
{
    /** @var array<string, array{value: mixed, expiresAt: int|null}> */
    private array $entries = [];

    /** @var array<string, array{token: string, expiresAt: int}> */
    private array $locks = [];

    /** @var Closure(): int */
    private readonly Closure $clock;

    /**
     * @param Closure(): int|null $clock returns the current Unix time; defaults to time()
     */
    public function __construct(?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn(): int => time();
    }

    public function fetch(string $key): ?array
    {
        $entry = $this->entries[$key] ?? null;

        if ($entry === null) {
            return null;
        }

        if ($entry['expiresAt'] !== null && $entry['expiresAt'] <= ($this->clock)()) {
            unset($this->entries[$key]);

            return null;
        }

        return [$entry['value']];
    }

    public function put(string $key, mixed $value, ?int $expiresAt): bool
    {
        $this->entries[$key] = ['value' => $value, 'expiresAt' => $expiresAt];

        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->entries[$key]);

        return true;
    }

    public function prune(): int
    {
        $now = ($this->clock)();
        $removed = 0;

        foreach ($this->entries as $key => $entry) {
            if ($entry['expiresAt'] !== null && $entry['expiresAt'] <= $now) {
                unset($this->entries[$key]);
                $removed++;
            }
        }

        foreach ($this->locks as $name => $lock) {
            if ($lock['expiresAt'] <= $now) {
                unset($this->locks[$name]);
                $removed++;
            }
        }

        return $removed;
    }

    public function clear(string $prefix): bool
    {
        foreach (array_keys($this->entries) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset($this->entries[$key]);
            }
        }

        return true;
    }

    public function acquireLock(string $name, int $seconds): ?string
    {
        $now = ($this->clock)();
        $lock = $this->locks[$name] ?? null;

        if ($lock !== null && $lock['expiresAt'] > $now) {
            return null;
        }

        $token = bin2hex(random_bytes(8));
        $this->locks[$name] = ['token' => $token, 'expiresAt' => $now + max(1, $seconds)];

        return $token;
    }

    public function releaseLock(string $name, string $token): void
    {
        if (($this->locks[$name]['token'] ?? null) === $token) {
            unset($this->locks[$name]);
        }
    }
}
