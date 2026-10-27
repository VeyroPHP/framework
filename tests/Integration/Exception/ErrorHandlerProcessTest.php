<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Integration\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use VeyroPHP\Exception\ErrorHandler;

/**
 * Runs real PHP processes, because uncaught exceptions and fatal errors end the script.
 */
#[CoversClass(ErrorHandler::class)]
final class ErrorHandlerProcessTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'veyrophp-proc-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->dir));
    }

    protected function tearDown(): void
    {
        foreach (scandir($this->dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                unlink($this->dir . DIRECTORY_SEPARATOR . $entry);
            }
        }

        rmdir($this->dir);
    }

    public function testUncaughtExceptionIsLoggedAndProductionOutputIsGeneric(): void
    {
        [$exitCode, $stdout, $stderr, $log] = $this->runScript(<<<'PHP'
            throw new RuntimeException('secret detail');
            PHP);

        self::assertSame(255, $exitCode);
        self::assertSame('', $stdout);
        self::assertSame("An unexpected error occurred. Check the application log for details.\n", $stderr);
        self::assertStringContainsString('app.CRITICAL: Uncaught RuntimeException: secret detail', $log);
    }

    public function testWarningBecomesExceptionAndIsHandled(): void
    {
        [$exitCode, , $stderr, $log] = $this->runScript(<<<'PHP'
            $values = [];
            echo $values['missing'];
            PHP, debug: true);

        self::assertSame(255, $exitCode);
        self::assertStringContainsString('ErrorException: Undefined array key "missing"', $stderr);
        self::assertStringContainsString('Uncaught ErrorException', $log);
    }

    public function testFatalOutOfMemoryErrorIsLoggedAtShutdown(): void
    {
        [$exitCode, , $stderr, $log] = $this->runScript(<<<'PHP'
            ini_set('memory_limit', '16M');
            $chunks = [];
            while (true) {
                $chunks[] = str_repeat('x', 1024 * 1024);
            }
            PHP, debug: true);

        self::assertSame(255, $exitCode);
        self::assertStringContainsString('VeyroPHP\Exception\FatalError: Allowed memory size', $stderr);
        self::assertStringContainsString('Uncaught VeyroPHP\Exception\FatalError: Allowed memory size', $log);
    }

    /**
     * @return array{int, string, string, string} exit code, stdout, stderr, log file contents
     */
    private function runScript(string $body, bool $debug = false): array
    {
        $autoload = var_export(dirname(__DIR__, 3) . '/vendor/autoload.php', true);
        $logFile = $this->dir . DIRECTORY_SEPARATOR . 'app.log';
        $logExport = var_export($logFile, true);
        $debugExport = var_export($debug, true);

        $script = $this->dir . DIRECTORY_SEPARATOR . 'script.php';
        file_put_contents($script, <<<PHP
            <?php
            declare(strict_types=1);
            require {$autoload};
            (new VeyroPHP\\Exception\\ErrorHandler(new VeyroPHP\\Logging\\FileLogger({$logExport}), {$debugExport}))->register();
            {$body}
            PHP);

        $process = proc_open(
            [PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=0', '-d', 'error_reporting=-1', $script],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $log = is_file($logFile) ? (string) file_get_contents($logFile) : '';

        return [$exitCode, $stdout, $stderr, $log];
    }
}
