<?php

declare(strict_types=1);

namespace VeyroPHP\Exception;

use Closure;
use ErrorException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;
use VeyroPHP\Exception\Renderer\ExceptionRenderer;
use VeyroPHP\Exception\Renderer\HtmlRenderer;
use VeyroPHP\Exception\Renderer\PlainTextRenderer;

/**
 * Global PHP error, exception and fatal-error handling.
 *
 * - PHP errors included in error_reporting() become ErrorException. Errors
 *   silenced with "@" or excluded by error_reporting() are left to PHP.
 * - Deprecations are logged at "notice" level instead of thrown, so a
 *   deprecation in a dependency does not break the application.
 * - Uncaught exceptions and fatal errors are logged at "critical" level and
 *   rendered with an ExceptionRenderer as a 500 response. Without debug, the
 *   output reveals no message, path or trace.
 * - If logging itself fails, both failures go to PHP's error_log().
 *
 * The HTTP layer catches exceptions per request; this handler is the last
 * line of defence for anything that escapes it.
 */
final class ErrorHandler
{
    private const FATAL_ERRORS = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR;

    private const DEPRECATIONS = E_DEPRECATED | E_USER_DEPRECATED;

    private readonly ExceptionRenderer $renderer;

    /** @var Closure(string, string, int): void */
    private readonly Closure $emitter;

    private bool $registered = false;

    private bool $shutdownFunctionAdded = false;

    /**
     * Memory released at fatal-error time, so the handler can still run after an out-of-memory error.
     *
     * @phpstan-ignore property.onlyWritten (only held so it can be freed)
     */
    private ?string $reservedMemory = null;

    /**
     * @param ExceptionRenderer|null $renderer defaults to plain text on the CLI and HTML otherwise
     * @param Closure(string $output, string $contentType, int $status): void|null $emitter defaults to STDERR on the CLI, otherwise the HTTP response
     */
    public function __construct(
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly bool $debug = false,
        ?ExceptionRenderer $renderer = null,
        ?Closure $emitter = null,
    ) {
        $this->renderer = $renderer ?? (self::isCli() ? new PlainTextRenderer() : new HtmlRenderer());
        $this->emitter = $emitter ?? self::defaultEmitter(...);
    }

    /**
     * @throws \LogicException when already registered
     */
    public function register(): void
    {
        if ($this->registered) {
            throw new \LogicException('The error handler is already registered.');
        }

        set_error_handler($this->handleError(...));
        set_exception_handler($this->handleUncaught(...));

        // A shutdown function cannot be removed, so it is added once and checks $registered itself.
        if (!$this->shutdownFunctionAdded) {
            register_shutdown_function($this->handleShutdown(...));
            $this->shutdownFunctionAdded = true;
        }

        $this->reservedMemory = str_repeat('x', 32 * 1024);
        $this->registered = true;
    }

    /**
     * Restores the error and exception handlers that were active before register().
     */
    public function unregister(): void
    {
        if (!$this->registered) {
            return;
        }

        restore_error_handler();
        restore_exception_handler();
        $this->reservedMemory = null;
        $this->registered = false;
    }

    public function isRegistered(): bool
    {
        return $this->registered;
    }

    /**
     * @throws ErrorException for every reported, non-deprecation error
     */
    public function handleError(int $level, string $message, string $file = '', int $line = 0): bool
    {
        if ((error_reporting() & $level) === 0) {
            return false;
        }

        if (($level & self::DEPRECATIONS) !== 0) {
            $this->logSafely('notice', 'Deprecated: {message}', ['message' => $message, 'file' => $file, 'line' => $line], null);

            return true;
        }

        throw new ErrorException($message, 0, $level, $file, $line);
    }

    /**
     * Logs the exception and emits the rendered error output with status 500.
     */
    public function handleException(Throwable $throwable): void
    {
        $this->reservedMemory = null;

        $this->logSafely(
            'critical',
            'Uncaught {class}: {message}',
            ['class' => $throwable::class, 'message' => $throwable->getMessage(), 'exception' => $throwable],
            $throwable,
        );

        ($this->emitter)($this->renderer->render($throwable, $this->debug), $this->renderer->contentType(), 500);
    }

    /**
     * Installed with set_exception_handler(). On the CLI it exits with status 255,
     * because PHP otherwise exits with 0 once a user exception handler has run,
     * which would make failed scripts look successful to cron and CI.
     */
    private function handleUncaught(Throwable $throwable): void
    {
        $this->handleException($throwable);

        if (self::isCli()) {
            exit(255);
        }
    }

    /**
     * Handles a fatal error that ended the script. Does nothing when unregistered.
     */
    public function handleShutdown(): void
    {
        if (!$this->registered) {
            return;
        }

        $error = error_get_last();

        if ($error === null || ($error['type'] & self::FATAL_ERRORS) === 0) {
            return;
        }

        $this->handleException(new FatalError($error['message'], 0, $error['type'], $error['file'], $error['line']));
    }

    /**
     * @param array<string, mixed> $context
     */
    private function logSafely(string $level, string $message, array $context, ?Throwable $original): void
    {
        try {
            $this->logger->log($level, $message, $context);
        } catch (Throwable $logFailure) {
            error_log(sprintf('VeyroPHP: logging failed (%s: %s)', $logFailure::class, $logFailure->getMessage()));

            if ($original !== null) {
                error_log('VeyroPHP: original error: ' . ThrowableFormatter::toText($original));
            }
        }
    }

    private static function defaultEmitter(string $output, string $contentType, int $status): void
    {
        if (self::isCli()) {
            fwrite(STDERR, $output);

            return;
        }

        // Discard partial page output so the error page is not mixed into it.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: ' . $contentType);
        }

        echo $output;
    }

    private static function isCli(): bool
    {
        return PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg';
    }
}
