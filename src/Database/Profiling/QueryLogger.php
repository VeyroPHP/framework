<?php

declare(strict_types=1);

namespace VeyroPHP\Database\Profiling;

use Psr\Log\LoggerInterface;

/**
 * Query listener that logs slow queries (warning) and optionally every query (debug).
 *
 * Log entries contain the SQL with placeholders, the binding count, the
 * duration and the connection name. Bound values are never logged. Failed
 * queries are not logged here, because they throw a QueryException that is
 * reported by the error handling.
 *
 * @internal Framework implementation detail, not covered by the backward-compatibility promise.
 */
final class QueryLogger
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly float $slowThresholdMs = 100.0,
        private readonly bool $logAllQueries = false,
    ) {}

    public function __invoke(QueryExecuted $query): void
    {
        if ($query->failed) {
            return;
        }

        $context = [
            'sql' => $query->sql,
            'binding_count' => $query->bindingCount,
            'duration_ms' => round($query->durationMs, 3),
            'connection' => $query->connection,
        ];

        if ($query->durationMs >= $this->slowThresholdMs) {
            $this->logger->warning('Slow query ({duration_ms} ms): {sql}', $context);
        } elseif ($this->logAllQueries) {
            $this->logger->debug('Query ({duration_ms} ms): {sql}', $context);
        }
    }
}
