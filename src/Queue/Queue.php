<?php

declare(strict_types=1);

namespace VeyroPHP\Queue;

use Closure;
use VeyroPHP\Queue\Driver\Driver;
use VeyroPHP\Queue\Driver\ReservesJobs;

/**
 * Puts jobs on a queue.
 *
 *     $queue->push(new SendInvoice($order));
 *     $queue->push(new SendInvoice($order), 'emails');          // a named queue
 *     $queue->later(600, new RemindCustomer($order));            // in 10 minutes
 *     $queue->push($job, connection: 'redis');                   // another connection
 *
 * The job is checked and stored when it is pushed (see Job for the rules);
 * a "queue:work" worker runs it later.
 *
 * On a connection whose reservations expire (database, Redis), a job's $timeout
 * must be less than the connection's retry_after: otherwise the job can be given
 * to a second worker while it still runs. A job without a timeout is not checked.
 */
final class Queue
{
    /** Payloads above this size do not fit MySQL's TEXT column. */
    public const MAX_PAYLOAD_BYTES = 60_000;

    /** @var array<string, Driver> */
    private array $drivers = [];

    /**
     * @param Closure(string): Driver $connections creates the driver of a connection
     * @param array<string, string> $defaultQueues connection => queue name used when none is given
     */
    public function __construct(
        private readonly Closure $connections,
        private readonly string $defaultConnection,
        private readonly Payload $payload,
        private readonly array $defaultQueues = [],
    ) {}

    /**
     * @return string the job id
     */
    public function push(Job $job, ?string $queue = null, ?string $connection = null): string
    {
        return $this->later(0, $job, $queue, $connection);
    }

    /**
     * @return string the job id
     */
    public function later(int $delaySeconds, Job $job, ?string $queue = null, ?string $connection = null): string
    {
        $connection ??= $this->defaultConnection;
        $queue ??= $this->defaultQueue($connection);

        if (preg_match('/^[A-Za-z0-9_.:-]{1,100}$/D', $queue) !== 1) {
            throw new QueueException(sprintf('Invalid queue name "%s": use letters, digits, "_", "-", "." and ":".', $queue));
        }

        $driver = $this->driver($connection);
        $timeout = self::option($job, 'timeout', 0, 0);

        if ($driver instanceof ReservesJobs && $timeout > 0 && $timeout >= $driver->retryAfter()) {
            throw new QueueException(sprintf(
                '%s::$timeout (%d s) must be less than retry_after (%d s) of connection "%s": otherwise another worker can start the job while it still runs. Lower the timeout or raise retry_after.',
                $job::class,
                $timeout,
                $driver->retryAfter(),
                $connection,
            ));
        }

        return $driver->push($queue, $this->encode($job), max(0, $delaySeconds));
    }

    public function driver(?string $connection = null): Driver
    {
        $connection ??= $this->defaultConnection;

        return $this->drivers[$connection] ??= ($this->connections)($connection);
    }

    /**
     * The payload packer, for code that queues other objects (mail, listeners).
     */
    public function payload(): Payload
    {
        return $this->payload;
    }

    public function defaultConnection(): string
    {
        return $this->defaultConnection;
    }

    public function defaultQueue(?string $connection = null): string
    {
        return $this->defaultQueues[$connection ?? $this->defaultConnection] ?? 'default';
    }

    private function encode(Job $job): string
    {
        $json = json_encode([
            'uuid' => bin2hex(random_bytes(16)),
            'job' => $this->payload->pack($job),
            'tries' => self::option($job, 'tries', 3, 1),
            'backoff' => self::backoff($job),
            'timeout' => self::option($job, 'timeout', 0, 0),
            'queued_at' => time(),
        ], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (strlen($json) > self::MAX_PAYLOAD_BYTES) {
            throw new QueueException(sprintf('%s is too large to queue (%d bytes, at most %d). Pass ids instead of large data.', $job::class, strlen($json), self::MAX_PAYLOAD_BYTES));
        }

        return $json;
    }

    private static function option(Job $job, string $name, int $default, int $min): int
    {
        if (!property_exists($job, $name)) {
            return $default;
        }

        $value = (new \ReflectionProperty($job, $name))->getValue($job);

        if (!is_int($value) || $value < $min) {
            throw new QueueException(sprintf('%s::$%s must be an integer of at least %d.', $job::class, $name, $min));
        }

        return $value;
    }

    /**
     * @return list<int>
     */
    private static function backoff(Job $job): array
    {
        if (!property_exists($job, 'backoff')) {
            return [];
        }

        $value = (new \ReflectionProperty($job, 'backoff'))->getValue($job);
        $list = is_int($value) ? [$value] : $value;

        if (!is_array($list) || !array_is_list($list) || array_filter($list, static fn(mixed $s): bool => !is_int($s) || $s < 0) !== []) {
            throw new QueueException(sprintf('%s::$backoff must be seconds or a list of seconds.', $job::class));
        }

        /** @var list<int> $list */
        return $list;
    }
}
