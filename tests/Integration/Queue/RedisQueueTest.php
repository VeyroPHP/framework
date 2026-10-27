<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Integration\Queue;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use VeyroPHP\Queue\Driver\RedisDriver;
use VeyroPHP\Queue\QueueException;
use VeyroPHP\Tests\Fixtures\Queue\FlakyRedis;

/**
 * Runs only with ext-redis and VEYROPHP_TEST_REDIS_HOST (database 15, random key prefix).
 */
#[CoversClass(RedisDriver::class)]
final class RedisQueueTest extends TestCase
{
    private \Redis $redis;

    private string $prefix;

    private int $now = 1_793_000_000;

    protected function setUp(): void
    {
        $host = getenv('VEYROPHP_TEST_REDIS_HOST');

        if (!extension_loaded('redis') || !is_string($host) || $host === '') {
            self::markTestSkipped('Needs ext-redis and VEYROPHP_TEST_REDIS_HOST.');
        }

        $this->redis = new \Redis();
        $this->redis->connect($host, (int) (getenv('VEYROPHP_TEST_REDIS_PORT') ?: 6379), 2.0);
        $this->redis->select(15);
        $this->prefix = 'veyrophp-test-' . bin2hex(random_bytes(4)) . ':';
    }

    protected function tearDown(): void
    {
        if (isset($this->prefix)) {
            $keys = $this->redis->keys($this->prefix . '*');
            if (is_array($keys) && $keys !== []) {
                $this->redis->del($keys);
            }
        }
    }

    private function driver(int $retryAfter = 90): RedisDriver
    {
        return new RedisDriver($this->redis, $this->prefix, $retryAfter, fn(): int => $this->now);
    }

    public function testPushPopDeleteInOrder(): void
    {
        $driver = $this->driver();
        $driver->push('default', '{"n":1}');
        $driver->push('default', '{"n":2}');
        $driver->push('other', '{"n":3}');

        $job = $driver->pop('default');
        self::assertSame(['{"n":1}', 1, 'default'], [$job?->payload, $job?->attempts, $job?->queue]);
        self::assertSame('{"n":2}', $driver->pop('default')?->payload);
        self::assertNull($driver->pop('default'));
        self::assertSame(2, $driver->size('default'), 'reserved jobs still count');

        \assert($job !== null);
        $driver->delete($job);
        self::assertSame(1, $driver->size('default'));
        self::assertSame('{"n":3}', $driver->pop('other')?->payload);
    }

    public function testDelayedJobsAndRelease(): void
    {
        $driver = $this->driver();
        $driver->push('default', 'later', 60);

        self::assertNull($driver->pop('default'));
        $this->now += 60;
        $job = $driver->pop('default');
        self::assertNotNull($job);

        $driver->release($job, 30);
        self::assertNull($driver->pop('default'));
        $this->now += 30;
        self::assertSame(2, $driver->pop('default')?->attempts);
    }

    public function testAbandonedReservationIsTakenOverAndTheOldWorkerCannotDeleteIt(): void
    {
        $driver = $this->driver(retryAfter: 90);
        $driver->push('default', 'job');

        $crashed = $driver->pop('default');
        self::assertNotNull($crashed);
        $this->now += 89;
        self::assertNull($driver->pop('default'));
        $this->now += 1;
        $takeover = $driver->pop('default');
        self::assertSame(2, $takeover?->attempts);

        $driver->delete($crashed);
        $driver->release($crashed, 0);
        self::assertSame(1, $driver->size('default'));
        self::assertNull($driver->pop('default'), 'the stale worker could not release it');

        $driver->delete($takeover);
        self::assertSame(0, $driver->size('default'));
    }

    /**
     * SVC-5: release() moves the job from "reserved" to "delayed". When the connection
     * fails during that move, the job must stay in one of the two, never in neither.
     */
    public function testAReleaseThatFailsHalfwayNeverLosesTheJob(): void
    {
        $redis = new FlakyRedis();
        $redis->connect((string) getenv('VEYROPHP_TEST_REDIS_HOST'), (int) (getenv('VEYROPHP_TEST_REDIS_PORT') ?: 6379), 2.0);
        $redis->select(15);
        $driver = new RedisDriver($redis, $this->prefix, 90, fn(): int => $this->now);
        $driver->push('default', 'job');
        $job = $driver->pop('default');
        self::assertNotNull($job);

        $redis->failing = true;

        try {
            $driver->release($job, 30);
            self::fail('the failed release was hidden');
        } catch (QueueException) {
        }

        $redis->failing = false;
        self::assertSame(1, $driver->size('default'), 'the job is still reserved or delayed');
        $this->now += 90;
        self::assertSame('job', $driver->pop('default')?->payload, 'and runs again once its reservation expires');
    }

    public function testClear(): void
    {
        $driver = $this->driver();
        $driver->push('a', '1');
        $driver->push('a', '2', 30);
        $driver->pop('a');
        $driver->push('b', '3');

        self::assertSame(2, $driver->clear('a'));
        self::assertSame(0, $driver->size('a'));
        self::assertSame(1, $driver->size('b'));
    }
}
