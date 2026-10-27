<?php

declare(strict_types=1);

namespace VeyroPHP\Testing;

use VeyroPHP\Application\Application;
use VeyroPHP\Application\ServiceProvider;
use VeyroPHP\Cache\CacheServiceProvider;
use VeyroPHP\Config\Config;
use VeyroPHP\Console\Console;
use VeyroPHP\Console\ConsoleServiceProvider;
use VeyroPHP\Console\Output;
use VeyroPHP\Database\DatabaseServiceProvider;
use VeyroPHP\Events\EventServiceProvider;
use VeyroPHP\Http\HttpKernel;
use VeyroPHP\Mail\MailServiceProvider;
use VeyroPHP\Package\PackageException;
use VeyroPHP\Package\Packages;
use VeyroPHP\Queue\QueueServiceProvider;

/**
 * A booted application for tests, especially of packages (like Laravel's
 * Testbench). Works with any test runner.
 *
 *     $test = TestApplication::forPackage(__DIR__ . '/..', ['greeting' => ['name' => 'Test']]);
 *     $test->make(Greeter::class)->greet();
 *     [$code, $output] = $test->command(['greeting:hello', 'Asha']);
 *     $test->cleanUp();   // in tearDown
 *
 * Defaults, all without network or disk state outside a temporary directory:
 * SQLite in memory, array cache, array mailer, sync queue, events and console.
 * Pass your own $framework providers to change the stack (add
 * HttpServiceProvider, Session, View... for packages with routes or views).
 */
final class TestApplication
{
    private function __construct(
        private readonly Application $app,
        private readonly string $basePath,
        private readonly bool $ownsBasePath,
    ) {}

    /**
     * @param list<ServiceProvider> $providers the code under test
     * @param array<string, mixed> $config merged over the test defaults
     * @param list<ServiceProvider>|null $framework framework providers; null for the default stack
     */
    public static function boot(array $providers = [], array $config = [], ?array $framework = null, ?string $basePath = null): self
    {
        $owns = $basePath === null;
        $basePath ??= sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'veyrophp-test-app-' . bin2hex(random_bytes(6));

        if (!is_dir($basePath) && !@mkdir($basePath, 0777, true) && !is_dir($basePath)) {
            throw new \RuntimeException('Cannot create the test application directory ' . $basePath);
        }

        $app = new Application($basePath);
        $app->container()->instance(Config::class, new Config(array_replace_recursive(self::defaults(), $config)));

        foreach ($framework ?? self::defaultFramework() as $provider) {
            $app->register($provider);
        }

        foreach ($providers as $provider) {
            $app->register($provider);
        }

        $app->boot();

        return new self($app, $basePath, $owns);
    }

    /**
     * Boots an application with the providers a package declares in its
     * composer.json (extra.veyrophp.providers), exactly as discovery would.
     *
     * @param array<string, mixed> $config
     * @param list<ServiceProvider>|null $framework
     */
    public static function forPackage(string $packageDir, array $config = [], ?array $framework = null): self
    {
        $json = json_decode((string) @file_get_contents(rtrim($packageDir, '/\\') . '/composer.json'), true);
        $extra = is_array($json) && is_array($json['extra'] ?? null) ? $json['extra'] : [];
        $veyrophp = is_array($extra['veyrophp'] ?? null) ? $extra['veyrophp'] : [];
        $providers = is_array($veyrophp['providers'] ?? null) ? $veyrophp['providers'] : null;

        if ($providers === null) {
            throw new PackageException(sprintf('%s/composer.json has no "extra.veyrophp.providers" list.', $packageDir));
        }

        $instances = [];

        foreach ($providers as $provider) {
            if (!is_string($provider)) {
                throw new PackageException('extra.veyrophp.providers must contain class names.');
            }

            $instances[] = Packages::instantiate(ltrim($provider, '\\'));
        }

        return self::boot($instances, $config, $framework);
    }

    public function app(): Application
    {
        return $this->app;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    public function make(string $class): object
    {
        return $this->app->container()->make($class);
    }

    public function config(): Config
    {
        return $this->make(Config::class);
    }

    /**
     * Runs a console command; returns exit code, standard output and error output.
     *
     * @param list<string> $arguments
     *
     * @return array{int, string, string}
     */
    public function command(array $arguments): array
    {
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');

        if ($out === false || $err === false) {
            throw new \RuntimeException('Cannot open memory streams.');
        }

        $code = $this->make(Console::class)->run($arguments, new Output($out, $err));
        rewind($out);
        rewind($err);

        return [$code, str_replace(PHP_EOL, "\n", (string) stream_get_contents($out)), str_replace(PHP_EOL, "\n", (string) stream_get_contents($err))];
    }

    /**
     * An HTTP client for the application; needs HttpServiceProvider in $framework.
     */
    public function http(): TestClient
    {
        if (!$this->app->container()->bound(HttpKernel::class)) {
            throw new \LogicException('http() needs HttpServiceProvider: pass it in $framework.');
        }

        return new TestClient($this->make(HttpKernel::class));
    }

    public function basePath(): string
    {
        return $this->basePath;
    }

    /**
     * Deletes the temporary directory (only one this class created).
     */
    public function cleanUp(): void
    {
        if (!$this->ownsBasePath || !is_dir($this->basePath)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->basePath, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($items as $item) {
            \assert($item instanceof \SplFileInfo);
            // Links are removed, never followed.
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($this->basePath);
    }

    /**
     * @return array<string, mixed>
     */
    private static function defaults(): array
    {
        return [
            'app' => ['name' => 'Test', 'env' => 'testing', 'debug' => true, 'url' => 'http://localhost'],
            'database' => ['default' => 'memory', 'connections' => ['memory' => ['driver' => 'sqlite', 'database' => ':memory:']]],
            'cache' => ['default' => 'array', 'prefix' => 'test', 'stores' => ['array' => ['driver' => 'array']]],
            'mail' => ['default' => 'array', 'from' => ['address' => 'test@example.com'], 'mailers' => ['array' => ['transport' => 'array']]],
            'queue' => ['default' => 'sync', 'connections' => ['sync' => ['driver' => 'sync']]],
        ];
    }

    /**
     * @return list<ServiceProvider>
     */
    private static function defaultFramework(): array
    {
        return [
            new DatabaseServiceProvider(),
            new CacheServiceProvider(),
            new EventServiceProvider(),
            new MailServiceProvider(),
            new QueueServiceProvider(),
            new ConsoleServiceProvider(),
        ];
    }
}
