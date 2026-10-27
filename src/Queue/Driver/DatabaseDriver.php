<?php

declare(strict_types=1);

namespace VeyroPHP\Queue\Driver;

use Closure;
use VeyroPHP\Database\Connection\Connection;
use VeyroPHP\Database\Query\Builder;

/**
 * Jobs in a database table (created by QueueSchema). Works on MySQL/MariaDB
 * (any version), PostgreSQL and SQLite.
 *
 * A job is taken with a conditional UPDATE that only succeeds while the row is
 * still free (or its reservation has expired), so two workers never get the
 * same job, without needing SELECT ... FOR UPDATE SKIP LOCKED.
 */
final class DatabaseDriver implements Driver, ReservesJobs
{
    /** How many candidates one pop() tries before giving up for this round. */
    private const CANDIDATES = 5;

    /** @var Closure(): int */
    private readonly Closure $clock;

    /**
     * @param int $retryAfter seconds after which a reserved job counts as abandoned (worker crashed)
     * @param (Closure(): int)|null $clock
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly string $table = 'jobs',
        private readonly int $retryAfter = 90,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn(): int => time();
    }

    public function retryAfter(): int
    {
        return $this->retryAfter;
    }

    public function push(string $queue, string $payload, int $delaySeconds = 0): string
    {
        $now = ($this->clock)();

        return (string) $this->connection->table($this->table)->insertGetId([
            'queue' => $queue,
            'payload' => $payload,
            'attempts' => 0,
            'reserved_by' => null,
            'reserved_at' => null,
            'available_at' => $now + max(0, $delaySeconds),
            'created_at' => $now,
        ]);
    }

    public function pop(string $queue): ?ReservedJob
    {
        $now = ($this->clock)();
        $expired = $now - $this->retryAfter;

        $candidates = $this->connection->table($this->table)
            ->select('id', 'reserved_at')
            ->where('queue', $queue)
            ->where(static function (Builder $q) use ($now, $expired): void {
                $q->where(static fn(Builder $free) => $free->whereNull('reserved_at')->where('available_at', '<=', $now))
                    ->orWhere('reserved_at', '<=', $expired);
            })
            ->orderBy('id')
            ->limit(self::CANDIDATES)
            ->get();

        foreach ($candidates as $candidate) {
            $token = bin2hex(random_bytes(16));
            $query = $this->connection->table($this->table)->where('id', $candidate['id']);

            // Only succeeds if nobody took the row since we read it.
            if ($candidate['reserved_at'] === null) {
                $query->whereNull('reserved_at')->where('available_at', '<=', $now);
            } else {
                $query->where('reserved_at', $candidate['reserved_at'])->where('reserved_at', '<=', $expired);
            }

            $claimed = $query->update([
                'reserved_at' => $now,
                'reserved_by' => $token,
                'attempts' => $this->connection->raw('attempts + 1'),
            ]);

            if ($claimed !== 1) {
                continue;
            }

            $row = $this->connection->table($this->table)->where('id', $candidate['id'])->where('reserved_by', $token)->first();

            if ($row === null) {
                continue;
            }

            $payload = $row['payload'] ?? null;
            $attempts = $row['attempts'] ?? null;

            if (!is_string($payload) || !is_numeric($attempts)) {
                continue;
            }

            return new ReservedJob(is_scalar($candidate['id']) ? (string) $candidate['id'] : '', $queue, $payload, (int) $attempts, $token);
        }

        return null;
    }

    public function delete(ReservedJob $job): void
    {
        $this->connection->table($this->table)->where('id', $job->id)->where('reserved_by', $job->reservation)->delete();
    }

    public function release(ReservedJob $job, int $delaySeconds): void
    {
        $this->connection->table($this->table)->where('id', $job->id)->where('reserved_by', $job->reservation)->update([
            'reserved_at' => null,
            'reserved_by' => null,
            'available_at' => ($this->clock)() + max(0, $delaySeconds),
        ]);
    }

    public function size(string $queue): int
    {
        return $this->connection->table($this->table)->where('queue', $queue)->count();
    }

    public function clear(string $queue): int
    {
        return $this->connection->table($this->table)->where('queue', $queue)->delete();
    }
}
