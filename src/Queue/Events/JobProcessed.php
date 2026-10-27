<?php

declare(strict_types=1);

namespace VeyroPHP\Queue\Events;

/**
 * A job finished successfully.
 */
final class JobProcessed
{
    public function __construct(
        public readonly string $connection,
        public readonly string $queue,
        public readonly string $jobClass,
        public readonly string $jobId,
        public readonly int $attempts,
    ) {}
}
