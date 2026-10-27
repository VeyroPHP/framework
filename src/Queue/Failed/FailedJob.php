<?php

declare(strict_types=1);

namespace VeyroPHP\Queue\Failed;

final class FailedJob
{
    public function __construct(
        public readonly string $id,
        public readonly string $connection,
        public readonly string $queue,
        public readonly string $payload,
        public readonly string $exception,
        public readonly string $failedAt,
    ) {}

    /**
     * The job class from the payload, for listings.
     */
    public function jobClass(): string
    {
        $data = json_decode($this->payload, true);
        $class = is_array($data) && is_array($data['job'] ?? null) ? ($data['job']['class'] ?? null) : null;

        return is_string($class) ? $class : 'unknown';
    }
}
