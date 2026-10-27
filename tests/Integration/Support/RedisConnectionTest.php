<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Integration\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use VeyroPHP\Cache\CacheManager;
use VeyroPHP\Cache\Exception\InvalidCacheKeyException;
use VeyroPHP\Support\RedisConnector;

/**
 * MISC-5 against a real server. Runs only with ext-redis and VEYROPHP_TEST_REDIS_HOST.
 */
#[CoversClass(RedisConnector::class)]
final class RedisConnectionTest extends TestCase
{
    /** @var array{host: string, port: int} */
    private array $server;

    protected function setUp(): void
    {
        $host = getenv('VEYROPHP_TEST_REDIS_HOST');

        if (!extension_loaded('redis') || !is_string($host) || $host === '') {
            self::markTestSkipped('Needs ext-redis and VEYROPHP_TEST_REDIS_HOST.');
        }

        $this->server = ['host' => $host, 'port' => (int) (getenv('VEYROPHP_TEST_REDIS_PORT') ?: 6379)];
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function connect(array $settings = []): \Redis
    {
        return RedisConnector::connect([...$this->server, ...$settings], static fn(string $problem, ?\Throwable $previous = null): \Throwable => new \LogicException($problem, 0, $previous), null, 1.0);
    }

    public function testAReadTimeoutIsAlwaysSet(): void
    {
        self::assertEqualsWithDelta(5.0, $this->connect()->getOption(\Redis::OPT_READ_TIMEOUT), 0.001, 'the default');
        self::assertEqualsWithDelta(0.5, $this->connect(['read_timeout' => 0.5])->getOption(\Redis::OPT_READ_TIMEOUT), 0.001);
    }

    public function testTheConfiguredDatabaseIsSelected(): void
    {
        $redis = $this->connect(['database' => 14]);
        $key = 'veyrophp-test-' . bin2hex(random_bytes(4));
        $redis->set($key, 'here');

        try {
            $check = $this->connect(['database' => 14]);
            self::assertSame('here', $check->get($key));
            self::assertFalse($this->connect(['database' => 13])->get($key), 'not in another database');
        } finally {
            $redis->del($key);
        }
    }

    public function testADatabaseThatCannotBeSelectedFailsInsteadOfFallingBackToZero(): void
    {
        $manager = new CacheManager(['default' => 'redis', 'stores' => ['redis' => ['driver' => 'redis', ...$this->server, 'database' => 9999]]], static fn(string $p): string => $p);

        try {
            $manager->store('redis');
            self::fail('database 9999 was accepted');
        } catch (InvalidCacheKeyException $e) {
            self::assertStringContainsString('database 9999', $e->getMessage());
        }
    }
}
