<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Integration\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use VeyroPHP\Application\Application;
use VeyroPHP\Config\Config;
use VeyroPHP\Config\ConfigCache;
use VeyroPHP\Config\ConfigServiceProvider;
use VeyroPHP\Config\Console\CacheCommand;
use VeyroPHP\Config\Console\ClearCommand;
use VeyroPHP\Config\Env\Env;
use VeyroPHP\Config\Env\EnvLoader;
use VeyroPHP\Config\Exception\ConfigException;
use VeyroPHP\Console\Console;
use VeyroPHP\Console\ConsoleServiceProvider;
use VeyroPHP\Console\Output;

#[CoversClass(ConfigCache::class)]
#[CoversClass(ConfigServiceProvider::class)]
#[CoversClass(CacheCommand::class)]
#[CoversClass(ClearCommand::class)]
final class ConfigCacheTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'veyrophp-cfgcache-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/config', 0777, true);
        file_put_contents($this->root . '/.env', "APP_NAME=First\nDB_PASSWORD=s3cret\n");
        file_put_contents($this->root . '/config/app.php', "<?php\nreturn ['name' => \$env->string('APP_NAME'), 'debug' => false, 'ratio' => 1.5, 'tags' => ['a', 'b'], 'none' => null];\n");
        file_put_contents($this->root . '/config/database.php', "<?php\nreturn ['password' => \$env->string('DB_PASSWORD')];\n");
    }

    protected function tearDown(): void
    {
        foreach ([...(glob($this->root . '/config/*') ?: []), ...(glob($this->root . '/bootstrap/cache/*') ?: []), $this->root . '/.env'] as $file) {
            @unlink($file);
        }
        foreach (['/bootstrap/cache', '/bootstrap', '/config', ''] as $dir) {
            @rmdir($this->root . $dir);
        }
    }

    private function app(?bool $useCache = null): Application
    {
        $app = new Application($this->root);
        $app->register(new ConfigServiceProvider(new EnvLoader([], false), $useCache));
        $app->register(new ConsoleServiceProvider());
        $app->boot();

        return $app;
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{int, string, string}
     */
    private static function exec(Application $app, array $arguments): array
    {
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        self::assertIsResource($out);
        self::assertIsResource($err);
        $code = $app->container()->make(Console::class)->run($arguments, new Output($out, $err));
        rewind($out);
        rewind($err);

        return [$code, str_replace(PHP_EOL, "\n", (string) stream_get_contents($out)), (string) stream_get_contents($err)];
    }

    private function cacheFile(): string
    {
        return $this->root . '/' . ConfigCache::RELATIVE_PATH;
    }

    public function testCacheIsUsedUntilCleared(): void
    {
        [$code, $out] = self::exec($this->app(), ['config:cache']);
        self::assertSame(0, $code);
        self::assertStringContainsString('Configuration cached: bootstrap/cache/config.php', $out);
        self::assertFileExists($this->cacheFile());

        // The cached file is valid PHP and keeps every type.
        self::assertSame(
            ['app' => ['name' => 'First', 'debug' => false, 'ratio' => 1.5, 'tags' => ['a', 'b'], 'none' => null], 'database' => ['password' => 's3cret']],
            ConfigCache::read($this->cacheFile()),
        );

        // .env changes are ignored while the cache exists...
        file_put_contents($this->root . '/.env', "APP_NAME=Second\nDB_PASSWORD=s3cret\n");
        $cached = $this->app();
        self::assertSame('First', $cached->container()->make(Config::class)->string('app.name'));
        self::assertFalse($cached->container()->make(Env::class)->has('APP_NAME'), '.env is not read when cached');

        // ...until the cache is cleared.
        self::assertSame([0, "Configuration cache cleared.\n", ''], self::exec($cached, ['config:clear']));
        self::assertFileDoesNotExist($this->cacheFile());
        self::assertSame('Second', $this->app()->container()->make(Config::class)->string('app.name'));
        self::assertSame([0, "There was no configuration cache.\n", ''], self::exec($this->app(), ['config:clear']));
    }

    public function testConfigCacheReadsSourcesNotTheOldCache(): void
    {
        self::exec($this->app(), ['config:cache']);
        file_put_contents($this->root . '/.env', "APP_NAME=Third\nDB_PASSWORD=s3cret\n");

        self::exec($this->app(), ['config:cache']);

        self::assertSame('Third', $this->app()->container()->make(Config::class)->string('app.name'));
    }

    public function testCacheCanBeIgnored(): void
    {
        self::exec($this->app(), ['config:cache']);
        file_put_contents($this->root . '/.env', "APP_NAME=Live\nDB_PASSWORD=s3cret\n");

        self::assertSame('Live', $this->app(false)->container()->make(Config::class)->string('app.name'));

        putenv('VEYROPHP_CONFIG_CACHE=0');
        try {
            self::assertSame('Live', $this->app()->container()->make(Config::class)->string('app.name'));
        } finally {
            putenv('VEYROPHP_CONFIG_CACHE');
        }

        self::assertSame('First', $this->app()->container()->make(Config::class)->string('app.name'));
    }

    public function testValuesThatCannotBeCachedAreRejected(): void
    {
        file_put_contents($this->root . '/config/hooks.php', "<?php\nreturn ['events' => ['saved' => static fn () => null]];\n");

        [$code, , $err] = self::exec($this->app(), ['config:cache']);

        self::assertSame(1, $code);
        self::assertStringContainsString('Config key "hooks.events.saved" holds a Closure', $err);
        self::assertFileDoesNotExist($this->cacheFile());
    }

    public function testNonArrayCacheFileIsIgnored(): void
    {
        mkdir(dirname($this->cacheFile()), 0777, true);
        file_put_contents($this->cacheFile(), "<?php\nreturn 'broken';\n");

        self::assertNull(ConfigCache::read($this->cacheFile()));
        self::assertSame('First', $this->app()->container()->make(Config::class)->string('app.name'));
    }

    public function testExceptionNamesTheKey(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('Config key "x.y" holds a stdClass');
        ConfigCache::write(new Config(['x' => ['y' => new \stdClass()]]), $this->cacheFile());
    }
}
