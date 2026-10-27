<?php

declare(strict_types=1);

namespace VeyroPHP\Queue\Failed;

use VeyroPHP\Database\Connection\Connection;

/**
 * Failed jobs in a database table (created by QueueSchema).
 *
 * The exception is stored as class, message, file and line plus a stack trace
 * without arguments, cut to 30 KB. Payloads are stored as queued: they hold
 * whatever the job was given (ids, not passwords).
 */
final class DatabaseFailedJobStore implements FailedJobStore
{
    private const MAX_EXCEPTION_BYTES = 30_000;

    public function __construct(
        private readonly Connection $connection,
        private readonly string $table = 'failed_jobs',
    ) {}

    /**
     * Idempotent for one job: a job comes back after its failure was recorded when the
     * worker died, or could not delete it, before removing it from the queue. Every
     * queued payload carries a random uuid, so an identical payload on the same
     * connection and queue is the same job, and its existing id is returned. The check
     * reads failed_jobs without an index; the table is expected to stay small (prune it).
     */
    public function record(string $connection, string $queue, string $payload, \Throwable $error): string
    {
        $existing = $this->connection->table($this->table)
            ->where('connection', $connection)
            ->where('queue', $queue)
            ->where('payload', $payload)
            ->value('id');

        if (is_int($existing) || is_string($existing)) {
            return (string) $existing;
        }

        $text = sprintf("%s: %s\nin %s:%d\n%s", $error::class, $error->getMessage(), $error->getFile(), $error->getLine(), $error->getTraceAsString());

        return (string) $this->connection->table($this->table)->insertGetId([
            'connection' => $connection,
            'queue' => $queue,
            'payload' => $payload,
            'exception' => strlen($text) > self::MAX_EXCEPTION_BYTES ? substr($text, 0, self::MAX_EXCEPTION_BYTES) . "\n[cut]" : $text,
            'failed_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    public function all(int $limit = 100): array
    {
        return array_map(
            self::hydrate(...),
            $this->connection->table($this->table)->orderByDesc('id')->limit($limit)->get(),
        );
    }

    public function find(string $id): ?FailedJob
    {
        $row = $this->connection->table($this->table)->where('id', $id)->first();

        return $row === null ? null : self::hydrate($row);
    }

    public function forget(string $id): bool
    {
        return $this->connection->table($this->table)->where('id', $id)->delete() === 1;
    }

    public function flush(): int
    {
        return $this->connection->table($this->table)->deleteAll();
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): FailedJob
    {
        $string = static fn(string $key): string => is_scalar($row[$key] ?? null) ? (string) $row[$key] : '';

        return new FailedJob($string('id'), $string('connection'), $string('queue'), $string('payload'), $string('exception'), $string('failed_at'));
    }
}
