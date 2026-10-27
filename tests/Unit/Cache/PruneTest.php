<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Unit\Cache;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use VeyroPHP\Cache\ArrayStore;
use VeyroPHP\Cache\Cache;
use VeyroPHP\Cache\Console\PruneCommand;
use VeyroPHP\Cache\FileStore;
use VeyroPHP\Cache\Store;

#[CoversClass(FileStore::class)]
#[CoversClass(ArrayStore::class)]
#[CoversClass(Cache::class)]
#[CoversClass(PruneCommand::class)]
final class PruneTest extends TestCase
{
    private string $dir;

    private int $now = 1_000_000;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'veyrophp-prune-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*/*') ?: [] as $file) {
            unlink($file);
        }
        foreach (glob($this->dir . '/*', GLOB_ONLYDIR) ?: [] as $directory) {
            rmdir($directory);
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    private function fileCache(): Cache
    {
        return new Cache(new FileStore($this->dir, false, fn(): int => $this->now), 'app', null, 10, 50, fn(): int => $this->now);
    }

    public function testFileStoreRemovesOnlyExpiredEntries(): void
    {
        $cache = $this->fileCache();
        $cache->set('session.gone', 'a', 60);
        $cache->set('session.alive', 'b', 600);
        $cache->set('forever', 'c');

        $this->now += 120;

        self::assertSame(1, $cache->prune());
        self::assertSame('b', $cache->get('session.alive'));
        self::assertSame('c', $cache->get('forever'));
        self::assertCount(2, glob($this->dir . '/*/*.cache') ?: []);
        self::assertSame(0, $cache->prune(), 'nothing left to prune');
    }

    public function testExpiredEntriesNobodyReadsWouldOtherwiseStayOnDisk(): void
    {
        $cache = $this->fileCache();
        for ($i = 0; $i < 5; $i++) {
            $cache->set('visitor.' . $i, 'session', 60);
        }

        $this->now += 61;

        // Without prune the files are still there; reads are what normally delete them.
        self::assertCount(5, glob($this->dir . '/*/*.cache') ?: []);
        self::assertSame(5, $cache->prune());
        self::assertSame([], glob($this->dir . '/*/*.cache') ?: []);
        self::assertSame([], glob($this->dir . '/*', GLOB_ONLYDIR) ?: [], 'empty bucket directories are removed');
    }

    public function testCorruptFilesStaleTempFilesAndReleasedLocksAreRemoved(): void
    {
        $store = new FileStore($this->dir, false, fn(): int => $this->now);
        $store->put('keep', 'v', null);

        mkdir($this->dir . '/zz', 0777, true);
        file_put_contents($this->dir . '/zz/broken.cache', 'not serialized');
        file_put_contents($this->dir . '/zz/fresh.cache.abc.tmp', 'being written');
        file_put_contents($this->dir . '/zz/stale.cache.def.tmp', 'crash leftover');
        touch($this->dir . '/zz/stale.cache.def.tmp', $this->now - 7200);
        touch($this->dir . '/zz/fresh.cache.abc.tmp', $this->now - 10);

        $held = $store->acquireLock('held', 300);
        $released = $store->acquireLock('released', 300);
        self::assertNotNull($held);
        self::assertNotNull($released);
        $store->releaseLock('released', $released);

        // broken entry + stale tmp + released lock
        self::assertSame(3, $store->prune());

        self::assertSame(['v'], $store->fetch('keep'));
        self::assertFileExists($this->dir . '/zz/fresh.cache.abc.tmp');
        self::assertFileDoesNotExist($this->dir . '/zz/stale.cache.def.tmp');
        self::assertNull($store->acquireLock('held', 300), 'a held lock must survive pruning');
    }

    public function testPruneDoesNotRestoreObjects(): void
    {
        $store = new FileStore($this->dir, true, fn(): int => $this->now);
        $store->put('object', new \ArrayObject([1]), $this->now - 1);

        // An expired entry containing an object is removed without instantiating it.
        self::assertSame(1, $store->prune());
    }

    public function testArrayStoreAndMissingDirectory(): void
    {
        $array = new ArrayStore(fn(): int => $this->now);
        $array->put('a', 1, $this->now + 10);
        $array->put('b', 2, $this->now + 100);
        $array->put('c', 3, null);
        $array->acquireLock('l', 5);

        $this->now += 20;

        self::assertSame(2, $array->prune()); // entry "a" and the expired lock
        self::assertSame([2], $array->fetch('b'));
        self::assertSame(0, (new FileStore($this->dir . '/missing'))->prune());
    }

    public function testStoresWithoutPruningReturnZero(): void
    {
        $plain = new class implements Store {
            public function fetch(string $key): ?array
            {
                return null;
            }

            public function put(string $key, mixed $value, ?int $expiresAt): bool
            {
                return true;
            }

            public function delete(string $key): bool
            {
                return true;
            }

            public function clear(string $prefix): bool
            {
                return true;
            }
        };

        self::assertSame(0, (new Cache($plain))->prune());
    }
}
