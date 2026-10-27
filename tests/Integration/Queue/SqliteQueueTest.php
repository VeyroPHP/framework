<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Integration\Queue;

use PHPUnit\Framework\Attributes\CoversClass;
use VeyroPHP\Queue\Driver\DatabaseDriver;
use VeyroPHP\Queue\Failed\DatabaseFailedJobStore;
use VeyroPHP\Queue\QueueSchema;

#[CoversClass(DatabaseDriver::class)]
#[CoversClass(DatabaseFailedJobStore::class)]
#[CoversClass(QueueSchema::class)]
final class SqliteQueueTest extends DatabaseQueueTestCase
{
    private static ?string $file = null;

    protected static function settings(): array
    {
        self::$file ??= sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'veyrophp-queue-' . bin2hex(random_bytes(4)) . '.sqlite';

        return ['driver' => 'sqlite', 'database' => self::$file];
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$file !== null && is_file(self::$file)) {
            unlink(self::$file);
        }
    }
}
