<?php

declare(strict_types=1);

namespace VeyroPHP\Database\Profiling;

/**
 * Event passed to query listeners after every statement.
 *
 * It carries the SQL with placeholders and the number of bindings, never the
 * bound values, so it is safe to log.
 */
final class QueryExecuted
{
    public function __construct(
        public readonly string $connection,
        public readonly string $sql,
        public readonly int $bindingCount,
        public readonly float $durationMs,
        public readonly bool $failed = false,
    ) {}
}
