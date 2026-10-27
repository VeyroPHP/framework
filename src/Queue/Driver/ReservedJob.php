<?php

declare(strict_types=1);

namespace VeyroPHP\Queue\Driver;

/**
 * A job a worker has taken from the queue. Until it is deleted or released,
 * other workers skip it; after "retry_after" seconds without either (a crashed
 * worker), it becomes available again.
 *
 * $attempts already counts this attempt. $reservation identifies this
 * reservation, so a slow worker cannot delete a job another worker has taken
 * over in the meantime.
 */
final class ReservedJob
{
    public function __construct(
        public readonly string $id,
        public readonly string $queue,
        public readonly string $payload,
        public readonly int $attempts,
        public readonly string $reservation,
    ) {}
}
