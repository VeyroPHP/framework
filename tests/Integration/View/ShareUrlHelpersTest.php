<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Integration\View;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use VeyroPHP\Application\Application;
use VeyroPHP\Config\Config;
use VeyroPHP\Container\Container;
use VeyroPHP\Http\HttpErrorResponder;
use VeyroPHP\Http\HttpKernel;
use VeyroPHP\Http\ResponseFactory;
use VeyroPHP\Routing\HandlerInvoker;
use VeyroPHP\Routing\Router;
use VeyroPHP\View\ShareUrlHelpers;
use VeyroPHP\View\View;

/**
 * Priority C: views get absolute() from app.url and asset() versions from public/.
 */
#[CoversClass(ShareUrlHelpers::class)]
final class ShareUrlHelpersTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/veyrophp-share-urls-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/public/css', 0o777, true);
        mkdir($this->root . '/views');
        file_put_contents($this->root . '/public/css/site.css', 'body{}');
        touch($this->root . '/public/css/site.css', 1_800_000_000);
        file_put_contents($this->root . '/views/page.php', '<?= $e($urls->absolute()) ?>|<?= $e($urls->asset(\'/css/site.css\')) ?>|<?= $e($urls->asset(\'/css/missing.css\')) ?>');
        file_put_contents($this->root . '/views/paths.php', '<?= $e($assetBase) ?>|<?= $e($urls->asset(\'/css/missing.css\')) ?>|<?= $e($urls->to(\'/login\')) ?>|<?= $e($urls->base()) ?>');
    }

    protected function tearDown(): void
    {
        unlink($this->root . '/public/css/site.css');
        unlink($this->root . '/views/page.php');
        unlink($this->root . '/views/paths.php');
        rmdir($this->root . '/public/css');
        rmdir($this->root . '/public');
        rmdir($this->root . '/views');
        rmdir($this->root);
    }

    public function testViewsGetAbsoluteAndVersionedAssetUrls(): void
    {
        $body = $this->render(new Config(['app' => ['url' => 'https://example.com/ignored-path']]));

        self::assertSame('https://example.com/about|/css/site.css?v=' . base_convert('1800000000', 10, 36) . '|/css/missing.css', $body);
    }

    public function testAbsoluteWithoutAppUrlFailsLoudly(): void
    {
        $body = $this->render(new Config([]));

        self::assertStringContainsString('500', $body);
    }

    /**
     * HTTP-3: through "/app/public/index.php/..." routes keep the front controller, but
     * files in public/ must not get it, or the web server hands them to PHP (404).
     */
    public function testStaticFilesNeverGetTheFrontControllerInTheirPath(): void
    {
        $server = ['SCRIPT_NAME' => '/app/public/index.php'];

        self::assertSame(
            '/app/public|/app/public/css/missing.css|/app/public/index.php/login|/app/public/index.php',
            $this->render(new Config([]), '/app/public/index.php/paths', $server, null),
        );
        self::assertSame(
            '/app/public|/app/public/css/missing.css|/app/public/login|/app/public',
            $this->render(new Config([]), '/app/public/paths', $server, null),
            'without index.php in the URL both are the same',
        );
        self::assertSame('/app|/app/css/missing.css|/app/login|/app', $this->render(new Config([]), '/app/paths', $server, null), 'clean URLs from a rewrite in the project root');
        self::assertSame('|/css/missing.css|/login|', $this->render(new Config([]), '/paths', ['SCRIPT_NAME' => '/index.php'], null), 'at the web root');
    }

    /**
     * @param array<string, string> $server
     */
    private function render(Config $config, string $path = '/about', array $server = [], ?string $basePath = ''): string
    {
        $container = new Container();
        $app = new Application($this->root, $container);
        $container->instance(Config::class, $config);
        $container->instance(View::class, new View($this->root . '/views'));
        $psr17 = new Psr17Factory();
        $responses = new ResponseFactory($psr17, $psr17);
        $router = new Router();
        $router->get('/about', static fn(View $view): string => $view->render('page'));
        $router->get('/paths', static fn(View $view): string => $view->render('paths'));

        $kernel = new HttpKernel(
            $router,
            $container,
            new HandlerInvoker($container, $responses),
            new HttpErrorResponder($responses),
            $responses,
            [new ShareUrlHelpers($container)],
            $basePath,
        );
        $container->instance(HttpKernel::class, $kernel);
        $container->instance(Router::class, $router);
        self::assertInstanceOf(Application::class, $container->get(Application::class));

        $response = $kernel->handle(new ServerRequest('GET', $path, [], null, '1.1', $server));

        return $response->getStatusCode() === 200 ? (string) $response->getBody() : (string) $response->getStatusCode();
    }
}
