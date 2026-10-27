<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Integration\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use VeyroPHP\Support\Extensions;
use VeyroPHP\Support\MissingExtensionException;

/**
 * MISC-4: optional extensions are checked where they are used, with an error that says
 * which extension a feature needs, instead of "Class finfo not found" mid-request.
 */
#[CoversClass(Extensions::class)]
#[CoversClass(MissingExtensionException::class)]
final class MissingExtensionTest extends TestCase
{
    public function testAMissingExtensionNamesTheExtensionAndTheFeature(): void
    {
        try {
            Extensions::require('veyrophp_not_an_extension', 'testing');
            self::fail('a missing extension was accepted');
        } catch (MissingExtensionException $e) {
            self::assertSame('veyrophp_not_an_extension', $e->extension);
            self::assertStringContainsString('ext-veyrophp_not_an_extension', $e->getMessage());
            self::assertStringContainsStringIgnoringCase('testing', $e->getMessage());
            self::assertStringContainsString('php.ini', $e->getMessage());
        }

        Extensions::require('json', 'testing');
        $this->addToAssertionCount(1);
    }

    /**
     * Runs PHP without php.ini, where XAMPP-style builds have no fileinfo. Nothing on the
     * machine is changed; the test is skipped where fileinfo is compiled in.
     */
    public function testUploadAndDiskTypeDetectionExplainAMissingFileinfo(): void
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open() is disabled.');
        }

        $probe = shell_exec(escapeshellarg(PHP_BINARY) . ' -n -r ' . escapeshellarg("echo extension_loaded('fileinfo') ? 'yes' : 'no';"));

        if (trim((string) $probe) !== 'no') {
            self::markTestSkipped('fileinfo is compiled into this PHP, so it cannot be missing here.');
        }

        $script = dirname(__DIR__, 2) . '/Fixtures/Support/fileinfo-probe.php';
        $output = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -n ' . escapeshellarg($script) . ' 2>&1');

        self::assertSame(2, substr_count($output, MissingExtensionException::class . ': '), $output);
        self::assertSame(2, substr_count($output, 'ext-fileinfo'), $output);
        self::assertStringNotContainsString('Class "finfo" not found', $output);
    }
}
