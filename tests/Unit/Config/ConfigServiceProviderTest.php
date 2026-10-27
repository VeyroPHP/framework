<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use VeyroPHP\Application\Application;
use VeyroPHP\Config\Config;
use VeyroPHP\Config\ConfigServiceProvider;
use VeyroPHP\Config\Env\Env;
use VeyroPHP\Config\Env\EnvLoader;

#[CoversClass(ConfigServiceProvider::class)]
final class ConfigServiceProviderTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $resolved = realpath(sys_get_temp_dir());
        self::assertIsString($resolved);
        $this->root = $resolved . DIRECTORY_SEPARATOR . 'veyrophp-provider-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->root));
    }

    protected function tearDown(): void
    {
        foreach (['config/app.php', '.env'] as $file) {
            $path = $this->root . DIRECTORY_SEPARATOR . $file;
            if (is_file($path)) {
                unlink($path);
            }
        }

        if (is_dir($this->root . DIRECTORY_SEPARATOR . 'config')) {
            rmdir($this->root . DIRECTORY_SEPARATOR . 'config');
        }

        rmdir($this->root);
    }

    public function testRegistersEnvAndConfigFromBasePath(): void
    {
        mkdir($this->root . DIRECTORY_SEPARATOR . 'config');
        file_put_contents($this->root . DIRECTORY_SEPARATOR . '.env', "APP_NAME=FromEnv\n");
        file_put_contents(
            $this->root . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php',
            "<?php return ['name' => \$env->string('APP_NAME'), 'debug' => \$env->bool('APP_DEBUG', false)];",
        );

        $app = new Application($this->root);
        $app->register(new ConfigServiceProvider(new EnvLoader(['APP_DEBUG' => 'true'])));

        $config = $app->container()->make(Config::class);
        self::assertSame('FromEnv', $config->string('app.name'));
        self::assertTrue($config->bool('app.debug'));
        self::assertSame('FromEnv', $app->container()->make(Env::class)->string('APP_NAME'));
    }

    public function testMissingEnvAndConfigDirectoryGiveEmptyValues(): void
    {
        $app = new Application($this->root);
        $app->register(new ConfigServiceProvider(new EnvLoader([])));

        self::assertSame([], $app->container()->make(Config::class)->all());
        self::assertFalse($app->container()->make(Env::class)->has('APP_NAME'));
    }

    public function testConfigIsSameInstanceOnEveryResolution(): void
    {
        $app = new Application($this->root);
        $app->register(new ConfigServiceProvider(new EnvLoader([])));

        self::assertSame($app->container()->get(Config::class), $app->container()->get(Config::class));
    }
}
