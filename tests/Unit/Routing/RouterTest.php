<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Unit\Routing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VeyroPHP\Routing\Exception\RoutingException;
use VeyroPHP\Routing\Route;
use VeyroPHP\Routing\RouteMatch;
use VeyroPHP\Routing\Router;
use VeyroPHP\Tests\Fixtures\Http\InvokableController;
use VeyroPHP\Tests\Fixtures\Http\NotInvokable;
use VeyroPHP\Tests\Fixtures\Http\TraceMiddleware;
use VeyroPHP\Tests\Fixtures\Http\UserController;

#[CoversClass(Router::class)]
#[CoversClass(Route::class)]
#[CoversClass(RouteMatch::class)]
#[CoversClass(RoutingException::class)]
final class RouterTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        $this->router = new Router();
    }

    private static function handler(): \Closure
    {
        return static fn(): string => 'ok';
    }

    // Matching

    public function testMatchesStaticRoute(): void
    {
        $route = $this->router->get('/about', self::handler());

        $match = $this->router->match('GET', '/about');

        self::assertTrue($match->isFound());
        self::assertSame($route, $match->route);
        self::assertSame([], $match->parameters);
    }

    public function testMatchesRoot(): void
    {
        $this->router->get('/', self::handler());

        self::assertTrue($this->router->match('GET', '/')->isFound());
        self::assertTrue($this->router->match('GET', '')->isFound());
    }

    public function testExtractsParameters(): void
    {
        $this->router->get('/users/{id}/posts/{slug}', self::handler());

        self::assertSame(['id' => '42', 'slug' => 'hello-world'], $this->router->match('GET', '/users/42/posts/hello-world')->parameters);
    }

    public function testParameterMatchesOneSegmentByDefault(): void
    {
        $this->router->get('/files/{name}', self::handler());

        self::assertFalse($this->router->match('GET', '/files/a/b')->isFound());
    }

    public function testInlineAndWhereConstraints(): void
    {
        $this->router->get('/users/{id:\d+}', self::handler());
        $this->router->get('/posts/{year}', self::handler())->where('year', '\d{4}');

        self::assertTrue($this->router->match('GET', '/users/7')->isFound());
        self::assertFalse($this->router->match('GET', '/users/abc')->isFound());
        self::assertTrue($this->router->match('GET', '/posts/2026')->isFound());
        self::assertFalse($this->router->match('GET', '/posts/26')->isFound());
    }

    public function testConstraintMayContainDelimiterAndQuantifierBraces(): void
    {
        // "#" is the regex delimiter internally and must be escaped; "{2,5}" must not end the placeholder.
        $this->router->get('/tag/{tag:#?[a-z]{2,5}}', self::handler());

        self::assertTrue($this->router->match('GET', '/tag/abc')->isFound());
        self::assertFalse($this->router->match('GET', '/tag/abcdefg')->isFound());
    }

    public function testParametersWithinSegment(): void
    {
        $this->router->get('/download/{name}.{ext:[a-z]+}', self::handler());

        self::assertSame(['name' => 'report', 'ext' => 'pdf'], $this->router->match('GET', '/download/report.pdf')->parameters);
    }

    public function testStaticRouteWinsOverDynamicRegardlessOfOrder(): void
    {
        $dynamic = $this->router->get('/users/{id}', self::handler());
        $static = $this->router->get('/users/me', self::handler());

        self::assertSame($static, $this->router->match('GET', '/users/me')->route);
        self::assertSame($dynamic, $this->router->match('GET', '/users/5')->route);
    }

    public function testDynamicRoutesMatchInRegistrationOrder(): void
    {
        $first = $this->router->get('/p/{a}', self::handler());
        $this->router->get('/p/{b:\d+}', self::handler());

        self::assertSame($first, $this->router->match('GET', '/p/1')->route);
    }

    public function testTrailingSlashIsSignificant(): void
    {
        $this->router->get('/users', self::handler());

        self::assertTrue($this->router->match('GET', '/users')->isFound());
        self::assertFalse($this->router->match('GET', '/users/')->isFound());
    }

    public function testMethodIsCaseInsensitive(): void
    {
        $this->router->post('/items', self::handler());

        self::assertTrue($this->router->match('post', '/items')->isFound());
    }

    public function testHeadIsServedByGetRoutes(): void
    {
        $this->router->get('/page', self::handler());

        self::assertTrue($this->router->match('HEAD', '/page')->isFound());
    }

    public function testMapAndAllVerbHelpers(): void
    {
        $this->router->put('/r', self::handler());
        $this->router->patch('/r', self::handler());
        $this->router->delete('/r', self::handler());
        $this->router->options('/r', self::handler());
        $this->router->map(['get', 'POST'], '/m', self::handler());

        foreach (['PUT', 'PATCH', 'DELETE', 'OPTIONS'] as $method) {
            self::assertTrue($this->router->match($method, '/r')->isFound(), $method);
        }
        self::assertTrue($this->router->match('GET', '/m')->isFound());
        self::assertTrue($this->router->match('POST', '/m')->isFound());
    }

    // 404 and 405

    public function testUnknownPathIsNotFound(): void
    {
        $this->router->get('/a', self::handler());

        $match = $this->router->match('GET', '/b');

        self::assertFalse($match->isFound());
        self::assertFalse($match->isMethodNotAllowed());
    }

    public function testWrongMethodIsMethodNotAllowedWithSortedAllowList(): void
    {
        $this->router->post('/items', self::handler());
        $this->router->get('/items', self::handler());
        $this->router->delete('/items/{id}', self::handler());

        $match = $this->router->match('PUT', '/items');

        self::assertTrue($match->isMethodNotAllowed());
        self::assertSame(['GET', 'HEAD', 'POST'], $match->allowedMethods);
    }

    public function testMethodNotAllowedCollectsAcrossStaticAndDynamicRoutes(): void
    {
        $this->router->get('/items/new', self::handler());
        $this->router->delete('/items/{id}', self::handler());

        self::assertSame(['DELETE', 'GET', 'HEAD'], $this->router->match('POST', '/items/new')->allowedMethods);
    }

    public function testDynamicRouteWithRightMethodBeatsStaticWithWrongMethod(): void
    {
        $this->router->get('/items/new', self::handler());
        $dynamic = $this->router->post('/items/{id}', self::handler());

        self::assertSame($dynamic, $this->router->match('POST', '/items/new')->route);
    }

    // Encoding

    public function testParametersAreDecoded(): void
    {
        $this->router->get('/search/{term}', self::handler());

        self::assertSame(['term' => 'café au lait'], $this->router->match('GET', '/search/caf%C3%A9%20au%20lait')->parameters);
    }

    public function testEncodedSlashStaysInsideOneParameter(): void
    {
        $this->router->get('/files/{path}', self::handler());

        self::assertSame(['path' => 'a/b'], $this->router->match('GET', '/files/a%2Fb')->parameters);
        self::assertSame(['path' => 'a/b'], $this->router->match('GET', '/files/a%2fb')->parameters);
    }

    public function testEncodedUnreservedCharactersMatchStaticRoutes(): void
    {
        $this->router->get('/about-us', self::handler());

        self::assertTrue($this->router->match('GET', '/%61bout%2Dus')->isFound());
    }

    public function testNonAsciiStaticRoute(): void
    {
        $this->router->get('/café', self::handler());

        self::assertTrue($this->router->match('GET', '/caf%C3%A9')->isFound());
        self::assertTrue($this->router->match('GET', '/caf%c3%a9')->isFound());
    }

    public function testDoubleEncodingIsNotDecodedTwice(): void
    {
        $this->router->get('/files/{path}', self::handler());

        self::assertSame(['path' => 'a%2Fb'], $this->router->match('GET', '/files/a%252Fb')->parameters);
    }

    public function testEncodedTraversalDoesNotEscapeSegment(): void
    {
        $this->router->get('/files/{name}', self::handler());

        self::assertSame(['name' => '../etc'], $this->router->match('GET', '/files/..%2Fetc')->parameters);
        self::assertFalse($this->router->match('GET', '/files/../etc')->isFound());
    }

    // Groups

    public function testGroupsApplyPrefixMiddlewareAndNamePrefix(): void
    {
        $outer = new TraceMiddleware('outer');
        $inner = new TraceMiddleware('inner');
        $own = new TraceMiddleware('own');

        $this->router->group('/admin', function (Router $r) use ($inner, $own): void {
            $r->group('/users', function (Router $r) use ($own): void {
                $r->get('/{id}', self::handler())->name('show')->middleware($own);
                $r->get('/', self::handler())->name('index');
            }, [$inner], 'users.');
        }, [$outer], 'admin.');

        $this->router->get('/outside', self::handler());

        $show = $this->router->route('admin.users.show');
        self::assertSame('/admin/users/{id}', $show->path());
        self::assertSame([$outer, $inner, $own], $show->getMiddleware());
        self::assertSame('/admin/users', $this->router->route('admin.users.index')->path());
        self::assertSame([], $this->router->match('GET', '/outside')->route?->getMiddleware());
    }

    public function testGroupStateIsRestoredWhenDefinitionThrows(): void
    {
        try {
            $this->router->group('/broken', static function (): void {
                throw new \RuntimeException('fail');
            });
        } catch (\RuntimeException) {
        }

        self::assertSame('/after', $this->router->get('/after', self::handler())->path());
    }

    public function testInvalidGroupPrefix(): void
    {
        $this->expectException(RoutingException::class);

        $this->router->group('admin/', static function (): void {});
    }

    // URL generation

    public function testGeneratesPathsForNamedRoutes(): void
    {
        $this->router->get('/users/{id:\d+}/posts/{slug}', self::handler())->name('post');

        self::assertSame('/users/5/posts/hello%20world', $this->router->path('post', ['id' => 5, 'slug' => 'hello world']));
    }

    public function testGenerationEncodesSlashesAndAddsQueryString(): void
    {
        $this->router->get('/files/{path}', self::handler())->name('file');

        self::assertSame('/files/a%2Fb?download=1&v=x%20y', $this->router->path('file', ['path' => 'a/b', 'download' => true, 'v' => 'x y']));
    }

    public function testGeneratedPathMatchesAgain(): void
    {
        $this->router->get('/search/{term}', self::handler())->name('search');

        $path = $this->router->path('search', ['term' => 'a/b c%']);

        self::assertSame(['term' => 'a/b c%'], $this->router->match('GET', $path)->parameters);
    }

    public function testGenerationRejectsMissingOrInvalidParameters(): void
    {
        $this->router->get('/users/{id:\d+}', self::handler())->name('user');

        try {
            $this->router->path('user');
            self::fail('Expected missing parameter error.');
        } catch (RoutingException $e) {
            self::assertStringContainsString('Missing parameter "id"', $e->getMessage());
        }

        $this->expectException(RoutingException::class);
        $this->expectExceptionMessage('does not match its constraint');
        $this->router->path('user', ['id' => 'abc']);
    }

    public function testParametersDoNotMatchATrailingNewline(): void
    {
        $route = new Route(['GET'], '/users/{id:\d+}', self::handler());

        self::assertSame(['id' => '5'], $route->match('/users/5'));
        self::assertNull($route->match("/users/5\n"));
        self::assertNull($route->match('/users/5%0A'));
    }

    public function testUnknownRouteName(): void
    {
        $this->expectException(RoutingException::class);

        $this->router->path('missing');
    }

    // Definition errors

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidPaths(): iterable
    {
        yield 'no leading slash' => ['users'];
        yield 'empty' => [''];
        yield 'empty segment' => ['/a//b'];
        yield 'duplicate parameter' => ['/{id}/{id}'];
        yield 'unbalanced brace' => ['/users/{id'];
        yield 'invalid parameter name' => ['/users/{1id}'];
        yield 'invalid regex' => ['/users/{id:[a-}'];
    }

    #[DataProvider('invalidPaths')]
    public function testRejectsInvalidPaths(string $path): void
    {
        $this->expectException(RoutingException::class);

        $this->router->get($path, self::handler());
    }

    public function testRejectsUnknownMethod(): void
    {
        $this->expectException(RoutingException::class);
        $this->expectExceptionMessage('Unsupported HTTP method "TRACE"');

        $this->router->map(['TRACE'], '/x', self::handler());
    }

    public function testWhereRejectsUnknownParameterAndInvalidRegex(): void
    {
        $route = $this->router->get('/users/{id}', self::handler());

        try {
            $route->where('name', '\w+');
            self::fail('Expected unknown parameter error.');
        } catch (RoutingException) {
        }

        $this->expectException(RoutingException::class);
        $route->where('id', '(unclosed');
    }

    public function testDuplicateRouteIsDetected(): void
    {
        $this->router->get('/users/{id}', self::handler());
        $this->router->get('/users/{name}', self::handler());

        $this->expectException(RoutingException::class);
        $this->expectExceptionMessage('GET /users/{name} is defined more than once');

        $this->router->match('GET', '/users/1');
    }

    public function testSamePathWithDifferentMethodsIsAllowed(): void
    {
        $this->router->get('/items', self::handler());
        $this->router->post('/items', self::handler());

        self::assertTrue($this->router->match('POST', '/items')->isFound());
    }

    public function testDuplicateNameIsDetected(): void
    {
        $this->router->get('/a', self::handler())->name('dup');
        $this->router->get('/b', self::handler())->name('dup');

        $this->expectException(RoutingException::class);
        $this->expectExceptionMessage('"dup" is used more than once');

        $this->router->routes();
    }

    public function testRoutesAreFrozenAfterMatchingStarts(): void
    {
        $route = $this->router->get('/a', self::handler());
        $this->router->match('GET', '/a');

        try {
            $this->router->get('/b', self::handler());
            self::fail('Expected frozen router error.');
        } catch (RoutingException) {
        }

        $this->expectException(RoutingException::class);
        $route->name('late');
    }

    // Handlers

    public function testAcceptsControllerArrayAndInvokableClass(): void
    {
        $this->router->get('/a', [UserController::class, 'show']);
        $this->router->get('/b', InvokableController::class);

        self::assertCount(2, $this->router->routes());
    }

    /**
     * @return iterable<string, array{array<mixed>|string}>
     */
    public static function invalidHandlers(): iterable
    {
        yield 'missing class' => ['App\\Missing'];
        yield 'not invokable' => [NotInvokable::class];
        yield 'missing method' => [[UserController::class, 'missing']];
        yield 'private method' => [[UserController::class, 'hidden']];
        yield 'missing controller class' => [['App\\Missing', 'show']];
        yield 'wrong shape' => [[UserController::class]];
    }

    /**
     * @param array<mixed>|string $handler
     */
    #[DataProvider('invalidHandlers')]
    public function testRejectsInvalidHandlers(array|string $handler): void
    {
        $this->expectException(RoutingException::class);

        // @phpstan-ignore argument.type (invalid on purpose)
        $this->router->get('/x', $handler);
    }
}
