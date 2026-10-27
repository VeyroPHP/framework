<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Unit\Cache;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;
use VeyroPHP\Application\Application;
use VeyroPHP\Cache\Cache;
use VeyroPHP\Cache\CacheManager;
use VeyroPHP\Cache\CacheServiceProvider;
use VeyroPHP\Cache\Exception\InvalidCacheKeyException;
use VeyroPHP\Config\Config;

#[CoversClass(CacheManager::class)]
#[CoversClass(CacheServiceProvider::class)]
final class CacheSetupTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = (string) realpath(sys_get_temp_dir()) . DIRECTORY_SEPARATOR . 'veyrophp-cacheprov-' . bin2hex(random_bytes(6));
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/storage/cache/*/*') ?: [] as $file) {
            unlink($file);
        }
        foreach ([...(glob($this->root . '/storage/cache/*', GLOB_ONLYDIR) ?: []), $this->root . '/storage/cache', $this->root . '/storage', $this->root] as $dir) {
            if (is_dir($dir)) {
                rmdir($dir);
            }
        }
    }

    public function testDefaultsToFileStoreInStorageCache(): void
    {
        $app = new Application($this->root);
        $app->register(new CacheServiceProvider());

        $cache = $app->container()->make(Cache::class);
        $cache->set('k', 'v');

        self::assertSame('v', $cache->get('k'));
        self::assertNotEmpty(glob($this->root . '/storage/cache/*/*.cache'));
        self::assertSame($cache, $app->container()->get(CacheInterface::class), 'same store instance through PSR-16');
    }

    public function testNamedStoresFromConfig(): void
    {
        $app = new Application($this->root);
        $app->container()->instance(Config::class, new Config(['cache' => [
            'default' => 'memory',
            'prefix' => 'shop',
            'stores' => ['memory' => ['driver' => 'array'], 'disk' => ['driver' => 'file', 'path' => 'storage/cache']],
        ]]));
        $app->register(new CacheServiceProvider());

        $manager = $app->container()->make(CacheManager::class);
        $manager->store()->set('k', 'memory');

        self::assertSame('memory', $manager->store('memory')->get('k'));
        self::assertNull($manager->store('disk')->get('k'));
        self::assertSame($manager->store('disk'), $manager->store('disk'));
    }

    public function testInvalidConfigurations(): void
    {
        $cases = [
            ['default' => 'missing', 'stores' => []],
            ['default' => 'x', 'stores' => ['x' => ['driver' => 'memcached']]],
            ['default' => 'x', 'stores' => ['x' => ['driver' => 'file']]],
            ['default' => 'x', 'allowed_classes' => 'yes', 'stores' => ['x' => ['driver' => 'array']]],
            ['default' => '', 'stores' => []],
        ];

        foreach ($cases as $config) {
            try {
                (new CacheManager($config, static fn(string $p): string => $p))->store();
                self::fail('Expected InvalidCacheKeyException for ' . json_encode($config));
            } catch (InvalidCacheKeyException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testRedisDriverWithoutExtensionFailsClearly(): void
    {
        if (extension_loaded('redis')) {
            self::markTestSkipped('ext-redis is installed.');
        }

        $this->expectException(InvalidCacheKeyException::class);
        $this->expectExceptionMessage('ext-redis');

        (new CacheManager(['default' => 'r', 'stores' => ['r' => ['driver' => 'redis', 'host' => '127.0.0.1']]], static fn(string $p): string => $p))->store();
    }
}
