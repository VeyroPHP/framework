<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Unit\Logging;

use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LogLevel;
use RuntimeException;
use stdClass;
use Stringable;
use VeyroPHP\Exception\ThrowableFormatter;
use VeyroPHP\Logging\Exception\LoggingException;
use VeyroPHP\Logging\FileLogger;
use VeyroPHP\Logging\Redactor;

#[CoversClass(FileLogger::class)]
#[CoversClass(LoggingException::class)]
#[CoversClass(ThrowableFormatter::class)]
#[CoversClass(Redactor::class)]
final class FileLoggerTest extends TestCase
{
    private string $dir;

    private string $file;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'veyrophp-log-' . bin2hex(random_bytes(6));
        $this->file = $this->dir . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'app.log';
    }

    protected function tearDown(): void
    {
        if (is_file($this->file)) {
            unlink($this->file);
        }

        foreach ([dirname($this->file), $this->dir] as $dir) {
            if (is_dir($dir)) {
                rmdir($dir);
            }
        }
    }

    private function logger(string $level = LogLevel::DEBUG, string $channel = 'app'): FileLogger
    {
        return new FileLogger(
            $this->file,
            $level,
            $channel,
            new Redactor(),
            static fn(): DateTimeImmutable => new DateTimeImmutable('2026-10-25T10:15:00.123456+00:00'),
        );
    }

    /**
     * @return list<string>
     */
    private function lines(): array
    {
        $contents = file_get_contents($this->file);
        self::assertIsString($contents);

        return explode("\n", rtrim($contents, "\n"));
    }

    public function testWritesFormattedLineAndCreatesDirectory(): void
    {
        $this->logger()->error('Payment failed');

        self::assertSame(['[2026-10-25T10:15:00.123456+00:00] app.ERROR: Payment failed'], $this->lines());
    }

    public function testAppendsEntries(): void
    {
        $logger = $this->logger();
        $logger->info('first');
        $logger->warning('second');

        self::assertCount(2, $this->lines());
        self::assertStringEndsWith('app.WARNING: second', $this->lines()[1]);
    }

    public function testAppendsContextAsJson(): void
    {
        $this->logger()->info('Order placed', ['order' => 42, 'total' => 9.5, 'paid' => true, 'note' => null, 'url' => 'https://x.test/a']);

        self::assertStringEndsWith(
            'Order placed {"order":42,"total":9.5,"paid":true,"note":null,"url":"https://x.test/a"}',
            $this->lines()[0],
        );
    }

    public function testDropsEntriesBelowMinimumLevel(): void
    {
        $logger = $this->logger(LogLevel::WARNING);
        $logger->debug('hidden');
        $logger->info('hidden');
        $logger->notice('hidden');
        $logger->warning('shown');
        $logger->emergency('shown');

        self::assertCount(2, $this->lines());
    }

    public function testDoesNotCreateFileWhenNothingIsLogged(): void
    {
        $this->logger(LogLevel::ERROR)->debug('hidden');

        self::assertFileDoesNotExist($this->file);
    }

    public function testInterpolatesPlaceholders(): void
    {
        $stringable = new class implements Stringable {
            public function __toString(): string
            {
                return 'str';
            }
        };

        $this->logger()->info(
            'User {user} at {when}: {flag} {nothing} {obj} {list} {missing} {s}',
            [
                'user' => 'ravi',
                'when' => new DateTimeImmutable('2026-01-02T03:04:05+00:00'),
                'flag' => false,
                'nothing' => null,
                'obj' => new stdClass(),
                'list' => [1, 2],
                's' => $stringable,
            ],
        );

        self::assertStringContainsString(
            'User ravi at 2026-01-02T03:04:05+00:00: false null {obj} {list} {missing} str',
            $this->lines()[0],
        );
    }

    public function testEscapesLineBreaksToPreventForgedEntries(): void
    {
        $this->logger()->warning("Login failed for {user}\n[2026-01-01] app.INFO: fake entry", ['user' => "evil\r\nuser"]);

        self::assertCount(1, $this->lines());
        self::assertStringContainsString('Login failed for evil\r\nuser\n[2026-01-01] app.INFO: fake entry', $this->lines()[0]);
    }

    public function testRedactsSensitiveContextAndPlaceholders(): void
    {
        $this->logger()->info('Login with {password}', ['user' => 'ravi', 'password' => 'hunter2', 'nested' => ['api_key' => 'k-123']]);

        $line = $this->lines()[0];
        self::assertStringNotContainsString('hunter2', $line);
        self::assertStringNotContainsString('k-123', $line);
        self::assertStringContainsString('Login with [REDACTED]', $line);
        self::assertStringContainsString('"api_key":"[REDACTED]"', $line);
    }

    public function testLogsExceptionWithTraceButWithoutArguments(): void
    {
        $exception = self::throwWithSecretArgument('top-secret-arg');

        $this->logger()->error('Failed', ['exception' => $exception]);

        $line = $this->lines()[0];
        self::assertStringContainsString('"class":"RuntimeException"', $line);
        self::assertStringContainsString('"message":"outer"', $line);
        self::assertStringContainsString('"previous":{"class":"LogicException","message":"inner"', $line);
        self::assertStringContainsString('throwWithSecretArgument()', $line);
        self::assertStringNotContainsString('top-secret-arg', $line);
    }

    public function testNormalizesUnserializableValues(): void
    {
        $resource = fopen('php://memory', 'r');
        self::assertIsResource($resource);

        $this->logger()->info('values', ['obj' => new stdClass(), 'res' => $resource, 'nan' => NAN, 'bad' => "\xB1\x31"]);
        fclose($resource);

        $line = $this->lines()[0];
        self::assertStringContainsString('"obj":"[object stdClass]"', $line);
        self::assertStringContainsString('"res":"[resource stream]"', $line);
        self::assertStringContainsString('"nan":"NAN"', $line);
        self::assertStringContainsString('"bad":"', $line);
    }

    public function testLimitsContextDepth(): void
    {
        $deep = ['v' => 'bottom'];
        for ($i = 0; $i < 15; $i++) {
            $deep = ['d' => $deep];
        }

        $this->logger()->info('deep', $deep);

        self::assertStringContainsString('[max depth reached]', $this->lines()[0]);
        self::assertStringNotContainsString('bottom', $this->lines()[0]);
    }

    public function testRejectsUnknownLevel(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->logger()->log('verbose', 'x');
    }

    public function testRejectsNonStringLevel(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->logger()->log(3, 'x');
    }

    public function testRejectsUnknownMinimumLevel(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->logger('verbose');
    }

    public function testRejectsInvalidChannel(): void
    {
        $this->expectException(LoggingException::class);

        $this->logger(LogLevel::DEBUG, "app\nfake");
    }

    public function testUsesChannelName(): void
    {
        $this->logger(LogLevel::DEBUG, 'billing')->info('x');

        self::assertStringContainsString('] billing.INFO: x', $this->lines()[0]);
    }

    public function testWriteFailureThrows(): void
    {
        // The log path is an existing directory, so it cannot be opened as a file.
        self::assertTrue(mkdir($this->file, 0777, true));

        try {
            $this->expectException(LoggingException::class);
            $this->expectExceptionMessage('Cannot write to log file');

            $this->logger()->error('x');
        } finally {
            rmdir($this->file);
        }
    }

    public function testDefaultClockProducesCurrentTimestamp(): void
    {
        (new FileLogger($this->file))->info('now');

        self::assertMatchesRegularExpression('/^\[\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}[+-]\d{2}:\d{2}\] app\.INFO: now$/', $this->lines()[0]);
    }

    private static function throwWithSecretArgument(string $secret): RuntimeException
    {
        try {
            throw new LogicException('inner');
        } catch (LogicException $e) {
            return new RuntimeException('outer', 0, $e);
        }
    }
}
