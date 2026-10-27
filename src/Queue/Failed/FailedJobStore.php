<?php

declare(strict_types=1);

namespace VeyroPHP\Queue\Failed;

/**
 * Keeps jobs that failed for good, so they can be inspected and retried
 * ("php veyro queue:failed", "queue:retry").
 */
interface FailedJobStore
{
    /**
     * Should be idempotent for the same connection, queue and payload (the same job
     * failed again after a crash), returning the existing id. Throw when the failure
     * cannot be stored: the worker then keeps the job in the queue.
     *
     * @return string the failed job's id
     */
    public function record(string $connection, string $queue, string $payload, \Throwable $error): string;

    /**
     * Newest first.
     *
     * @return list<FailedJob>
     */
    public function all(int $limit = 100): array;

    public function find(string $id): ?FailedJob;

    public function forget(string $id): bool;

    /**
     * @return int how many were deleted
     */
    public function flush(): int;
}
