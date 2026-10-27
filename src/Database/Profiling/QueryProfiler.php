<?php

declare(strict_types=1);

namespace VeyroPHP\Database\Profiling;

use Closure;

/**
 * Collects query statistics for one unit of work, usually one HTTP request.
 *
 * Counts and totals are always exact. At most $maxStored individual queries
 * are kept, so a long-running loop cannot exhaust memory.
 *
 * Repeated identical SQL (same statement, different bindings) is the typical
 * sign of an N+1 problem: one query per row instead of one query for all rows.
 */
final class QueryProfiler
{
    /** @var list<QueryExecuted> */
    private array $queries = [];

    private int $count = 0;

    private float $totalMs = 0.0;

    /** @var array<string, int> */
    private array $executionsBySql = [];

    /** @var list<QueryExecuted> */
    private array $slow = [];

    public function __construct(
        private readonly float $slowThresholdMs = 100.0,
        private readonly int $maxStored = 500,
    ) {}

    public function record(QueryExecuted $query): void
    {
        $this->count++;
        $this->totalMs += $query->durationMs;
        $this->executionsBySql[$query->sql] = ($this->executionsBySql[$query->sql] ?? 0) + 1;

        if (count($this->queries) < $this->maxStored) {
            $this->queries[] = $query;
        }

        if ($query->durationMs >= $this->slowThresholdMs && count($this->slow) < $this->maxStored) {
            $this->slow[] = $query;
        }
    }

    /**
     * A listener for Connection::listen() or DatabaseManager::listen().
     *
     * @return Closure(QueryExecuted): void
     */
    public function listener(): Closure
    {
        return $this->record(...);
    }

    public function count(): int
    {
        return $this->count;
    }

    public function totalTimeMs(): float
    {
        return $this->totalMs;
    }

    /**
     * @return list<QueryExecuted> the first $maxStored queries
     */
    public function queries(): array
    {
        return $this->queries;
    }

    /**
     * @return list<QueryExecuted> queries at or above the slow threshold
     */
    public function slowQueries(): array
    {
        return $this->slow;
    }

    /**
     * SQL statements executed at least $threshold times, most frequent first.
     *
     * @return array<string, int> SQL => executions
     */
    public function repeatedQueries(int $threshold = 2): array
    {
        $repeated = array_filter($this->executionsBySql, static fn(int $executions): bool => $executions >= $threshold);
        arsort($repeated);

        return $repeated;
    }

    public function slowThresholdMs(): float
    {
        return $this->slowThresholdMs;
    }

    public function reset(): void
    {
        $this->queries = [];
        $this->slow = [];
        $this->executionsBySql = [];
        $this->count = 0;
        $this->totalMs = 0.0;
    }
}
