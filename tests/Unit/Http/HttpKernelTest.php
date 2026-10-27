<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Unit\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use stdClass;
use VeyroPHP\Container\Container;
use VeyroPHP\Http\CallableHandler;
use VeyroPHP\Http\ContentNegotiation;
use VeyroPHP\Http\Exception\HttpException;
use VeyroPHP\Http\Exception\MethodNotAllowedHttpException;
use VeyroPHP\Http\Exception\NotFoundHttpException;
use VeyroPHP\Http\HttpErrorResponder;
use VeyroPHP\Http\HttpKernel;
use VeyroPHP\Http\Middleware\Pipeline;
use VeyroPHP\Http\ResponseFactory;
use VeyroPHP\Routing\HandlerInvoker;
use VeyroPHP\Routing\Route;
use VeyroPHP\Routing\Router;
use VeyroPHP\Tests\Fixtures\Http\FailingController;
use VeyroPHP\Tests\Fixtures\Http\InvokableController;
use VeyroPHP\Tests\Fixtures\Http\Item;
use VeyroPHP\Tests\Fixtures\Http\ShortCircuitMiddleware;
use VeyroPHP\Tests\Fixtures\Http\TraceMiddleware;
use VeyroPHP\Tests\Fixtures\Http\UserController;
use VeyroPHP\Tests\Fixtures\Logging\ArrayLogger;

#[CoversClass(HttpKernel::class)]
#[CoversClass(HandlerInvoker::class)]
#[CoversClass(HttpErrorResponder::class)]
#[CoversClass(Pipeline::class)]
#[CoversClass(CallableHandler::class)]
#[CoversClass(ContentNegotiation::class)]
#[CoversClass(HttpException::class)]
#[CoversClass(NotFoundHttpException::class)]
#[CoversClass(MethodNotAllowedHttpException::class)]
#[CoversClass(ResponseFactory::class)]
final class HttpKernelTest extends TestCase
{
    private Container $container;

    private Router $router;

    private ArrayLogger $logger;

    protected function setUp(): void
    {
        $this->container = new Container();
        $this->router = new Router();
        $this->logger = new ArrayLogger();

        $psr17 = new Psr17Factory();
        $this->container->instance(ResponseFactory::class, new ResponseFactory($psr17, $psr17));
    }

