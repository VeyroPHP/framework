<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Unit\Logging;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use VeyroPHP\Logging\FileLogger;
use VeyroPHP\Logging\Redactor;

/**
 * VQ-065: optional daily files with a retention in days, and safety under external
 * rotation (logrotate moves the file away; the next entry starts a new one).
 */
#[CoversClass(FileLogger::class)]
final class FileLoggerRotationTest extends TestCase
{
    private string $dir;

    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'veyrophp-logrotate-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
        $this->now = new DateTimeImmutable('2026-10-27T10:00:00+05:30');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->dir);
    }

    private function logger(int $keepDays): FileLogger
    {
        return new FileLogger($this->dir . DIRECTORY_SEPARATOR . 'veyrophp.log', LogLevel::DEBUG, 'app', new Redactor(), fn(): DateTimeImmutable => $this->now, $keepDays);
    }

    /**
     * @return list<string>
     */
    private function files(): array
    {
        $names = array_map('basename', glob($this->dir . DIRECTORY_SEPARATOR . '*') ?: []);
        sort($names);

        return $names;
    }

    public function testWithoutRetentionThereIsOneFile(): void
    {
        $this->logger(0)->info('one');

        self::assertSame(['veyrophp.log'], $this->files());
    }

    public function testDailyFilesUseTheDateOfTheEntry(): void
    {
        $logger = $this->logger(14);
        $logger->info('first day');
        $this->now = $this->now->modify('+1 day');
        $logger->info('second day');

        self::assertSame(['veyrophp-2026-10-27.log', 'veyrophp-2026-10-28.log'], $this->files());
        self::assertStringContainsString('second day', (string) file_get_contents($this->dir . '/veyrophp-2026-10-28.log'));
    }

    public function testOnlyTheLastDaysAreKeptAndOtherFilesAreNotTouched(): void
    {
        foreach (['veyrophp-2026-10-20.log', 'veyrophp-2026-10-24.log', 'veyrophp-2026-10-25.log', 'veyrophp-2026-10-26.log', 'veyrophp.log', 'other-2026-10-01.log', 'veyrophp-2026-10-01.log.gz', 'veyrophp-notadate.log'] as $name) {
            file_put_contents($this->dir . DIRECTORY_SEPARATOR . $name, 'old');
        }

        // Keep 3 days: today (27th), 26th and 25th.
        $this->logger(3)->info('today');

        self::assertSame([
            'other-2026-10-01.log',
            'veyrophp-2026-10-01.log.gz',
            'veyrophp-2026-10-25.log',
            'veyrophp-2026-10-26.log',
            'veyrophp-2026-10-27.log',
            'veyrophp-notadate.log',
            'veyrophp.log',
        ], $this->files());
    }

    public function testRetentionWorksWhenTheFileNameContainsDate(): void
    {
        file_put_contents($this->dir . DIRECTORY_SEPARATOR . 'UPDATES-2026-10-01.log', 'old');
        $logger = new FileLogger($this->dir . DIRECTORY_SEPARATOR . 'UPDATES.log', LogLevel::DEBUG, 'app', new Redactor(), fn(): DateTimeImmutable => $this->now, 3);

        $logger->info('today');

        self::assertSame(['UPDATES-2026-10-27.log'], $this->files());
    }

    public function testAHugeEntryIsCutWithAMarker(): void
    {
        $this->logger(0)->error('big', ['payload' => str_repeat('x', 2 * FileLogger::MAX_ENTRY_BYTES)]);
        $this->logger(0)->info('next');

        $lines = file($this->dir . '/veyrophp.log', FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines);
        self::assertCount(2, $lines, 'the cut entry still ends with a newline');
        self::assertLessThanOrEqual(FileLogger::MAX_ENTRY_BYTES, strlen($lines[0]) + 1);
        self::assertMatchesRegularExpression('/ \[truncated \d+ bytes\]$/', $lines[0]);
        self::assertStringStartsWith('[2026-10-27T10:00:00.000000+05:30] app.ERROR: big', $lines[0]);
        self::assertStringContainsString('app.INFO: next', $lines[1]);
    }

    public function testACutNeverSplitsAUtf8Character(): void
    {
        $this->logger(0)->error(str_repeat('अ', FileLogger::MAX_ENTRY_BYTES));

        $line = (string) file_get_contents($this->dir . '/veyrophp.log');
        self::assertTrue(mb_check_encoding($line, 'UTF-8'));
    }

    public function testNegativeRetentionIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->logger(-1);
    }

    public function testExternalRotationIsSafeBecauseEveryEntryReopensThePath(): void
    {
        $logger = $this->logger(0);
        $logger->info('before rotation');
        rename($this->dir . '/veyrophp.log', $this->dir . '/veyrophp.log.1');

        $logger->info('after rotation');

        self::assertSame(['veyrophp.log', 'veyrophp.log.1'], $this->files());
        self::assertStringContainsString('after rotation', (string) file_get_contents($this->dir . '/veyrophp.log'));
        self::assertStringNotContainsString('after rotation', (string) file_get_contents($this->dir . '/veyrophp.log.1'));
    }
}
