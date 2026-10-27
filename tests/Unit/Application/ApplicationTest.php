<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Unit\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VeyroPHP\Application\Application;
use VeyroPHP\Exception\InvalidPathException;
use VeyroPHP\Exception\VeyroPHPException;

#[CoversClass(Application::class)]
#[CoversClass(InvalidPathException::class)]
final class ApplicationTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $resolved = realpath(sys_get_temp_dir());
        self::assertIsString($resolved);

        $this->root = $resolved . DIRECTORY_SEPARATOR . 'veyrophp-app-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->root));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            rmdir($this->root);
        }
    }

    public function testExposesFrameworkVersion(): void
    {
        $app = new Application($this->root);

        self::assertSame(Application::VERSION, $app->version());
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+(-[0-9A-Za-z.]+)?$/', $app->version());
    }

    public function testBasePathIsResolvedAndHasNoTrailingSeparator(): void
    {
        $app = new Application($this->root . DIRECTORY_SEPARATOR);

        self::assertSame($this->root, $app->basePath());
    }

    public function testBasePathResolvesDotSegmentsInConstructorArgument(): void
    {
        $app = new Application($this->root . DIRECTORY_SEPARATOR . '.' . DIRECTORY_SEPARATOR);

        self::assertSame($this->root, $app->basePath());
    }

    public function testRejectsEmptyBasePath(): void
    {
        $this->expectException(InvalidPathException::class);
        $this->expectExceptionMessage('must not be empty');

        new Application('');
    }

    public function testRejectsMissingBaseDirectory(): void
    {
        $this->expectException(InvalidPathException::class);
        $this->expectExceptionMessage('does not exist');

        new Application($this->root . DIRECTORY_SEPARATOR . 'missing');
    }

    public function testRejectsFileAsBasePath(): void
    {
        $file = $this->root . DIRECTORY_SEPARATOR . 'file.txt';
        self::assertNotFalse(file_put_contents($file, 'x'));

        try {
            $this->expectException(InvalidPathException::class);
            new Application($file);
        } finally {
            unlink($file);
        }
    }

    public function testRejectsNullByteInBasePath(): void
    {
        $this->expectException(InvalidPathException::class);
        $this->expectExceptionMessage('null bytes');

        new Application($this->root . "\0evil");
    }

    #[DataProvider('relativePaths')]
    public function testJoinsRelativePathsInsideBasePath(string $relative, string $expectedSuffix): void
    {
        $app = new Application($this->root);

        $expected = $expectedSuffix === ''
            ? $this->root
            : $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $expectedSuffix);

        self::assertSame($expected, $app->basePath($relative));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function relativePaths(): iterable
    {
        yield 'empty' => ['', ''];
        yield 'single segment' => ['config', 'config'];
        yield 'nested forward slashes' => ['config/app.php', 'config/app.php'];
        yield 'backslashes' => ['config\\app.php', 'config/app.php'];
        yield 'repeated separators' => ['config//cache///app.php', 'config/cache/app.php'];
        yield 'dot segments' => ['./config/./app.php', 'config/app.php'];
        yield 'trailing separator' => ['storage/', 'storage'];
        yield 'only dots' => ['./.', ''];
        yield 'dots inside names are allowed' => ['..hidden/file..txt', '..hidden/file..txt'];
    }

    #[DataProvider('escapingPaths')]
    public function testRejectsPathsThatEscapeBasePath(string $path): void
    {
        $app = new Application($this->root);

        $this->expectException(InvalidPathException::class);

        $app->basePath($path);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function escapingPaths(): iterable
    {
        yield 'parent segment' => ['..'];
        yield 'leading traversal' => ['../etc/passwd'];
        yield 'nested traversal' => ['config/../../secret'];
        yield 'backslash traversal' => ['config\\..\\..\\secret'];
        yield 'unix absolute' => ['/etc/passwd'];
        yield 'windows root absolute' => ['\\Windows\\System32'];
        yield 'windows drive absolute' => ['C:\\Windows'];
        yield 'windows drive relative' => ['C:Windows'];
        yield 'stream wrapper' => ['phar://archive.phar/file'];
        yield 'null byte' => ["config\0.php"];
    }

    public function testFrameworkExceptionsShareMarkerInterface(): void
    {
        $app = new Application($this->root);

        try {
            $app->basePath('../x');
            self::fail('Expected an exception.');
        } catch (VeyroPHPException $e) {
            self::assertInstanceOf(\InvalidArgumentException::class, $e);
        }
    }
}
