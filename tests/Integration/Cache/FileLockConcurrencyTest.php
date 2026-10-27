<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Integration\Cache;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use VeyroPHP\Cache\FileStore;

/**
 * MISC-1 with real processes: several processes take one file lock again and again
 * while another removes released lock files as fast as it can. No moment may have two
 * holders.
 */
#[CoversClass(FileStore::class)]
final class FileLockConcurrencyTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open() is disabled.');
        }

        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'veyrophp-lock-procs-' . bin2hex(random_bytes(4));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach ([...(glob($this->directory . '/*/*') ?: []), ...(glob($this->directory . '/*') ?: [])] as $path) {
            is_dir($path) ? @rmdir($path) : @unlink($path);
        }
        @rmdir($this->directory);
    }

    public function testConcurrentProcessesNeverHoldTheSameLockTogether(): void
    {
        $go = $this->directory . DIRECTORY_SEPARATOR . 'go';
        $processes = [];

        foreach (['acquire', 'acquire', 'acquire', 'acquire', 'prune'] as $mode) {
            $process = proc_open(
                [PHP_BINARY, dirname(__DIR__, 2) . '/Fixtures/Cache/lock-worker.php', $this->directory, $go, '2000', $mode],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );
            self::assertIsResource($process);
            $processes[] = [$mode, $process, $pipes];
        }

        touch($go);
        $held = [];
        $violations = 0;
        $removed = 0;

        foreach ($processes as [$mode, $process, $pipes]) {
            $output = (string) stream_get_contents($pipes[1]);
            $errors = (string) stream_get_contents($pipes[2]);
            self::assertSame(0, proc_close($process), $mode . ' process failed: ' . $errors);

            if ($mode === 'prune') {
                $removed = (int) substr(trim($output), strlen('removed '));

                continue;
            }

            $violations += substr_count($output, 'VIOLATION');
            $held[] = substr_count($output, 'held');
        }

        self::assertSame(0, $violations, 'two processes held the lock at the same time');
        self::assertGreaterThan(1, count(array_filter($held)), 'more than one process got the lock (otherwise this proves nothing); holds per process: ' . implode(', ', $held));
        self::assertGreaterThan(0, $removed, 'released lock files were removed while others raced (otherwise this proves nothing)');
    }
}
