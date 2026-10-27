<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Unit\Config\Env;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use VeyroPHP\Config\Env\DotenvParser;
use VeyroPHP\Config\Env\Env;
use VeyroPHP\Config\Env\EnvLoader;
use VeyroPHP\Config\Exception\EnvException;

#[CoversClass(EnvLoader::class)]
#[CoversClass(Env::class)]
#[CoversClass(DotenvParser::class)]
#[CoversClass(EnvException::class)]
final class EnvLoaderTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'veyrophp-env-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (is_file($this->file)) {
            unlink($this->file);
        }
    }

    public function testLoadsValuesFromFile(): void
    {
        file_put_contents($this->file, "APP_NAME=VeyroPHP\nAPP_DEBUG=true\n");

        $env = (new EnvLoader([]))->load($this->file);

        self::assertSame('VeyroPHP', $env->string('APP_NAME'));
        self::assertTrue($env->bool('APP_DEBUG'));
    }

    public function testRealEnvironmentOverridesFile(): void
    {
        file_put_contents($this->file, "APP_ENV=local\nAPP_NAME=FromFile\n");

        $env = (new EnvLoader(['APP_ENV' => 'production']))->load($this->file);

        self::assertSame('production', $env->string('APP_ENV'));
        self::assertSame('FromFile', $env->string('APP_NAME'));
    }

    public function testMissingFileUsesOnlyRealEnvironment(): void
    {
        $env = (new EnvLoader(['ONLY_SYSTEM' => 'yes']))->load($this->file);

        self::assertSame('yes', $env->string('ONLY_SYSTEM'));
        self::assertFalse($env->has('APP_NAME'));
    }

    public function testSyntaxErrorInFileFailsLoading(): void
    {
        file_put_contents($this->file, "BROKEN\n");

        $this->expectException(EnvException::class);
        $this->expectExceptionMessage('line 1');

        (new EnvLoader([]))->load($this->file);
    }

    public function testDirectoryPathIsTreatedAsMissingFile(): void
    {
        $env = (new EnvLoader(['A' => '1']))->load(sys_get_temp_dir());

        self::assertSame('1', $env->string('A'));
    }

    public function testDefaultsToProcessEnvironment(): void
    {
        $path = getenv('PATH');
        self::assertIsString($path, 'PATH must be set for this test.');

        self::assertSame($path, (new EnvLoader())->load($this->file)->string('PATH'));
    }

    public function testWindowsNamesAreCaseInsensitive(): void
    {
        file_put_contents($this->file, "PATH=from-file\n");

        // On Windows the process variable "Path" is the same variable as PATH and wins over .env.
        $windows = (new EnvLoader(['Path' => 'C:\\bin', 'App_Env' => 'production'], true))->load($this->file);
        self::assertSame('C:\\bin', $windows->string('PATH'));
        self::assertSame('production', $windows->string('APP_ENV'));
        self::assertSame('C:\\bin', $windows->string('Path'), 'the original spelling still works');

        // Elsewhere names are case-sensitive: "Path" and PATH are different variables.
        $linux = (new EnvLoader(['Path' => '/usr/bin'], false))->load($this->file);
        self::assertSame('from-file', $linux->string('PATH'));
        self::assertFalse($linux->has('APP_ENV'));
    }

    public function testDoesNotModifyGlobalEnvironment(): void
    {
        $name = 'VEYROPHP_TEST_' . strtoupper(bin2hex(random_bytes(4)));
        file_put_contents($this->file, "{$name}=leaked\n");

        (new EnvLoader([]))->load($this->file);

        self::assertFalse(getenv($name));
        self::assertArrayNotHasKey($name, $_ENV);
        self::assertArrayNotHasKey($name, $_SERVER);
    }
}