    /**
     * @param list<\Psr\Http\Server\MiddlewareInterface|class-string<\Psr\Http\Server\MiddlewareInterface>> $middleware
     */
    private function kernel(array $middleware = [], bool $debug = false, ?string $basePath = null): HttpKernel
    {
        $responses = $this->container->make(ResponseFactory::class);

        return new HttpKernel(
            $this->router,
            $this->container,
            new HandlerInvoker($this->container, $responses),
            new HttpErrorResponder($responses, $this->logger, $debug),
            $responses,
            $middleware,
            $basePath,
        );
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $server
     */
    private static function request(string $method, string $uri, array $headers = [], array $server = []): ServerRequestInterface
    {
        return new ServerRequest($method, $uri, $headers, null, '1.1', $server);
    }

    private static function body(ResponseInterface $response): string
    {
        return (string) $response->getBody();
    }

    // Handlers and return values

    public function testStringReturnBecomesHtmlResponse(): void
    {
        $this->router->get('/', static fn(): string => '<h1>Home</h1>');

        $response = $this->kernel()->handle(self::request('GET', '/'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('<h1>Home</h1>', self::body($response));
    }

    public function testArrayAndJsonSerializableReturnBecomeJson(): void
    {
        $this->router->get('/a', static fn(): array => ['name' => 'VeyroPHP', 'url' => 'https://x.test/']);
        $this->router->get('/b', static fn(): Item => new Item());

        $a = $this->kernel()->handle(self::request('GET', '/a'));
        self::assertSame('application/json', $a->getHeaderLine('Content-Type'));
        self::assertSame('{"name":"VeyroPHP","url":"https://x.test/"}', self::body($a));
        self::assertSame('{"item":true}', self::body($this->kernel()->handle(self::request('GET', '/b'))));
    }

    public function testNullReturnBecomes204(): void
    {
        $this->router->delete('/items/{id}', static function (): void {});

        $response = $this->kernel()->handle(self::request('DELETE', '/items/1'));

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('', self::body($response));
    }

    public function testUnsupportedReturnTypeIs500(): void
    {
        $this->router->get('/', static fn(): stdClass => new stdClass());

        $response = $this->kernel()->handle(self::request('GET', '/'));

        self::assertSame(500, $response->getStatusCode());
        $message = $this->logger->records[0]['context']['message'];
        self::assertIsString($message);
        self::assertStringContainsString('returned stdClass', $message);
    }

    public function testControllerResolvedThroughContainerWithTypedParameters(): void
    {
        $this->router->get('/users/{id}', [UserController::class, 'show']);
        $this->router->get('/greet/{name}', [UserController::class, 'greet']);
        $this->router->get('/static', [UserController::class, 'staticAction']);

        self::assertSame('{"id":42,"path":"/users/0042"}', self::body($this->kernel()->handle(self::request('GET', '/users/0042'))));
        self::assertSame('Hello, Ravi Kumar', self::body($this->kernel()->handle(self::request('GET', '/greet/Ravi%20Kumar'))));
        self::assertSame('static', self::body($this->kernel()->handle(self::request('GET', '/static'))));
    }

    public function testInvalidIntParameterGives404(): void
    {
        $this->router->get('/users/{id}', [UserController::class, 'show']);

        foreach (['abc', '1.5', '99999999999999999999999'] as $value) {
            self::assertSame(404, $this->kernel()->handle(self::request('GET', '/users/' . $value))->getStatusCode(), $value);
        }
    }

    public function testFloatParameter(): void
    {
        $this->router->get('/price/{amount}', static fn(float $amount): array => ['amount' => $amount]);

        self::assertSame('{"amount":2.5}', self::body($this->kernel()->handle(self::request('GET', '/price/2.5'))));
        self::assertSame(404, $this->kernel()->handle(self::request('GET', '/price/abc'))->getStatusCode());
    }

    public function testInvokableControllerWithServiceInjection(): void
    {
        $this->router->post('/invoke', InvokableController::class);

        $response = $this->kernel()->handle(self::request('POST', '/invoke'));

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('invoked', self::body($response));
    }

    public function testHandlerDefaultValueAndUnresolvableArgument(): void
    {
        $this->router->get('/default', static fn(string $missing = 'fallback'): string => $missing);
        $this->router->get('/broken', static fn(string $missing): string => $missing);

        self::assertSame('fallback', self::body($this->kernel()->handle(self::request('GET', '/default'))));
        self::assertSame(500, $this->kernel()->handle(self::request('GET', '/broken'))->getStatusCode());
    }

    public function testRouteParametersAndRouteAreRequestAttributes(): void
    {
        $route = $this->router->get('/posts/{slug}', static fn(ServerRequestInterface $request): array => [
            'slug' => $request->getAttribute('slug'),
            'route' => $request->getAttribute(HttpKernel::ROUTE_ATTRIBUTE) instanceof Route,
        ]);

        self::assertSame('{"slug":"hello","route":true}', self::body($this->kernel()->handle(self::request('GET', '/posts/hello'))));
        self::assertSame('/posts/{slug}', $route->path());
    }

    // 404 / 405 / errors

    public function testNotFound(): void
    {
        $response = $this->kernel()->handle(self::request('GET', '/missing'));

        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('404 | Not Found', self::body($response));
        self::assertSame([], $this->logger->records, '4xx must not be logged');
    }

    public function testMethodNotAllowedSetsAllowHeader(): void
    {
        $this->router->get('/items', static fn(): string => 'list');
        $this->router->post('/items', static fn(): string => 'create');

        $response = $this->kernel()->handle(self::request('DELETE', '/items'));

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('GET, HEAD, POST', $response->getHeaderLine('Allow'));
    }

    public function testJsonErrorWhenClientPrefersJson(): void
    {
        $response = $this->kernel()->handle(self::request('GET', '/missing', ['Accept' => 'application/json']));

        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame('{"error":{"status":404,"message":"Not Found"}}', self::body($response));
    }

    public function testHttpExceptionMessageAndHeadersAreUsed(): void
    {
        $this->router->get('/limited', static function (): never {
            throw new HttpException(429, 'Slow down.', ['Retry-After' => '60']);
        });

        $response = $this->kernel()->handle(self::request('GET', '/limited', ['Accept' => 'application/json']));

        self::assertSame(429, $response->getStatusCode());
        self::assertSame('60', $response->getHeaderLine('Retry-After'));
        self::assertSame('{"error":{"status":429,"message":"Slow down."}}', self::body($response));
    }

    public function testUnexpectedExceptionIsLoggedAndHiddenInProduction(): void
    {
        $this->router->get('/fail', FailingController::class);

        $html = $this->kernel()->handle(self::request('GET', '/fail'));
        $json = $this->kernel()->handle(self::request('GET', '/fail', ['Accept' => 'application/json']));

        self::assertSame(500, $html->getStatusCode());
        self::assertStringNotContainsString('hunter2', self::body($html));
        self::assertStringNotContainsString('RuntimeException', self::body($html));
        self::assertSame('{"error":{"status":500,"message":"Internal Server Error"}}', self::body($json));

        self::assertSame('critical', $this->logger->records[0]['level']);
        self::assertSame('GET', $this->logger->records[0]['context']['method']);
        self::assertSame('/fail', $this->logger->records[0]['context']['path']);
    }

    public function testDebugShowsExceptionDetails(): void
    {
        $this->router->get('/fail', FailingController::class);

        $html = self::body($this->kernel(debug: true)->handle(self::request('GET', '/fail')));
        $json = self::body($this->kernel(debug: true)->handle(self::request('GET', '/fail', ['Accept' => 'application/json'])));

        self::assertStringContainsString('RuntimeException', $html);
        self::assertStringContainsString('database password=hunter2 is wrong', $html);
        self::assertStringContainsString('"exception":{"class":"RuntimeException"', $json);
    }

    public function testFiveHundredHttpExceptionIsLoggedAsError(): void
    {
        $this->router->get('/down', static function (): never {
            throw new HttpException(503);
        });

        self::assertSame(503, $this->kernel()->handle(self::request('GET', '/down'))->getStatusCode());
        self::assertSame('error', $this->logger->records[0]['level']);
    }

    // Middleware

    public function testGlobalThenRouteMiddlewareRunInOrder(): void
    {
        $this->router->get('/', static fn(ServerRequestInterface $r): string => is_string($trace = $r->getAttribute('trace')) ? $trace : '')
            ->middleware(new TraceMiddleware('route'));

        $response = $this->kernel([new TraceMiddleware('global1'), new TraceMiddleware('global2')])->handle(self::request('GET', '/'));

        self::assertSame('global1>global2>route>', self::body($response));
        self::assertSame(['route', 'global2', 'global1'], $response->getHeader('X-Trace'));
    }

    public function testMiddlewareResolvedFromContainerAndCanShortCircuit(): void
    {
        $this->router->get('/admin', static fn(): string => 'secret')->middleware(ShortCircuitMiddleware::class);

        $response = $this->kernel()->handle(self::request('GET', '/admin'));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('blocked', self::body($response));
    }

    public function testInvalidMiddlewareClassIs500(): void
    {
        $this->router->get('/', static fn(): string => 'x')->middleware(stdClass::class);

        self::assertSame(500, $this->kernel()->handle(self::request('GET', '/'))->getStatusCode());
    }

    public function testGlobalMiddlewareRunsForNotFound(): void
    {
        $response = $this->kernel([new TraceMiddleware('global')])->handle(self::request('GET', '/missing'));

        // The 404 is produced after the middleware ran, so its header is not added.
        self::assertSame(404, $response->getStatusCode());
    }

    // HEAD, base path, scope

    public function testHeadRemovesBodyButKeepsHeaders(): void
    {
        $this->router->get('/page', static fn(): string => 'content');

        $response = $this->kernel()->handle(self::request('HEAD', '/page'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('', self::body($response));
    }

    public function testResponseUsesRequestProtocolVersion(): void
    {
        // Answering an HTTP/1.0 request with an "HTTP/1.1" status line made Apache keep the
        // connection open until KeepAliveTimeout (5 s per request with ab). Found by benchmarking.
        $this->router->get('/', static fn(): string => 'ok');

        $request = self::request('GET', '/')->withProtocolVersion('1.0');

        self::assertSame('1.0', $this->kernel()->handle($request)->getProtocolVersion());
        self::assertSame('1.0', $this->kernel()->handle(self::request('GET', '/missing')->withProtocolVersion('1.0'))->getProtocolVersion());
        self::assertSame('1.1', $this->kernel()->handle(self::request('GET', '/'))->getProtocolVersion());
    }

    public function testBasePathDetectedFromScriptName(): void
    {
        $this->router->get('/', static fn(): string => 'home');
        $this->router->get('/users', static fn(): string => 'users');
        $server = ['SCRIPT_NAME' => '/veyrophp/skeleton/public/index.php'];
        $kernel = $this->kernel();

        self::assertSame('home', self::body($kernel->handle(self::request('GET', '/veyrophp/skeleton/public/', [], $server))));
        self::assertSame('home', self::body($kernel->handle(self::request('GET', '/veyrophp/skeleton/public', [], $server))));
        self::assertSame('users', self::body($kernel->handle(self::request('GET', '/veyrophp/skeleton/public/users', [], $server))));
        self::assertSame('users', self::body($kernel->handle(self::request('GET', '/veyrophp/skeleton/public/index.php/users', [], $server))));
        self::assertSame(404, $kernel->handle(self::request('GET', '/veyrophp/skeleton/publicusers', [], $server))->getStatusCode());
    }

    public function testLocalRedirectsGetTheBasePath(): void
    {
        $responses = $this->container->make(ResponseFactory::class);
        $this->router->get('/go', static fn(): ResponseInterface => $responses->redirect('/login'));
        $this->router->get('/already', static fn(): ResponseInterface => $responses->redirect('/app/public/x'));
        $this->router->get('/already-full', static fn(): ResponseInterface => $responses->redirect('/app/public/index.php/x'));
        $this->router->get('/external', static fn(): ResponseInterface => $responses->redirect('https://example.com/', allowExternal: true));
        $server = ['SCRIPT_NAME' => '/app/public/index.php'];
        $kernel = $this->kernel();

        self::assertSame('/app/public/login', $kernel->handle(self::request('GET', '/app/public/go', [], $server))->getHeaderLine('Location'));
        self::assertSame('/app/public/login', $kernel->handle(self::request('GET', '/app/public/index.php/go', [], $server))->getHeaderLine('Location'));
        self::assertSame('/app/public/x', $kernel->handle(self::request('GET', '/app/public/already', [], $server))->getHeaderLine('Location'));
        // HTTP-2: an already prefixed location is recognised through index.php as well.
        self::assertSame('/app/public/x', $kernel->handle(self::request('GET', '/app/public/index.php/already', [], $server))->getHeaderLine('Location'));
        self::assertSame('/app/public/index.php/x', $kernel->handle(self::request('GET', '/app/public/index.php/already-full', [], $server))->getHeaderLine('Location'));
        self::assertSame('https://example.com/', $kernel->handle(self::request('GET', '/app/public/external', [], $server))->getHeaderLine('Location'));
        self::assertSame('/login', $kernel->handle(self::request('GET', '/go', [], ['SCRIPT_NAME' => '/index.php']))->getHeaderLine('Location'));
    }

    /**
     * HTTP-3: the base path is resolved once per request and handed on as attributes:
     * the routing base and the base for files in public/.
     */
    public function testBasePathsAreResolvedOnceIntoRequestAttributes(): void
    {
        $this->router->get('/where', static fn(ServerRequestInterface $r): string => implode('|', array_map(
            static fn(string $name): string => var_export($r->getAttribute($name), true),
            [HttpKernel::BASE_ATTRIBUTE, HttpKernel::PUBLIC_BASE_ATTRIBUTE, HttpKernel::PATH_ATTRIBUTE],
        )));
        $server = ['SCRIPT_NAME' => '/app/public/index.php'];
        $kernel = $this->kernel();

        self::assertSame("'/app/public/index.php'|'/app/public'|'/where'", self::body($kernel->handle(self::request('GET', '/app/public/index.php/where', [], $server))));
        self::assertSame("'/app/public'|'/app/public'|'/where'", self::body($kernel->handle(self::request('GET', '/app/public/where', [], $server))));
        self::assertSame("'/app'|'/app'|'/where'", self::body($kernel->handle(self::request('GET', '/app/where', [], $server))));
        self::assertSame("''|''|'/where'", self::body($kernel->handle(self::request('GET', '/where', [], ['SCRIPT_NAME' => '/index.php']))));
        self::assertSame("'/fixed'|'/fixed'|'/where'", self::body($this->kernel(basePath: '/fixed')->handle(self::request('GET', '/fixed/where'))));
    }

    public function testCleanUrlsWithoutPublicSegment(): void
    {
        $responses = $this->container->make(ResponseFactory::class);
        $this->router->get('/', static fn(): string => 'home');
        $this->router->get('/users', static fn(): string => 'users');
        $this->router->get('/go', static fn(): ResponseInterface => $responses->redirect('/login'));
        $server = ['SCRIPT_NAME' => '/veyrophp-app/public/index.php'];
        $kernel = $this->kernel();

        self::assertSame('users', self::body($kernel->handle(self::request('GET', '/veyrophp-app/users', [], $server))));
        self::assertSame('home', self::body($kernel->handle(self::request('GET', '/veyrophp-app/', [], $server))));
        self::assertSame('home', self::body($kernel->handle(self::request('GET', '/veyrophp-app', [], $server))));
        self::assertSame('users', self::body($kernel->handle(self::request('GET', '/veyrophp-app/public/users', [], $server))), 'URLs with public keep working');
        self::assertSame('/veyrophp-app/login', $kernel->handle(self::request('GET', '/veyrophp-app/go', [], $server))->getHeaderLine('Location'));
        self::assertSame(404, $kernel->handle(self::request('GET', '/other-app/users', [], $server))->getStatusCode());
    }

    public function testRootScriptNameDoesNotStripAnything(): void
    {
        $this->router->get('/users', static fn(): string => 'users');

        self::assertSame('users', self::body($this->kernel()->handle(self::request('GET', '/users', [], ['SCRIPT_NAME' => '/index.php']))));
    }

    public function testExplicitBasePath(): void
    {
        $this->router->get('/users', static fn(): string => 'users');

        self::assertSame('users', self::body($this->kernel(basePath: '/app/')->handle(self::request('GET', '/app/users'))));
    }

    public function testScopedServicesAreResetAfterEachRequest(): void
    {
        $this->container->scoped(stdClass::class);
        $this->router->get('/', fn(): string => (string) spl_object_id($this->container->make(stdClass::class)));

        $first = $this->container->get(stdClass::class);
        $this->kernel()->handle(self::request('GET', '/'));

        self::assertNotSame($first, $this->container->get(stdClass::class));
    }

    public function testContentNegotiation(): void
    {
        $cases = [
            '' => false,
            'text/html' => false,
            'application/json' => true,
            'application/problem+json' => true,
            'text/html, application/json' => false,
            'text/html;q=0.5, application/json' => true,
            'application/json;q=0.4, text/html;q=0.9' => false,
            '*/*' => false,
        ];

        foreach ($cases as $accept => $expected) {
            self::assertSame($expected, ContentNegotiation::prefersJson(self::request('GET', '/', $accept === '' ? [] : ['Accept' => $accept])), $accept);
        }
    }
}
