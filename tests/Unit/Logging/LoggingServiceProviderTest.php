<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Unit\Logging;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use VeyroPHP\Application\Application;
use VeyroPHP\Config\Config;
use VeyroPHP\Logging\FileLogger;
use VeyroPHP\Logging\LoggingServiceProvider;

#[CoversClass(LoggingServiceProvider::class)]
final class LoggingServiceProviderTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $resolved = realpath(sys_get_temp_dir());
        self::assertIsString($resolved);
        $this->root = $resolved . DIRECTORY_SEPARATOR . 'veyrophp-logprov-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->root));
    }

    protected function tearDown(): void
    {
        self::removeDirectory($this->root);
    }

    public function testDefaultsWriteToStorageLogs(): void
    {
        $app = new Application($this->root);
        $app->register(new LoggingServiceProvider());

        $logger = $app->container()->make(LoggerInterface::class);
        self::assertInstanceOf(FileLogger::class, $logger);
        self::assertSame($logger, $app->container()->get(LoggerInterface::class));

        $logger->debug('hello');

        $file = $this->root . '/storage/logs/veyrophp.log';
        self::assertFileExists($file);
        self::assertStringContainsString('app.DEBUG: hello', (string) file_get_contents($file));
    }

    public function testUsesConfiguredPathLevelChannelAndRedaction(): void
    {
        $app = new Application($this->root);
        $app->container()->instance(Config::class, new Config(['logging' => [
            'path' => 'var/custom.log',
            'level' => 'warning',
            'channel' => 'shop',
            'redact' => ['aadhaar'],
        ]]));
        $app->register(new LoggingServiceProvider());

        $logger = $app->container()->make(LoggerInterface::class);
        $logger->info('dropped');
        $logger->warning('kept', ['aadhaar_no' => '1234']);

        $contents = (string) file_get_contents($this->root . '/var/custom.log');
        self::assertStringNotContainsString('dropped', $contents);
        self::assertStringContainsString('shop.WARNING: kept {"aadhaar_no":"[REDACTED]"}', $contents);
    }

    public function testDaysSwitchesToDailyFiles(): void
    {
        $app = new Application($this->root);
        $app->container()->instance(Config::class, new Config(['logging' => ['days' => 14]]));
        $app->register(new LoggingServiceProvider());

        $app->container()->make(LoggerInterface::class)->error('daily');

        self::assertFileExists($this->root . '/storage/logs/veyrophp-' . date('Y-m-d') . '.log');
        self::assertFileDoesNotExist($this->root . '/storage/logs/veyrophp.log');
    }

    public function testAcceptsAbsolutePath(): void
    {
        $absolute = $this->root . DIRECTORY_SEPARATOR . 'abs' . DIRECTORY_SEPARATOR . 'x.log';
        $app = new Application($this->root);
        $app->container()->instance(Config::class, new Config(['logging' => ['path' => $absolute]]));
        $app->register(new LoggingServiceProvider());

        $app->container()->make(LoggerInterface::class)->error('abs');

        self::assertFileExists($absolute);
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
}
