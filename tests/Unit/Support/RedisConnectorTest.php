<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VeyroPHP\Application\Application;
use VeyroPHP\Cache\CacheManager;
use VeyroPHP\Cache\Exception\InvalidCacheKeyException;
use VeyroPHP\Config\Config;
use VeyroPHP\Queue\Queue;
use VeyroPHP\Queue\QueueException;
use VeyroPHP\Queue\QueueServiceProvider;
use VeyroPHP\Support\RedisConnector;

/**
 * MISC-5: one connector for the Redis cache store and the Redis queue. Settings are
 * checked before the extension or a connection is needed, so these tests run without
 * Redis; each caller keeps its own exception type.
 */
#[CoversClass(RedisConnector::class)]
final class RedisConnectorTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidSettings(): iterable
    {
        yield 'port as a string' => [['port' => '6379'], 'port'];
        yield 'timeout as a string' => [['timeout' => '1'], 'timeout'];
        yield 'read_timeout as a string' => [['read_timeout' => '5'], 'read_timeout'];
        yield 'negative read_timeout' => [['read_timeout' => -1.0], 'read_timeout'];
        yield 'database as a string' => [['database' => '2'], 'database'];
        yield 'negative database' => [['database' => -1], 'database'];
        yield 'password as a number' => [['password' => 1234], 'password'];
        yield 'empty host' => [['host' => ''], 'host'];
    }

    /**
     * @param array<string, mixed> $settings
     */
    #[DataProvider('invalidSettings')]
    public function testTheCacheStoreRejectsInvalidSettings(array $settings, string $key): void
    {
        $manager = new CacheManager(['default' => 'redis', 'stores' => ['redis' => ['driver' => 'redis', 'host' => '127.0.0.1', ...$settings]]], static fn(string $p): string => $p);

        try {
            $manager->store('redis');
            self::fail('accepted ' . $key);
        } catch (InvalidCacheKeyException $e) {
            self::assertStringContainsString($key, $e->getMessage());
            self::assertStringNotContainsString('ext-redis', $e->getMessage(), 'settings are checked before the extension');
        }
    }

    /**
     * @param array<string, mixed> $settings
     */
    #[DataProvider('invalidSettings')]
    public function testTheQueueRejectsInvalidSettings(array $settings, string $key): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->container()->instance(Config::class, new Config(['queue' => ['default' => 'redis', 'connections' => ['redis' => ['driver' => 'redis', ...$settings]]]]));
        $app->register(new QueueServiceProvider());

        try {
            $app->container()->make(Queue::class)->driver('redis');
            self::fail('accepted ' . $key);
        } catch (QueueException $e) {
            self::assertStringContainsString($key, $e->getMessage());
        }
    }

    public function testPasswordsNeverAppearInErrors(): void
    {
        $manager = new CacheManager(['default' => 'redis', 'stores' => ['redis' => ['driver' => 'redis', 'host' => '127.0.0.1', 'password' => 'hunter2-secret', 'database' => 'x']]], static fn(string $p): string => $p);

        try {
            $manager->store('redis');
            self::fail('accepted an invalid database');
        } catch (InvalidCacheKeyException $e) {
            self::assertStringNotContainsString('hunter2-secret', $e->getMessage());
        }
    }
}
