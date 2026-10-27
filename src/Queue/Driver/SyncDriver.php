<?php

declare(strict_types=1);

namespace VeyroPHP\Queue\Driver;

use Closure;

/**
 * Runs each job immediately when it is pushed, in the same request. For local
 * development without a worker; an exception from the job reaches the code
 * that queued it, and nothing is retried.
 */
final class SyncDriver implements Driver
{
    /**
     * @param Closure(string): void $run runs a payload
     */
    public function __construct(private readonly Closure $run) {}

    public function push(string $queue, string $payload, int $delaySeconds = 0): string
    {
        ($this->run)($payload);

        return 'sync';
    }

    /**
     * @phpstan-pure
     */
    public function pop(string $queue): ?ReservedJob
    {
        return null;
    }

    public function delete(ReservedJob $job): void {}

    public function release(ReservedJob $job, int $delaySeconds): void {}

    /**
     * @phpstan-pure
     */
    public function size(string $queue): int
    {
        return 0;
    }

    public function clear(string $queue): int
    {
        return 0;
    }
}
