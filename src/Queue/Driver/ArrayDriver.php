<?php

declare(strict_types=1);

namespace VeyroPHP\Queue\Driver;

use Closure;

/**
 * Jobs in memory, for tests: nothing survives the process. Inspect what was
 * queued with jobs(); run them with a Worker if the test needs to.
 */
final class ArrayDriver implements Driver
{
    /** @var array<int, array{queue: string, payload: string, attempts: int, available_at: int, reservation: string|null}> */
    private array $jobs = [];

    private int $nextId = 1;

    /** @var Closure(): int */
    private readonly Closure $clock;

    /**
     * @param (Closure(): int)|null $clock
     */
    public function __construct(?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn(): int => time();
    }

    public function push(string $queue, string $payload, int $delaySeconds = 0): string
    {
        $id = $this->nextId++;
        $this->jobs[$id] = [
            'queue' => $queue,
            'payload' => $payload,
            'attempts' => 0,
            'available_at' => ($this->clock)() + max(0, $delaySeconds),
            'reservation' => null,
        ];

        return (string) $id;
    }

    public function pop(string $queue): ?ReservedJob
    {
        foreach ($this->jobs as $id => $job) {
            if ($job['queue'] === $queue && $job['reservation'] === null && $job['available_at'] <= ($this->clock)()) {
                $job['reservation'] = bin2hex(random_bytes(8));
                $job['attempts']++;
                $this->jobs[$id] = $job;

                return new ReservedJob((string) $id, $queue, $job['payload'], $job['attempts'], $job['reservation']);
            }
        }

        return null;
    }

    public function delete(ReservedJob $job): void
    {
        if (($this->jobs[(int) $job->id]['reservation'] ?? null) === $job->reservation) {
            unset($this->jobs[(int) $job->id]);
        }
    }

    public function release(ReservedJob $job, int $delaySeconds): void
    {
        $stored = $this->jobs[(int) $job->id] ?? null;

        if ($stored !== null && $stored['reservation'] === $job->reservation) {
            $stored['reservation'] = null;
            $stored['available_at'] = ($this->clock)() + max(0, $delaySeconds);
            $this->jobs[(int) $job->id] = $stored;
        }
    }

    public function size(string $queue): int
    {
        return count(array_filter($this->jobs, static fn(array $job): bool => $job['queue'] === $queue));
    }

    public function clear(string $queue): int
    {
        $before = count($this->jobs);
        $this->jobs = array_filter($this->jobs, static fn(array $job): bool => $job['queue'] !== $queue);

        return $before - count($this->jobs);
    }

    /**
     * Queued jobs (decoded payloads), optionally only one class.
     *
     * @return list<array<string, mixed>>
     */
    public function jobs(?string $class = null): array
    {
        $found = [];

        foreach ($this->jobs as $job) {
            $payload = json_decode($job['payload'], true);

            $jobClass = is_array($payload) && is_array($payload['job'] ?? null) ? ($payload['job']['class'] ?? null) : null;

            if (is_array($payload) && ($class === null || $jobClass === $class)) {
                /** @var array<string, mixed> $payload */
                $found[] = $payload + ['queue' => $job['queue'], 'attempts' => $job['attempts']];
            }
        }

        return $found;
    }
}
