<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Integration\Cache;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use VeyroPHP\Cache\Cache;
use VeyroPHP\Cache\FileStore;

/**
 * Starts several PHP processes at once that all call remember() on the same
 * missing key with a slow callback. With locking, the callback runs once.
 */
#[CoversClass(Cache::class)]
#[CoversClass(FileStore::class)]
final class CacheStampedeTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'veyrophp-stampede-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        self::removeDirectory($this->dir);
    }

    private static function removeDirectory(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            is_dir($path) ? self::removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }

    public function testConcurrentRememberRunsCallbackOnce(): void
    {
        $processes = 5;
        $script = $this->dir . DIRECTORY_SEPARATOR . 'worker.php';
        $counter = $this->dir . DIRECTORY_SEPARATOR . 'calls.log';
        $autoload = var_export(dirname(__DIR__, 3) . '/vendor/autoload.php', true);
        $cacheDir = var_export($this->dir . DIRECTORY_SEPARATOR . 'cache', true);
        $counterExport = var_export($counter, true);

        file_put_contents($script, <<<PHP
            <?php
            require {$autoload};
            \$cache = new VeyroPHP\\Cache\\Cache(new VeyroPHP\\Cache\\FileStore({$cacheDir}), 'app', null, 10, 5000);
            echo \$cache->remember('report', 60, static function (): string {
                file_put_contents({$counterExport}, "x", FILE_APPEND | LOCK_EX);
                usleep(400000); // an expensive computation
                return 'report-data';
            });
            PHP);

        $handles = [];
        for ($i = 0; $i < $processes; $i++) {
            $process = proc_open([PHP_BINARY, $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            $handles[] = [$process, $pipes];
        }

        $outputs = [];
        foreach ($handles as [$process, $pipes]) {
            $outputs[] = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), 'worker failed: ' . $errors);
        }

        self::assertSame(array_fill(0, $processes, 'report-data'), $outputs);
        self::assertSame('x', (string) file_get_contents($counter), 'the expensive callback must run exactly once');
    }
}
