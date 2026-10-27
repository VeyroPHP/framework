<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Integration\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use VeyroPHP\Application\Application;
use VeyroPHP\Cache\CacheServiceProvider;
use VeyroPHP\Config\Config;
use VeyroPHP\Console\Console;
use VeyroPHP\Console\ConsoleServiceProvider;
use VeyroPHP\Console\Output;
use VeyroPHP\Database\DatabaseServiceProvider;
use VeyroPHP\Http\Console\HealthCommand;
use VeyroPHP\Http\Health\HealthCheck;
use VeyroPHP\Http\HttpServiceProvider;
use VeyroPHP\Http\Maintenance\MaintenanceMode;

#[CoversClass(HttpServiceProvider::class)]
#[CoversClass(HealthCommand::class)]
final class HealthWiringTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'veyrophp-healthwire-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/storage', 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/storage/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->root . '/storage');
        rmdir($this->root);
    }

    private function app(string $database = ':memory:'): Application
    {
        $app = new Application($this->root);
        $app->container()->instance(Config::class, new Config([
            'app' => ['env' => 'local'],
            'database' => ['default' => 'main', 'connections' => ['main' => ['driver' => 'sqlite', 'database' => $database]]],
            'cache' => ['default' => 'array', 'stores' => ['array' => ['driver' => 'array']]],
        ]));
        $app->register(new DatabaseServiceProvider());
        $app->register(new CacheServiceProvider());
        $app->register(new HttpServiceProvider());
        $app->register(new ConsoleServiceProvider());
        $app->boot();

        return $app;
    }

    /**
     * @return array{int, string}
     */
    private static function exec(Application $app, string ...$arguments): array
    {
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        self::assertIsResource($out);
        self::assertIsResource($err);
        $code = $app->container()->make(Console::class)->run(array_values($arguments), new Output($out, $err));
        rewind($out);
        rewind($err);

        return [$code, (string) stream_get_contents($out) . (string) stream_get_contents($err)];
    }

    public function testBuiltInChecksAndMaintenanceModeAreRegistered(): void
    {
        $app = $this->app();
        $health = $app->container()->make(HealthCheck::class);

        self::assertSame(['database', 'cache', 'storage'], $health->names());
        self::assertTrue($health->run()->healthy());
        $file = (new \ReflectionProperty(MaintenanceMode::class, 'file'))->getValue($app->container()->make(MaintenanceMode::class));
        self::assertIsString($file);
        self::assertSame(str_replace('\\', '/', $this->root . '/' . MaintenanceMode::FILE), str_replace('\\', '/', $file));
    }

    public function testHealthCommandShowsDetails(): void
    {
        [$code, $out] = self::exec($this->app(), 'health');

        self::assertSame(0, $code, $out);
        self::assertMatchesRegularExpression('/database\s+ok\s+[\d.]+ ms/', $out);
        self::assertMatchesRegularExpression('/storage\s+ok/', $out);
    }

    public function testHealthCommandFailsWithTheErrorWhenTheDatabaseIsUnreachable(): void
    {
        [$code, $out] = self::exec($this->app($this->root . '/missing-dir/db.sqlite'), 'health');

        self::assertSame(1, $code);
        self::assertMatchesRegularExpression('/database\s+FAIL/', $out);
        self::assertStringContainsString('cache', $out);
    }
}
