<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;
use VeyroPHP\Exception\VeyroPHPException;

/**
 * Every exception class the framework and its first-party packages define implements
 * VeyroPHPException, so applications can catch framework errors by one interface
 * (CODING_STANDARDS §3; audit findings CORE-6, HTTP-13).
 */
final class ExceptionMarkerTest extends TestCase
{
    public function testEveryFrameworkExceptionClassImplementsTheMarker(): void
    {
        $root = dirname(__DIR__, 3);
        $missing = [];
        $checked = 0;

        foreach ([$root . '/src', ...(glob($root . '/packages/*/src') ?: [])] as $dir) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));

            foreach ($files as $file) {
                \assert($file instanceof \SplFileInfo);
                $code = (string) file_get_contents($file->getPathname());

                if (preg_match('/^namespace\s+([^;]+);/m', $code, $namespace) !== 1
                    || preg_match_all('/^(?:final\s+|abstract\s+)?class\s+(\w+)\b/m', $code, $classes) < 1) {
                    continue;
                }

                foreach ($classes[1] as $short) {
                    $class = $namespace[1] . '\\' . $short;

                    if (!class_exists($class) || !is_subclass_of($class, \Throwable::class)) {
                        continue;
                    }

                    $checked++;

                    if (!is_subclass_of($class, VeyroPHPException::class)) {
                        $missing[] = $class;
                    }
                }
            }
        }

        self::assertGreaterThan(20, $checked, 'the scan found the exception classes');
        self::assertSame([], $missing);
    }
}
