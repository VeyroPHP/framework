<?php

declare(strict_types=1);

namespace VeyroPHP\Queue\Driver;

use VeyroPHP\Queue\QueueException;

/**
 * Stores queued jobs (database, Redis, memory). Implement it to use another
 * backend; payloads are opaque JSON strings.
 *
 * @throws QueueException when the backend cannot be reached
 */
interface Driver
{
    /**
     * @return string the job id
     */
    public function push(string $queue, string $payload, int $delaySeconds = 0): string;

    /**
     * Reserves the next available job, or returns null when there is none.
     *
     * @phpstan-impure
     */
    public function pop(string $queue): ?ReservedJob;

    /**
     * Removes a finished (or finally failed) job.
     */
    public function delete(ReservedJob $job): void;

    /**
     * Makes the job available again after $delaySeconds, keeping its attempt count.
     */
    public function release(ReservedJob $job, int $delaySeconds): void;

    /**
     * Jobs waiting or running on the queue.
     *
     * @phpstan-impure
     */
    public function size(string $queue): int;

    /**
     * Deletes every job on the queue; returns how many.
     */
    public function clear(string $queue): int;
}
