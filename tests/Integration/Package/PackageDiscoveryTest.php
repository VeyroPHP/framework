<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Integration\Package;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use VeyroPHP\Application\Application;
use VeyroPHP\Config\Config;
use VeyroPHP\Console\Command;
use VeyroPHP\Console\CommandRegistry;
use VeyroPHP\Console\Console;
use VeyroPHP\Console\ConsoleServiceProvider;
use VeyroPHP\Console\Input;
use VeyroPHP\Console\Output;
use VeyroPHP\Package\Console\DiscoverCommand;
use VeyroPHP\Package\Console\PublishCommand;
use VeyroPHP\Package\PackageException;
use VeyroPHP\Package\PackageManifest;
use VeyroPHP\Package\Packages;
use VeyroPHP\Package\PackageServiceProvider;
use VeyroPHP\Package\Publishables;

final class OrderLog
{
    /** @var list<string> */
    public static array $calls = [];
}

#[\VeyroPHP\Console\AsCommand('alpha:hello', 'Says hello from the alpha package')]
final class AlphaCommand extends Command
{
    public function definition(): array
    {
        return [];
    }

    public function execute(Input $input, Output $output): int
    {
        $output->line('hello from alpha');

        return self::SUCCESS;
    }
}

final class AlphaProvider extends PackageServiceProvider
{
    public static string $configFile = '';

    public function register(Application $app): void
    {
        OrderLog::$calls[] = 'alpha:register';
        $this->mergeConfig($app, 'alpha', self::$configFile);
        $this->commands($app, AlphaCommand::class);
        $this->publishes($app, [self::$configFile => 'config/alpha.php'], 'alpha-config');
    }

    public function boot(Application $app): void
    {
        OrderLog::$calls[] = 'alpha:boot';
    }
}

final class AlphaSecondProvider extends PackageServiceProvider
{
    public function register(Application $app): void
    {
        OrderLog::$calls[] = 'alpha-second:register';
    }
}

final class ZuluProvider extends PackageServiceProvider
{
    public function register(Application $app): void
    {
        OrderLog::$calls[] = 'zulu:register';
    }
}

final class NeedsArgumentsProvider extends PackageServiceProvider
{
    public function __construct(public readonly string $required) {}
}

