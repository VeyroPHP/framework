<?php

declare(strict_types=1);

namespace VeyroPHP\Cache\Exception;

use RuntimeException;
use VeyroPHP\Exception\VeyroPHPException;

/**
 * Cache::lock() could not acquire its lock within the wait time, so the callback
 * did not run (VQ-057: fail closed instead of running without the lock).
 */
final class LockTimeoutException extends RuntimeException implements VeyroPHPException
{
    public static function forKey(string $key, int $waitMs): self
    {
        return new self(sprintf('Could not acquire the cache lock "%s" within %d ms; the work was not done.', $key, $waitMs));
    }
}
