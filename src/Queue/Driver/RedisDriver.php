<?php

declare(strict_types=1);

namespace VeyroPHP\Queue\Driver;

use Closure;
use Redis;
use RedisException;
use VeyroPHP\Queue\QueueException;

/**
 * Jobs in Redis (phpredis). Per queue: a list of ready jobs, a sorted set of
 * delayed jobs (score: available time) and a sorted set of reserved jobs
 * (score: when the reservation expires).
 *
 * pop() runs as one Lua script, so moving due jobs, taking one and reserving it
 * is atomic: two workers never get the same job. release() is one script too, so
 * a job is always either reserved or delayed, never lost in between.
 */
final class RedisDriver implements Driver, ReservesJobs
{
    private const POP = <<<'LUA'
        -- KEYS: ready, delayed, reserved; ARGV: now, reservation expiry, reservation token
        local function migrate(from, to, now)
            local due = redis.call('zrangebyscore', from, '-inf', now, 'LIMIT', 0, 100)
            for _, job in ipairs(due) do
                redis.call('zrem', from, job)
                redis.call('rpush', to, job)
            end
        end
        migrate(KEYS[2], KEYS[1], ARGV[1])
        migrate(KEYS[3], KEYS[1], ARGV[1])
        local job = redis.call('lpop', KEYS[1])
        if not job then return false end
        local data = cjson.decode(job)
        data['attempts'] = (data['attempts'] or 0) + 1
        data['reservation'] = ARGV[3]
        local reserved = cjson.encode(data)
        redis.call('zadd', KEYS[3], ARGV[2], reserved)
        return reserved
        LUA;

    /**
     * One step, so a dropped connection cannot remove the reservation without adding
     * the delayed entry (SVC-5). Only our own reservation is moved: if it expired and
     * another worker took the job, zrem finds nothing and nothing is added.
     */
    private const RELEASE = <<<'LUA'
        -- KEYS: reserved, delayed; ARGV: reservation, available time, delayed entry
        if redis.call('zrem', KEYS[1], ARGV[1]) == 1 then
            redis.call('zadd', KEYS[2], ARGV[2], ARGV[3])
            return 1
        end
        return 0
        LUA;

    /** @var Closure(): int */
    private readonly Closure $clock;

    /**
     * @param (Closure(): int)|null $clock
     */
    public function __construct(
        private readonly Redis $redis,
        private readonly string $prefix = 'queues:',
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
        $id = bin2hex(random_bytes(16));
        $entry = json_encode(['id' => $id, 'payload' => $payload, 'attempts' => 0], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $this->call(fn() => $delaySeconds > 0
            ? $this->redis->zAdd($this->key($queue, 'delayed'), ($this->clock)() + $delaySeconds, $entry)
            : $this->redis->rPush($this->key($queue), $entry));

        return $id;
    }

    public function pop(string $queue): ?ReservedJob
    {
        $now = ($this->clock)();
        $token = bin2hex(random_bytes(16));
        $reserved = $this->call(fn() => $this->redis->eval(
            self::POP,
            [$this->key($queue), $this->key($queue, 'delayed'), $this->key($queue, 'reserved'), $now, $now + $this->retryAfter, $token],
            3,
        ));

        if (!is_string($reserved)) {
            return null;
        }

        $data = json_decode($reserved, true);

        if (!is_array($data) || !is_string($data['id'] ?? null) || !is_string($data['payload'] ?? null) || !is_int($data['attempts'] ?? null)) {
            throw new QueueException('Invalid job in Redis queue ' . $queue);
        }

        // The whole reserved entry identifies the reservation (it is the sorted-set member).
        return new ReservedJob($data['id'], $queue, $data['payload'], $data['attempts'], $reserved);
    }

    public function delete(ReservedJob $job): void
    {
        $this->call(fn() => $this->redis->zRem($this->key($job->queue, 'reserved'), $job->reservation));
    }

    public function release(ReservedJob $job, int $delaySeconds): void
    {
        $data = json_decode($job->reservation, true);

        if (!is_array($data)) {
            return;
        }

        unset($data['reservation']);
        $entry = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $this->call(fn() => $this->redis->eval(
            self::RELEASE,
            [$this->key($job->queue, 'reserved'), $this->key($job->queue, 'delayed'), $job->reservation, ($this->clock)() + max(0, $delaySeconds), $entry],
            2,
        ));
    }

    public function size(string $queue): int
    {
        return (int) $this->call(fn() => (int) $this->redis->lLen($this->key($queue))
            + (int) $this->redis->zCard($this->key($queue, 'delayed'))
            + (int) $this->redis->zCard($this->key($queue, 'reserved')));
    }

    public function clear(string $queue): int
    {
        $size = $this->size($queue);
        $this->call(fn() => $this->redis->del($this->key($queue), $this->key($queue, 'delayed'), $this->key($queue, 'reserved')));

        return $size;
    }

    private function key(string $queue, string $suffix = ''): string
    {
        return $this->prefix . $queue . ($suffix === '' ? '' : ':' . $suffix);
    }

    /**
     * @template T
     * @param Closure(): T $operation
     * @return T
     */
    private function call(Closure $operation): mixed
    {
        try {
            return $operation();
        } catch (RedisException $e) {
            throw new QueueException('Redis queue error: ' . $e->getMessage(), 0, $e);
        }
    }
}
