<?php

declare(strict_types=1);

namespace VeyroPHP\Queue\Events;

/**
 * A job failed for good (no attempts left, or it can never succeed). Listen to
 * it to alert someone.
 */
final class JobFailed
{
    public function __construct(
        public readonly string $connection,
        public readonly string $queue,
        public readonly string $jobClass,
        public readonly string $jobId,
        public readonly \Throwable $error,
    ) {}
}
