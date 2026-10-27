<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Unit\Exception;

use ErrorException;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use VeyroPHP\Exception\ErrorHandler;
use VeyroPHP\Exception\Renderer\HtmlRenderer;
use VeyroPHP\Exception\Renderer\PlainTextRenderer;
use VeyroPHP\Exception\ThrowableFormatter;
use VeyroPHP\Tests\Fixtures\Logging\ArrayLogger;
use VeyroPHP\Tests\Fixtures\Logging\FailingLogger;

#[CoversClass(ErrorHandler::class)]
#[CoversClass(PlainTextRenderer::class)]
#[CoversClass(HtmlRenderer::class)]
#[CoversClass(ThrowableFormatter::class)]
final class ErrorHandlerTest extends TestCase
{
    private ArrayLogger $logger;

    /** @var list<array{string, string, int}> */
    private array $emitted = [];

    private int $originalErrorReporting;

    protected function setUp(): void
    {
        $this->logger = new ArrayLogger();
        $this->emitted = [];

        // PHPUnit lowers error_reporting() while a test runs; report everything like a normal process.
        $this->originalErrorReporting = error_reporting(E_ALL);
    }

    protected function tearDown(): void
    {
        error_reporting($this->originalErrorReporting);
    }

    private function handler(bool $debug = false, ?\Psr\Log\LoggerInterface $logger = null): ErrorHandler
    {
        return new ErrorHandler(
            $logger ?? $this->logger,
            $debug,
            new PlainTextRenderer(),
            function (string $output, string $contentType, int $status): void {
                $this->emitted[] = [$output, $contentType, $status];
            },
        );
    }

    public function testConvertsReportedErrorToErrorException(): void
    {
        try {
            $this->handler()->handleError(E_WARNING, 'Undefined variable $x', 'file.php', 12);
            self::fail('Expected an ErrorException.');
        } catch (ErrorException $e) {
            self::assertSame('Undefined variable $x', $e->getMessage());
            self::assertSame(E_WARNING, $e->getSeverity());
            self::assertSame('file.php', $e->getFile());
            self::assertSame(12, $e->getLine());
        }
    }

    public function testIgnoresErrorsExcludedFromErrorReporting(): void
    {
        $previous = error_reporting(E_ALL & ~E_NOTICE);

        try {
            self::assertFalse($this->handler()->handleError(E_NOTICE, 'ignored'));
        } finally {
            error_reporting($previous);
        }
    }

    public function testIgnoresSilencedErrors(): void
    {
        // "@" sets error_reporting() to a mask that excludes warnings while the expression runs.
        $previous = error_reporting(0);

        try {
            self::assertFalse($this->handler()->handleError(E_WARNING, 'silenced'));
        } finally {
            error_reporting($previous);
        }
    }

    public function testLogsDeprecationsInsteadOfThrowing(): void
    {
        self::assertTrue($this->handler()->handleError(E_DEPRECATED, 'old API', 'lib.php', 3));
        self::assertTrue($this->handler()->handleError(E_USER_DEPRECATED, 'old user API'));

        self::assertSame('notice', $this->logger->records[0]['level']);
        self::assertSame('Deprecated: {message}', $this->logger->records[0]['message']);
        self::assertSame(['message' => 'old API', 'file' => 'lib.php', 'line' => 3], $this->logger->records[0]['context']);
        self::assertCount(2, $this->logger->records);
    }

    public function testUncaughtExceptionIsLoggedAndRenderedWithStatus500(): void
    {
        $exception = new RuntimeException('database password=hunter2 rejected');

        $this->handler()->handleException($exception);

        self::assertSame('critical', $this->logger->records[0]['level']);
        self::assertSame($exception, $this->logger->records[0]['context']['exception']);

        self::assertCount(1, $this->emitted);
        [$output, $contentType, $status] = $this->emitted[0];
        self::assertSame(500, $status);
        self::assertSame('text/plain; charset=utf-8', $contentType);
        self::assertSame(PlainTextRenderer::PRODUCTION_MESSAGE . "\n", $output);
    }

    public function testProductionOutputRevealsNothing(): void
    {
        $this->handler()->handleException(new RuntimeException('secret detail'));

        $output = $this->emitted[0][0];
        self::assertStringNotContainsString('secret detail', $output);
        self::assertStringNotContainsString('RuntimeException', $output);
        self::assertStringNotContainsString(__FILE__, $output);
    }

    public function testDebugOutputShowsDetailsAndPreviousExceptions(): void
    {
        $this->handler(debug: true)->handleException(new RuntimeException('outer', 0, new LogicException('inner')));

        $output = $this->emitted[0][0];
        self::assertStringContainsString('RuntimeException: outer in ' . __FILE__, $output);
        self::assertStringContainsString('Caused by: LogicException: inner', $output);
        self::assertStringContainsString('#0 ', $output);
    }

    public function testLoggingFailureFallsBackToErrorLogAndStillRenders(): void
    {
        $logFile = tempnam(sys_get_temp_dir(), 'veyrophp-errlog-');
        self::assertIsString($logFile);
        $previous = ini_set('error_log', $logFile);

        try {
            $this->handler(logger: new FailingLogger())->handleException(new RuntimeException('original failure'));
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
        }

        $errorLog = (string) file_get_contents($logFile);
        unlink($logFile);

        self::assertStringContainsString('logging failed (RuntimeException: disk full)', $errorLog);
        self::assertStringContainsString('original error: RuntimeException: original failure', $errorLog);
        self::assertCount(1, $this->emitted);
    }

    public function testRegisterInstallsAndUnregisterRestoresHandlers(): void
    {
        $handler = $this->handler();
        $sentinel = static fn(): bool => true;
        set_error_handler($sentinel);

        try {
            $handler->register();
            self::assertTrue($handler->isRegistered());

            try {
                trigger_error('converted', E_USER_WARNING);
                self::fail('Expected the registered handler to throw.');
            } catch (ErrorException $e) {
                self::assertSame('converted', $e->getMessage());
            }

            $handler->unregister();
            self::assertFalse($handler->isRegistered());

            $current = set_error_handler(null);
            restore_error_handler();
            self::assertSame($sentinel, $current);
        } finally {
            $handler->unregister();
            restore_error_handler();
        }
    }

    public function testRegisterTwiceFails(): void
    {
        $handler = $this->handler();
        $handler->register();

        try {
            $this->expectException(LogicException::class);
            $handler->register();
        } finally {
            $handler->unregister();
        }
    }

    public function testUnregisterWithoutRegisterIsHarmless(): void
    {
        $handler = $this->handler();
        $handler->unregister();

        self::assertFalse($handler->isRegistered());
    }

    public function testShutdownDoesNothingWhenNotRegistered(): void
    {
        $this->handler()->handleShutdown();

        self::assertSame([], $this->emitted);
        self::assertSame([], $this->logger->records);
    }

    public function testHtmlRendererEscapesDebugOutput(): void
    {
        $html = (new HtmlRenderer())->render(new RuntimeException('<script>alert(1)</script>'), true);

        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public function testHtmlRendererProductionPageIsGeneric(): void
    {
        $renderer = new HtmlRenderer();
        $html = $renderer->render(new RuntimeException('secret detail'), false);

        self::assertStringContainsString('500 | Server Error', $html);
        self::assertStringNotContainsString('secret detail', $html);
        self::assertStringNotContainsString('RuntimeException', $html);
        self::assertSame('text/html; charset=utf-8', $renderer->contentType());
    }
}
