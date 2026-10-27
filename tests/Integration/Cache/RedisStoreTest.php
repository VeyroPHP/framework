<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Integration\Cache;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use VeyroPHP\Cache\Cache;
use VeyroPHP\Cache\RedisStore;

/**
 * Runs only with ext-redis and VEYROPHP_TEST_REDIS_HOST set. Uses database 15
 * and a random prefix, and clears only that prefix.
 */
#[CoversClass(RedisStore::class)]
final class RedisStoreTest extends TestCase
{
    private Cache $cache;

    private RedisStore $store;

    protected function setUp(): void
    {
        $host = getenv('VEYROPHP_TEST_REDIS_HOST');

        if (!extension_loaded('redis') || !is_string($host) || $host === '') {
            self::markTestSkipped('Needs ext-redis and VEYROPHP_TEST_REDIS_HOST.');
        }

        $redis = new \Redis();
        $redis->connect($host, (int) (getenv('VEYROPHP_TEST_REDIS_PORT') ?: 6379), 2.0);
        $redis->select(15);

        $this->store = new RedisStore($redis);
        $this->cache = new Cache($this->store, 'veyrophp-test-' . bin2hex(random_bytes(4)));
    }

    protected function tearDown(): void
    {
        if (isset($this->cache)) {
            $this->cache->clear();
        }
    }

    public function testRoundTripTtlAndNull(): void
    {
        self::assertTrue($this->cache->set('a', ['x' => 1], 60));
        self::assertSame(['x' => 1], $this->cache->get('a'));
        self::assertTrue($this->cache->set('n', null));
        self::assertTrue($this->cache->has('n'));
        self::assertTrue($this->cache->set('gone', 'v', -1));
        self::assertFalse($this->cache->has('gone'));
    }

    public function testClearOnlyRemovesPrefix(): void
    {
        $other = new Cache($this->store, 'veyrophp-other-' . bin2hex(random_bytes(4)));
        $other->set('k', 'keep');
        $this->cache->set('k', 'drop');

        $this->cache->clear();

        self::assertFalse($this->cache->has('k'));
        self::assertSame('keep', $other->get('k'));
        $other->clear();
    }

    public function testLocks(): void
    {
        $token = $this->store->acquireLock('job-' . bin2hex(random_bytes(4)), 5);
        self::assertNotNull($token);
    }

    public function testClearWithEmptyPrefixIsRefused(): void
    {
        self::assertFalse($this->store->clear(''));
    }
}
