<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Unit\Cache;

use DateInterval;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\InvalidArgumentException;
use RuntimeException;
use stdClass;
use VeyroPHP\Cache\ArrayStore;
use VeyroPHP\Cache\Cache;
use VeyroPHP\Cache\Exception\InvalidCacheKeyException;
use VeyroPHP\Cache\FileStore;
use VeyroPHP\Cache\Store;

/**
 * Cache semantics, run against both the array and the file store.
 */
#[CoversClass(Cache::class)]
#[CoversClass(ArrayStore::class)]
#[CoversClass(FileStore::class)]
#[CoversClass(InvalidCacheKeyException::class)]
final class CacheTest extends TestCase
{
    private int $now = 1_800_000_000;

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'veyrophp-cache-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        self::removeDirectory($this->dir);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function stores(): iterable
    {
        yield 'array' => ['array'];
        yield 'file' => ['file'];
    }

    private function store(string $type): Store
    {
        $clock = fn(): int => $this->now;

        return $type === 'array' ? new ArrayStore($clock) : new FileStore($this->dir, false, $clock);
    }

    private function cache(string $type, string $prefix = 'app', int|DateInterval|null $defaultTtl = null, ?Store $store = null): Cache
    {
        return new Cache($store ?? $this->store($type), $prefix, $defaultTtl, 10, 3000, fn(): int => $this->now);
    }

    #[DataProvider('stores')]
    public function testSetGetDeleteHas(string $type): void
    {
        $cache = $this->cache($type);

        self::assertTrue($cache->set('user.5', ['name' => 'Ravi']));
        self::assertSame(['name' => 'Ravi'], $cache->get('user.5'));
        self::assertTrue($cache->has('user.5'));
        self::assertTrue($cache->delete('user.5'));
        self::assertFalse($cache->has('user.5'));
        self::assertSame('fallback', $cache->get('user.5', 'fallback'));
        self::assertTrue($cache->delete('never-set'));
    }

    #[DataProvider('stores')]
    public function testScalarTypesSurviveRoundTrip(string $type): void
    {
        $cache = $this->cache($type);
        $values = ['int' => 42, 'float' => 1.5, 'true' => true, 'false' => false, 'string' => "multi\nline", 'list' => [1, [2, 3]], 'zero' => 0];

        foreach ($values as $key => $value) {
            $cache->set($key, $value);
            self::assertSame($value, $cache->get($key, 'miss'), $key);
        }
    }

    #[DataProvider('stores')]
    public function testCachedNullIsAHit(string $type): void
    {
        $cache = $this->cache($type);
        $cache->set('nothing', null);

        self::assertTrue($cache->has('nothing'));
        self::assertNull($cache->get('nothing', 'default'));
    }

    #[DataProvider('stores')]
    public function testTtlExpiry(string $type): void
    {
        $cache = $this->cache($type);
        $cache->set('short', 'v', 60);
        $cache->set('interval', 'v', new DateInterval('PT2M'));
        $cache->set('forever', 'v');

        $this->now += 59;
        self::assertTrue($cache->has('short'));

        $this->now += 1;
        self::assertFalse($cache->has('short'));
        self::assertTrue($cache->has('interval'));

        $this->now += 60;
        self::assertFalse($cache->has('interval'));
        self::assertTrue($cache->has('forever'));
    }

    #[DataProvider('stores')]
    public function testZeroOrNegativeTtlDeletes(string $type): void
    {
        $cache = $this->cache($type);
        $cache->set('a', 'v');
        $cache->set('b', 'v');

        self::assertTrue($cache->set('a', 'new', 0));
        self::assertTrue($cache->set('b', 'new', -5));

        self::assertFalse($cache->has('a'));
        self::assertFalse($cache->has('b'));
    }

    #[DataProvider('stores')]
    public function testDefaultTtlApplies(string $type): void
    {
        $cache = $this->cache($type, defaultTtl: 10);
        $cache->set('k', 'v');

        $this->now += 10;

        self::assertFalse($cache->has('k'));
    }

