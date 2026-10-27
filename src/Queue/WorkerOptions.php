<?php

declare(strict_types=1);

namespace VeyroPHP\Queue;

/**
 * How long a worker runs (see "php veyro queue:work --help").
 */
final class WorkerOptions
{
    /**
     * @param int $sleep seconds to wait when every queue is empty
     * @param int $maxJobs stop after this many jobs (0 = no limit)
     * @param int $maxTime stop after this many seconds (0 = no limit)
     * @param int $memoryMb stop when memory use passes this many MB (0 = no limit)
     * @param bool $stopWhenEmpty stop as soon as every queue is empty
     */
    public function __construct(
        public readonly int $sleep = 3,
        public readonly int $maxJobs = 0,
        public readonly int $maxTime = 0,
        public readonly int $memoryMb = 128,
        public readonly bool $stopWhenEmpty = false,
    ) {}
}