#[CoversClass(PackageManifest::class)]
#[CoversClass(Packages::class)]
#[CoversClass(PackageServiceProvider::class)]
#[CoversClass(Publishables::class)]
#[CoversClass(CommandRegistry::class)]
#[CoversClass(DiscoverCommand::class)]
#[CoversClass(PublishCommand::class)]
#[CoversClass(Config::class)]
final class PackageDiscoveryTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        OrderLog::$calls = [];
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'veyrophp-pkg-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/vendor/composer', 0777, true);
        mkdir($this->root . '/packages/alpha/config', 0777, true);
        file_put_contents($this->root . '/packages/alpha/config/alpha.php', "<?php return ['greeting' => 'Hello', 'channels' => ['mail', 'sms'], 'nested' => ['a' => 1, 'b' => 2]];");
        AlphaProvider::$configFile = $this->root . '/packages/alpha/config/alpha.php';

        $this->installed([
            // Listed out of order on purpose: discovery sorts by name.
            ['name' => 'zulu/tools', 'version' => '2.0.0', 'extra' => ['veyrophp' => ['providers' => [ZuluProvider::class]]]],
            ['name' => 'plain/library', 'version' => '1.0.0'],
            ['name' => 'acme/alpha', 'version' => '1.2.0', 'extra' => ['veyrophp' => ['providers' => ['\\' . AlphaProvider::class, AlphaSecondProvider::class]]]],
            ['name' => 'evil/odd', 'version' => '0.1.0', 'extra' => ['veyrophp' => ['providers' => ['not a class', 42, 'Evil\\Ok\\Provider']]]],
            ['name' => 'Bad Name!', 'extra' => ['veyrophp' => ['providers' => [ZuluProvider::class]]]],
        ]);
    }

    protected function tearDown(): void
    {
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            \assert($item instanceof \SplFileInfo);
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    /**
     * @param list<array<string, mixed>> $packages
     */
    private function installed(array $packages): void
    {
        file_put_contents($this->root . '/vendor/composer/installed.json', json_encode(['packages' => $packages, 'dev' => true], JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function app(array $config = []): Application
    {
        $app = new Application($this->root);
        $app->container()->instance(Config::class, new Config($config));
        $app->register(new ConsoleServiceProvider());

        return $app;
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{int, string}
     */
    private function exec(Application $app, array $arguments): array
    {
        $out = fopen('php://memory', 'w+');
        self::assertIsResource($out);
        $code = $app->container()->make(Console::class)->run($arguments, new Output($out, $out));
        rewind($out);

        return [$code, str_replace(PHP_EOL, "\n", (string) stream_get_contents($out))];
    }

    public function testDiscoveryIsSortedValidatedAndCached(): void
    {
        $manifest = new PackageManifest($this->root);
        $packages = $manifest->build();

        self::assertSame(['acme/alpha', 'evil/odd', 'zulu/tools'], array_keys($packages), 'sorted by name; packages without extra.veyrophp ignored');
        self::assertSame([AlphaProvider::class, AlphaSecondProvider::class], $packages['acme/alpha']['providers'], 'package order kept, leading backslash removed');
        self::assertSame(['Evil\\Ok\\Provider'], $packages['evil/odd']['providers']);
        self::assertCount(3, $manifest->warnings(), 'two invalid providers and one invalid package name reported');
        self::assertFileExists($this->root . '/' . PackageManifest::CACHE);

        // installed.json changes (composer update): the cache is rebuilt automatically.
        touch($this->root . '/' . PackageManifest::CACHE, time() - 10);
        $this->installed([['name' => 'zulu/tools', 'version' => '3.0.0', 'extra' => ['veyrophp' => ['providers' => [ZuluProvider::class]]]]]);
        self::assertSame(['zulu/tools'], array_keys((new PackageManifest($this->root))->packages()));

        self::assertSame([ZuluProvider::class], (new PackageManifest($this->root))->providers());
        self::assertSame([], (new PackageManifest($this->root))->providers(['*']));
        self::assertSame([], (new PackageManifest($this->root))->providers(['zulu/tools']));
    }

    public function testDiscoveryNeverRunsPackageCode(): void
    {
        // A provider class that does not exist cannot be loaded: discovery still works.
        $this->installed([['name' => 'ghost/pkg', 'extra' => ['veyrophp' => ['providers' => ['Ghost\\Missing\\Provider']]]]]);

        self::assertSame(['Ghost\\Missing\\Provider'], (new PackageManifest($this->root))->providers());

        $this->expectException(PackageException::class);
        $this->expectExceptionMessage('Ghost\\Missing\\Provider does not exist');
        Packages::register($this->app());
    }

    public function testRegistrationOrderConfigCommandsAndOptOut(): void
    {
        $this->installed([
            ['name' => 'zulu/tools', 'extra' => ['veyrophp' => ['providers' => [ZuluProvider::class]]]],
            ['name' => 'acme/alpha', 'extra' => ['veyrophp' => ['providers' => [AlphaProvider::class, AlphaSecondProvider::class]]]],
        ]);
        $app = $this->app(['alpha' => ['greeting' => 'Namaste', 'channels' => ['mail'], 'nested' => ['a' => 9]]]);

        $registered = Packages::register($app);
        $app->boot();

        self::assertSame([AlphaProvider::class, AlphaSecondProvider::class, ZuluProvider::class], $registered);
        self::assertSame(['alpha:register', 'alpha-second:register', 'zulu:register', 'alpha:boot'], OrderLog::$calls);

        $config = $app->container()->make(Config::class);
        self::assertSame('Namaste', $config->get('alpha.greeting'), 'the application wins');
        self::assertSame(['mail'], $config->get('alpha.channels'), 'lists from the application are not merged with defaults');
        self::assertSame(['a' => 9, 'b' => 2], $config->get('alpha.nested'), 'missing nested keys come from the package');

        self::assertSame([0, "hello from alpha\n"], $this->exec($app, ['alpha:hello']), 'package commands are available');

        // A provider the application registered itself is not registered again.
        $explicit = $this->app();
        $explicit->register(new ZuluProvider());
        self::assertSame([AlphaProvider::class, AlphaSecondProvider::class], Packages::register($explicit));

        $optOut = $this->app(['packages' => ['dont_discover' => ['acme/alpha']]]);
        self::assertSame([ZuluProvider::class], Packages::register($optOut));
    }

    public function testDefaultsWhenTheApplicationHasNoConfig(): void
    {
        $this->installed([['name' => 'acme/alpha', 'extra' => ['veyrophp' => ['providers' => [AlphaProvider::class]]]]]);
        $app = $this->app();
        Packages::register($app);

        self::assertSame(['greeting' => 'Hello', 'channels' => ['mail', 'sms'], 'nested' => ['a' => 1, 'b' => 2]], $app->container()->make(Config::class)->get('alpha'));
    }

    public function testInvalidProviders(): void
    {
        foreach ([
            [NeedsArgumentsProvider::class, 'constructible without arguments'],
            [OrderLog::class, 'must be a concrete class implementing'],
        ] as [$class, $message]) {
            try {
                Packages::instantiate($class);
                self::fail('expected PackageException for ' . $class);
            } catch (PackageException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    public function testPublishing(): void
    {
        $this->installed([['name' => 'acme/alpha', 'extra' => ['veyrophp' => ['providers' => [AlphaProvider::class]]]]]);
        $app = $this->app();
        Packages::register($app);
        $app->boot();

        [$code, $out] = $this->exec($app, ['vendor:publish', '--list']);
        self::assertSame(0, $code);
        self::assertStringContainsString('alpha-config', $out);
        self::assertFileDoesNotExist($this->root . '/config/alpha.php', '--list changes nothing');

        self::assertSame([0, "Published config/alpha.php\n"], $this->exec($app, ['vendor:publish', '--tag=alpha-config']));
        file_put_contents($this->root . '/config/alpha.php', '<?php return ["greeting" => "edited"];');

        self::assertStringContainsString('Skipped config/alpha.php (exists; use --force to overwrite)', $this->exec($app, ['vendor:publish'])[1]);
        self::assertStringContainsString('edited', (string) file_get_contents($this->root . '/config/alpha.php'));

        $this->exec($app, ['vendor:publish', '--force']);
        self::assertStringContainsString('Hello', (string) file_get_contents($this->root . '/config/alpha.php'));
        self::assertSame([0, "Nothing to publish.\n"], $this->exec($app, ['vendor:publish', '--tag=other']));

        foreach (['../outside.php', '/etc/passwd', 'C:/x.php', 'config\\x.php', 'a/./b.php', ''] as $target) {
            try {
                (new Publishables())->add('X', 'tag', ['/tmp/a' => $target]);
                self::fail('target accepted: ' . $target);
            } catch (PackageException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testDiscoverCommand(): void
    {
        [$code, $out] = $this->exec($this->app(['packages' => ['dont_discover' => ['zulu/tools']]]), ['package:discover']);

        self::assertSame(0, $code);
        self::assertStringContainsString('acme/alpha', $out);
        self::assertStringContainsString('skipped (dont_discover)', $out);
        self::assertStringContainsString('invalid provider entry', $out);
        self::assertStringContainsString('only install packages you trust', $out);
    }

    /**
     * CORE-4: a manifest that cannot be written is reported, leaves no temporary file, and
     * fails package:discover; discovery itself still returns the packages.
     */
    public function testAManifestThatCannotBeWrittenIsReported(): void
    {
        // bootstrap/cache is a file: the directory cannot be created.
        mkdir($this->root . '/bootstrap');
        file_put_contents($this->root . '/bootstrap/cache', 'not a directory');

        $manifest = new PackageManifest($this->root);
        self::assertSame(['acme/alpha', 'evil/odd', 'zulu/tools'], array_keys($manifest->build()), 'discovery still works');
        self::assertStringContainsString('Could not write bootstrap/cache/packages.php', implode("\n", $manifest->warnings()));

        [$code, $out] = $this->exec($this->app(), ['package:discover']);
        self::assertSame(1, $code);
        self::assertStringContainsString('Could not write bootstrap/cache/packages.php', $out);

        // packages.php is a directory: the temporary file is written, the rename fails.
        unlink($this->root . '/bootstrap/cache');
        mkdir($this->root . '/bootstrap/cache/packages.php', 0777, true);

        [$code, $out] = $this->exec($this->app(), ['package:discover']);
        self::assertSame(1, $code);
        self::assertStringContainsString('Could not write bootstrap/cache/packages.php', $out);
        self::assertSame([], glob($this->root . '/bootstrap/cache/*.tmp') ?: [], 'no temporary file is left');
    }
}