    #[DataProvider('stores')]
    public function testMultipleOperations(string $type): void
    {
        $cache = $this->cache($type);

        self::assertTrue($cache->setMultiple(['a' => 1, 'b' => 2]));
        self::assertSame(['a' => 1, 'b' => 2, 'c' => 'd'], $cache->getMultiple(['a', 'b', 'c'], 'd'));
        self::assertTrue($cache->deleteMultiple(['a', 'c']));
        self::assertSame(['a' => null, 'b' => 2], $cache->getMultiple(['a', 'b']));
    }

    #[DataProvider('stores')]
    public function testClearOnlyAffectsOwnPrefix(string $type): void
    {
        $store = $this->store($type);
        $app = $this->cache($type, 'app', store: $store);
        $other = $this->cache($type, 'other', store: $store);
        $application = $this->cache($type, 'application', store: $store);

        $app->set('k', 'app');
        $other->set('k', 'other');
        $application->set('k', 'application');

        self::assertTrue($app->clear());

        self::assertFalse($app->has('k'));
        self::assertSame('other', $other->get('k'));
        self::assertSame('application', $application->get('k'), '"app:" must not clear "application:"');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidKeys(): iterable
    {
        foreach (['', 'a{b', 'a}b', 'a(b', 'a)b', 'a/b', 'a\\b', 'a@b', 'a:b'] as $key) {
            yield $key === '' ? 'empty' : $key => [$key];
        }
    }

    #[DataProvider('invalidKeys')]
    public function testInvalidKeysThrowPsrException(string $key): void
    {
        $cache = $this->cache('array');

        foreach ([fn() => $cache->get($key), fn() => $cache->set($key, 1), fn() => $cache->has($key), fn() => $cache->delete($key)] as $call) {
            try {
                $call();
                self::fail('Expected InvalidArgumentException.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testSetMultipleRejectsNonStringKeys(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->cache('array')->setMultiple([5 => 'x']);
    }

    // remember()

    #[DataProvider('stores')]
    public function testRememberComputesOnceAndCachesNull(string $type): void
    {
        $cache = $this->cache($type);
        $calls = 0;
        $compute = static function () use (&$calls): ?string {
            $calls++;

            return null;
        };

        self::assertNull($cache->remember('k', 60, $compute));
        self::assertNull($cache->remember('k', 60, $compute));
        self::assertSame(1, $calls, 'a cached null must not be recomputed');
    }

    #[DataProvider('stores')]
    public function testRememberRecomputesAfterExpiry(string $type): void
    {
        $cache = $this->cache($type);
        $calls = new \ArrayObject();
        $compute = static function () use ($calls): int {
            $calls->append(true);

            return $calls->count();
        };

        self::assertSame(1, $cache->remember('k', 30, $compute));
        $this->now += 31;
        self::assertSame(2, $cache->remember('k', 30, $compute));
        $first = $cache->rememberForever('forever', $compute);
        $this->now += 1_000_000;
        $later = $cache->rememberForever('forever', $compute);

        self::assertSame(3, $first);
        self::assertSame(3, $later, 'rememberForever() must not expire');
        self::assertCount(3, $calls);
    }

    #[DataProvider('stores')]
    public function testRememberReleasesLockWhenCallbackThrows(string $type): void
    {
        $cache = $this->cache($type);

        try {
            $cache->remember('k', 60, static fn(): never => throw new RuntimeException('boom'));
            self::fail('Expected exception.');
        } catch (RuntimeException) {
        }

        // The lock was released, so the next call computes immediately instead of waiting.
        $start = hrtime(true);
        self::assertSame('ok', $cache->remember('k', 60, static fn(): string => 'ok'));
        self::assertLessThan(500, (hrtime(true) - $start) / 1e6);
    }

    #[DataProvider('stores')]
    public function testRememberComputesItselfWhenLockIsHeldTooLong(string $type): void
    {
        $store = $this->store($type);
        self::assertInstanceOf(\VeyroPHP\Cache\LockingStore::class, $store);
        self::assertNotNull($store->acquireLock('app:k', 60), 'simulate another process holding the lock');

        $cache = new Cache($store, 'app', null, 10, 60, fn(): int => $this->now);

        self::assertSame('computed', $cache->remember('k', 60, static fn(): string => 'computed'));
    }

    #[DataProvider('stores')]
    public function testLocksAreExclusiveExpireAndNeedTheirToken(string $type): void
    {
        $store = $this->store($type);
        self::assertInstanceOf(\VeyroPHP\Cache\LockingStore::class, $store);

        $first = $store->acquireLock('job', 30);
        self::assertNotNull($first);

        $whileHeld = $store->acquireLock('job', 30);
        self::assertNull($whileHeld);

        $store->releaseLock('job', 'wrong-token');
        $afterWrongRelease = $store->acquireLock('job', 30);
        self::assertNull($afterWrongRelease, 'a wrong token must not release the lock');

        $this->now += 31;
        $afterExpiry = $store->acquireLock('job', 30);
        self::assertNotNull($afterExpiry, 'an expired lock can be taken over');

        $store->releaseLock('job', $afterExpiry);
        $afterRelease = $store->acquireLock('job', 30);
        self::assertNotNull($afterRelease);
    }

    // FileStore specifics

    public function testFileStoreTreatsCorruptFilesAsMissesAndDeletesThem(): void
    {
        $cache = $this->cache('file');
        $cache->set('k', 'v');

        $files = glob($this->dir . '/*/*.cache') ?: [];
        self::assertCount(1, $files);
        file_put_contents($files[0], 'not serialized data');

        self::assertSame('miss', $cache->get('k', 'miss'));
        self::assertFileDoesNotExist($files[0]);
    }

    public function testFileStoreDoesNotRestoreObjectsByDefault(): void
    {
        $object = new stdClass();
        $object->name = 'x';

        $cache = $this->cache('file');
        $cache->set('obj', $object);

        self::assertInstanceOf(\__PHP_Incomplete_Class::class, $cache->get('obj'));
    }

    public function testFileStoreRestoresAllowedClasses(): void
    {
        $object = new stdClass();
        $object->name = 'x';

        $cache = new Cache(new FileStore($this->dir, [stdClass::class]));
        $cache->set('obj', $object);

        self::assertEquals($object, $cache->get('obj'));
    }

    public function testFileStoreRejectsUnserializableValues(): void
    {
        self::assertFalse($this->cache('file')->set('closure', static fn() => 1));
    }

    public function testFileStoreLeavesNoTemporaryFiles(): void
    {
        $cache = $this->cache('file');
        for ($i = 0; $i < 20; $i++) {
            $cache->set('k' . $i, str_repeat('x', 1000));
        }

        self::assertSame([], glob($this->dir . '/*/*.tmp') ?: []);
        self::assertCount(20, glob($this->dir . '/*/*.cache') ?: []);
    }

    public function testClearRemovesReleasedLockFilesButKeepsHeldOnes(): void
    {
        $store = new FileStore($this->dir, false, fn(): int => $this->now);
        $cache = new Cache($store, 'app', null, 10, 3000, fn(): int => $this->now);

        $cache->remember('a', 60, static fn(): string => 'x');    // lock taken and released
        $held = $store->acquireLock('app:b', 60);                    // still held
        self::assertNotNull($held);
        $otherPrefix = $store->acquireLock('other:c', 1);
        self::assertNotNull($otherPrefix);
        $store->releaseLock('other:c', $otherPrefix);

        $cache->clear();

        $remaining = glob($this->dir . '/*/*') ?: [];
        self::assertCount(2, $remaining, 'only the held lock and the other prefix\'s lock remain');
        self::assertSame([], glob($this->dir . '/*/*.cache') ?: []);
    }

    public function testFileStoreWriteFailureReturnsFalse(): void
    {
        // The cache directory path is an existing file, so nothing can be written below it.
        file_put_contents($this->dir, 'x');

        try {
            self::assertFalse((new Cache(new FileStore($this->dir)))->set('k', 'v'));
            self::assertSame('miss', (new Cache(new FileStore($this->dir)))->get('k', 'miss'));
        } finally {
            unlink($this->dir);
        }
    }

    private static function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            is_dir($path) ? self::removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }
}
