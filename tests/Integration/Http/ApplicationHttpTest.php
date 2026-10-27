<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Integration\Http;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseFactoryInterface;
use VeyroPHP\Application\Application;
use VeyroPHP\Config\ConfigServiceProvider;
use VeyroPHP\Config\Env\EnvLoader;
use VeyroPHP\Http\Exception\HttpException;
use VeyroPHP\Http\HttpKernel;
use VeyroPHP\Http\HttpServiceProvider;
use VeyroPHP\Http\Middleware\JsonBodyParser;
use VeyroPHP\Routing\Router;
use VeyroPHP\View\ViewServiceProvider;

/**
 * Boots a complete application from files on disk and sends requests through the kernel.
 */
#[CoversClass(HttpServiceProvider::class)]
#[CoversClass(ViewServiceProvider::class)]
final class ApplicationHttpTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $resolved = realpath(sys_get_temp_dir());
        self::assertIsString($resolved);
        $this->root = $resolved . DIRECTORY_SEPARATOR . 'veyrophp-app-' . bin2hex(random_bytes(6));

        $this->write('config/app.php', "<?php return ['name' => \$env->string('APP_NAME', 'VeyroPHP'), 'debug' => \$env->bool('APP_DEBUG', false)];");
        $this->write('resources/views/welcome.php', '<h1>Welcome to <?= $e($name) ?></h1>');
        $this->write('routes/web.php', <<<'PHP'
            <?php
            use VeyroPHP\Config\Config;
            use VeyroPHP\View\View;

            $router->get('/', static fn (View $view, Config $config): string => $view->render('welcome', [
                'name' => $config->string('app.name'),
            ]))->name('home');

            $router->post('/api/echo', static fn (Psr\Http\Message\ServerRequestInterface $request): array => [
                'received' => $request->getParsedBody(),
            ]);

            $router->get('/fail', static function (): never {
                throw new RuntimeException('secret failure');
            });
            PHP);
    }

    protected function tearDown(): void
    {
        self::removeDirectory($this->root);
    }

    private function write(string $relative, string $contents): void
    {
        $path = $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);

        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }

        file_put_contents($path, $contents);
    }

    /**
     * @param array<string, string> $env
     */
    private function boot(array $env = []): Application
    {
        $app = new Application($this->root);
        $app->register(new ConfigServiceProvider(new EnvLoader($env)));
        $app->register(new ViewServiceProvider());
        $app->register(new HttpServiceProvider(['routes/web.php'], [JsonBodyParser::class]));
        $app->boot();

        return $app;
    }

    public function testServesWelcomePageFromRouteFileAndView(): void
    {
        $kernel = $this->boot(['APP_NAME' => 'My <App>'])->container()->make(HttpKernel::class);

        $response = $kernel->handle(new ServerRequest('GET', '/'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('<h1>Welcome to My &lt;App&gt;</h1>', (string) $response->getBody());
    }

    public function testGlobalMiddlewareFromProviderParsesJson(): void
    {
        $kernel = $this->boot()->container()->make(HttpKernel::class);

        $response = $kernel->handle(new ServerRequest('POST', '/api/echo', ['Content-Type' => 'application/json'], '{"a":1}'));

        self::assertSame('{"received":{"a":1}}', (string) $response->getBody());
    }

    public function testDebugFlagComesFromConfig(): void
    {
        $production = $this->boot()->container()->make(HttpKernel::class)->handle(new ServerRequest('GET', '/fail'));
        $debug = $this->boot(['APP_DEBUG' => 'true'])->container()->make(HttpKernel::class)->handle(new ServerRequest('GET', '/fail'));

        self::assertStringNotContainsString('secret failure', (string) $production->getBody());
        self::assertStringContainsString('secret failure', (string) $debug->getBody());
    }

    public function testServicesAreRegisteredAsSingletons(): void
    {
        $container = $this->boot()->container();

        self::assertSame($container->get(HttpKernel::class), $container->get(HttpKernel::class));
        self::assertSame($container->get(Router::class), $container->get(Router::class));
        self::assertSame('/', $container->make(Router::class)->path('home'));
        self::assertInstanceOf(ResponseFactoryInterface::class, $container->get(ResponseFactoryInterface::class));
    }

    public function testMissingRouteFileFailsBoot(): void
    {
        $app = new Application($this->root);
        $app->register(new HttpServiceProvider(['routes/missing.php']));

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Route file "routes/missing.php" does not exist.');

        $app->boot();
    }

    private static function removeDirectory(string $dir): void
    {
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
