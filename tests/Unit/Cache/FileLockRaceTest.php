<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Unit\Cache;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use VeyroPHP\Cache\FileStore;

/**
 * MISC-1: removing lock files must never let two holders own one lock. The other
 * process is played by a second handle on the same file, which flock() treats like
 * another process on Unix and Windows alike.
 */
#[CoversClass(FileStore::class)]
final class FileLockRaceTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'veyrophp-lock-race-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*/*') ?: [] as $file) {
            @unlink($file);
        }
        foreach (glob($this->directory . '/*', GLOB_ONLYDIR) ?: [] as $directory) {
            @rmdir($directory);
        }
        @rmdir($this->directory);
    }

    private function lockFile(string $name): string
    {
        $hash = hash('sha256', 'lock:' . $name);

        return $this->directory . DIRECTORY_SEPARATOR . substr($hash, 0, 2) . DIRECTORY_SEPARATOR . $hash . '.cache.lock';
    }

    /**
     * A released lock: its file stays, with an expiry in the past.
     */
    private function releasedLock(FileStore $store, string $name): string
    {
        $token = $store->acquireLock($name, 10);
        self::assertNotNull($token);
        $store->releaseLock($name, $token);
        self::assertFileExists($this->lockFile($name));

        return $this->lockFile($name);
    }

    public function testPruneAndClearLeaveALockFileSomeoneIsAcquiring(): void
    {
        $store = new FileStore($this->directory);
        $file = $this->releasedLock($store, 'reports');

        // Another process is between flock() and writing its token.
        $other = fopen($file, 'r+');
        self::assertIsResource($other);
        self::assertTrue(flock($other, LOCK_EX | LOCK_NB));

        self::assertSame(0, $store->prune());
        self::assertTrue($store->clear(''));
        self::assertFileExists($file, 'a locked lock file is never removed');

        flock($other, LOCK_UN);
        fclose($other);

        self::assertSame(1, $store->prune(), 'once free, the released file is removed');
        self::assertFileDoesNotExist($file);
    }

    public function testAnAcquireWhoseFileWasRemovedMeanwhileDoesNotCount(): void
    {
        $file = $this->releasedLock(new FileStore($this->directory), 'reports');
        $second = new FileStore($this->directory);
        $secondToken = null;
        $interleaved = false;

        // The first acquirer holds flock() and has read the expired content; now an
        // old-style prune removes the file and a second process takes the lock.
        $first = new FileStore($this->directory, clock: static function () use (&$interleaved, &$secondToken, $second, $file): int {
            if (!$interleaved) {
                $interleaved = true;
                @unlink($file);
                $secondToken = $second->acquireLock('reports', 30);
            }

            return time();
        });

        $firstToken = $first->acquireLock('reports', 30);

        self::assertTrue($interleaved);
        self::assertNull($firstToken, 'a lock written into a removed file is not a lock');

        // Unix: the second process created a new file and holds the lock. Windows: the
        // removed file blocked the path until the first handle closed; it can now be taken.
        $holder = $secondToken ?? (new FileStore($this->directory))->acquireLock('reports', 30);
        self::assertNotNull($holder, 'the lock is not lost');
        self::assertNull((new FileStore($this->directory))->acquireLock('reports', 30), 'and it has exactly one holder');
    }

    public function testHeldLocksSurvivePruneAndClear(): void
    {
        $store = new FileStore($this->directory);
        $token = $store->acquireLock('reports', 30);
        self::assertNotNull($token);

        $store->prune();
        $store->clear('');

        self::assertNull($store->acquireLock('reports', 30));
        $store->releaseLock('reports', $token);
        self::assertNotNull($store->acquireLock('reports', 30));
    }

    /**
     * Found while verifying R5: a long-running process (queue worker) has seen a cache
     * directory; another process (cron "cache:prune") removes it once it is empty. PHP's
     * stat cache then still answered "the directory exists", so the worker never created
     * it again and could not take that lock or write that entry for the rest of its life.
     */
    public function testAProcessKeepsWorkingAfterAnotherProcessRemovedItsDirectory(): void
    {
        $store = new FileStore($this->directory);
        self::assertTrue($store->put('greeting', 'hello', null));
        self::assertNotNull($store->acquireLock('reports', 30));

        // A refused attempt: as in a worker that keeps trying, the last path this process
        // looked at is the lock's directory.
        self::assertNull($store->acquireLock('reports', 30));

        // Another process removes everything, including the now empty directories.
        // Single quotes only: escapeshellarg() on Windows removes double quotes.
        $script = "foreach (glob(\$argv[1] . '/*/*') as \$f) { unlink(\$f); } foreach (glob(\$argv[1] . '/*', GLOB_ONLYDIR) as \$d) { rmdir(\$d); }";
        shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($script) . ' ' . escapeshellarg($this->directory));
        self::assertSame([], glob($this->directory . '/*') ?: [], 'the other process removed the directories');

        self::assertNotNull($store->acquireLock('reports', 30), 'the lock can be taken again');
        self::assertTrue($store->put('greeting', 'hello again', null), 'the entry can be written again');
        self::assertSame(['hello again'], $store->fetch('greeting'));
    }
}
