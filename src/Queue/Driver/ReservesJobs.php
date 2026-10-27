<?php

declare(strict_types=1);

namespace VeyroPHP\Queue\Driver;

/**
 * A driver whose reserved jobs become available again after retry_after seconds,
 * when the worker that reserved them is presumed dead (database, Redis).
 */
interface ReservesJobs
{
    /**
     * Seconds after which a reserved job is handed to another worker.
     */
    public function retryAfter(): int;
}
