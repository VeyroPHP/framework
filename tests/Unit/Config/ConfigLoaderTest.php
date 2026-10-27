<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use VeyroPHP\Config\Config;
use VeyroPHP\Config\ConfigLoader;
use VeyroPHP\Config\Env\Env;
use VeyroPHP\Config\Exception\ConfigException;

#[CoversClass(ConfigLoader::class)]
#[CoversClass(ConfigException::class)]
#[CoversClass(Config::class)]
final class ConfigLoaderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'veyrophp-config-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->dir));
    }

    protected function tearDown(): void
    {
        self::removeDirectory($this->dir);
    }

    public function testLoadsEachFileUnderItsName(): void
    {
        $this->writeFile('app.php', "<?php return ['name' => 'VeyroPHP', 'debug' => true];");
        $this->writeFile('database.php', "<?php return ['default' => 'mysql'];");

        $config = (new ConfigLoader())->load($this->dir);

        self::assertSame('VeyroPHP', $config->string('app.name'));
        self::assertTrue($config->bool('app.debug'));
        self::assertSame('mysql', $config->string('database.default'));
    }

    public function testFilesAreLoadedInAlphabeticalOrder(): void
    {
        $this->writeFile('zeta.php', '<?php return [];');
        $this->writeFile('alpha.php', '<?php return [];');
        $this->writeFile('mid.php', '<?php return [];');

        self::assertSame(['alpha', 'mid', 'zeta'], array_keys((new ConfigLoader())->load($this->dir)->all()));
    }

    public function testEmptyDirectoryGivesEmptyConfig(): void
    {
        self::assertSame([], (new ConfigLoader())->load($this->dir)->all());
    }

    public function testIgnoresNonPhpFilesHiddenFilesAndSubdirectories(): void
    {
        $this->writeFile('app.php', "<?php return ['ok' => true];");
        $this->writeFile('notes.txt', 'not config');
        $this->writeFile('.hidden.php', "<?php throw new RuntimeException('must not load');");
        self::assertTrue(mkdir($this->dir . DIRECTORY_SEPARATOR . 'nested.php'));
        $this->writeFile('nested.php' . DIRECTORY_SEPARATOR . 'inner.php', "<?php throw new RuntimeException('must not load');");

        self::assertSame(['app'], array_keys((new ConfigLoader())->load($this->dir)->all()));
    }

    public function testRejectsFileNameWithDot(): void
    {
        $this->writeFile('app.local.php', '<?php return [];');

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('must not contain a dot');

        (new ConfigLoader())->load($this->dir);
    }

    public function testRejectsMissingDirectory(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('does not exist');

        (new ConfigLoader())->load($this->dir . DIRECTORY_SEPARATOR . 'missing');
    }

    public function testRejectsFileAsDirectory(): void
    {
        $this->writeFile('app.php', '<?php return [];');

        $this->expectException(ConfigException::class);

        (new ConfigLoader())->load($this->dir . DIRECTORY_SEPARATOR . 'app.php');
    }

    public function testRejectsFileThatDoesNotReturnArray(): void
    {
        $this->writeFile('app.php', "<?php return 'nope';");

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('must return an array, string returned');

        (new ConfigLoader())->load($this->dir);
    }

    public function testRejectsFileWithoutReturn(): void
    {
        $this->writeFile('app.php', '<?php $x = 1;');

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('must return an array, int returned');

        (new ConfigLoader())->load($this->dir);
    }

    public function testWrapsExceptionThrownByFileAndKeepsPrevious(): void
    {
        $this->writeFile('app.php', "<?php throw new RuntimeException('broken config');");

        try {
            (new ConfigLoader())->load($this->dir);
            self::fail('Expected a ConfigException.');
        } catch (ConfigException $e) {
            self::assertStringContainsString('app.php', $e->getMessage());
            self::assertStringNotContainsString('broken config', $e->getMessage());
            self::assertInstanceOf(RuntimeException::class, $e->getPrevious());
            self::assertSame('broken config', $e->getPrevious()->getMessage());
        }
    }

    public function testWrapsParseErrors(): void
    {
        $this->writeFile('app.php', '<?php return [;');

        try {
            (new ConfigLoader())->load($this->dir);
            self::fail('Expected a ConfigException.');
        } catch (ConfigException $e) {
            self::assertInstanceOf(\ParseError::class, $e->getPrevious());
        }
    }

    public function testConfigFileCannotAccessLoaderScope(): void
    {
        $this->writeFile('app.php', "<?php return ['this' => isset(\$this), 'vars' => array_keys(get_defined_vars())];");

        $config = (new ConfigLoader())->load($this->dir);

        self::assertFalse($config->bool('app.this'));
        self::assertSame(['__path', 'env'], $config->array('app.vars'));
    }

    public function testConfigFilesReadEnvironmentThroughEnvVariable(): void
    {
        $this->writeFile('app.php', "<?php return ['debug' => \$env->bool('APP_DEBUG', false), 'name' => \$env->string('APP_NAME', 'VeyroPHP')];");

        $config = (new ConfigLoader())->load($this->dir, new Env(['APP_DEBUG' => 'true']));

        self::assertTrue($config->bool('app.debug'));
        self::assertSame('VeyroPHP', $config->string('app.name'));
    }

    private function writeFile(string $name, string $contents): void
    {
        self::assertNotFalse(file_put_contents($this->dir . DIRECTORY_SEPARATOR . $name, $contents));
    }

    private static function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

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
