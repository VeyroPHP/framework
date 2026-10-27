<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Integration\Orm;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use VeyroPHP\Application\Application;
use VeyroPHP\Config\Config;
use VeyroPHP\Container\Container;
use VeyroPHP\Database\Connection\Connection;
use VeyroPHP\Database\DatabaseServiceProvider;
use VeyroPHP\Database\Profiling\QueryProfiler;
use VeyroPHP\Database\Schema\Blueprint;
use VeyroPHP\Database\Schema\Schema;
use VeyroPHP\Http\HttpKernel;
use VeyroPHP\Http\HttpServiceProvider;
use VeyroPHP\Orm\ModelRouteResolver;
use VeyroPHP\Orm\Orm;
use VeyroPHP\Orm\OrmServiceProvider;
use VeyroPHP\Routing\Router;
use VeyroPHP\Support\Ulid;
use VeyroPHP\Tests\Fixtures\Orm\Comment;
use VeyroPHP\Tests\Fixtures\Orm\User;

/**
 * Full application: database, HTTP and ORM providers, routes with model parameters.
 */
#[CoversClass(ModelRouteResolver::class)]
#[CoversClass(OrmServiceProvider::class)]
#[CoversClass(Container::class)]
final class ModelRouteBindingTest extends TestCase
{
    private HttpKernel $kernel;

    private Orm $orm;

    private QueryProfiler $profiler;

    protected function setUp(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->container()->instance(Config::class, new Config(['database' => [
            'default' => 'memory',
            'connections' => ['memory' => ['driver' => 'sqlite', 'database' => ':memory:']],
        ]]));
        $app->register(new DatabaseServiceProvider());
        $app->register(new HttpServiceProvider());
        $app->register(new OrmServiceProvider());
        $app->boot();

        $container = $app->container();
        $schema = $container->make(Schema::class);
        $schema->create('users', static function (Blueprint $t): void {
            $t->id();
            $t->ulid()->unique();
            $t->string('name');
            $t->string('email');
            $t->string('password')->nullable();
            $t->boolean('is_admin')->default(false);
            $t->timestamps();
        });
        $schema->create('comments', static function (Blueprint $t): void {
            $t->id();
            $t->integer('post_id');
            $t->string('body');
        });

        $router = $container->make(Router::class);
        $router->get('/users/{user}', static fn(User $user): User => $user);
        $router->get('/comments/{comment}', static fn(Comment $comment): array => $comment->toArray());

        $this->kernel = $container->make(HttpKernel::class);
        $this->orm = $container->make(Orm::class);
        $this->profiler = new QueryProfiler();
        $container->make(Connection::class)->listen($this->profiler->listener());
    }

    public function testBindsModelByPublicIdAndHidesPassword(): void
    {
        $user = $this->orm->create(User::class, ['name' => 'Ravi', 'email' => 'ravi@example.test']);
        $user->set('password', password_hash('secret', PASSWORD_DEFAULT));
        $this->orm->save($user);

        $response = $this->kernel->handle(new ServerRequest('GET', '/users/' . $user->getPublicId()));
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('"name":"Ravi"', $body);
        self::assertStringNotContainsString('password', $body);
    }

    public function testNumericIdIsNotAcceptedForModelsWithPublicId(): void
    {
        $user = $this->orm->create(User::class, ['name' => 'Ravi', 'email' => 'ravi@example.test']);

        $response = $this->kernel->handle(new ServerRequest('GET', '/users/' . $user->getKey(), ['Accept' => 'application/json']));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('{"error":{"status":404,"message":"Not Found"}}', (string) $response->getBody());
    }

    public function testUnknownOrMalformedPublicIdIs404WithoutQueryingForMalformed(): void
    {
        self::assertSame(404, $this->kernel->handle(new ServerRequest('GET', '/users/' . Ulid::generate()))->getStatusCode());

        $this->profiler->reset();
        self::assertSame(404, $this->kernel->handle(new ServerRequest('GET', "/users/1'%20OR%20'1'='1"))->getStatusCode());
        self::assertSame(0, $this->profiler->count(), 'a malformed ULID must not reach the database');
    }

    public function testModelsWithoutPublicIdBindByNumericKey(): void
    {
        $comment = $this->orm->create(Comment::class, ['post_id' => 1, 'body' => 'Hello']);

        self::assertSame(200, $this->kernel->handle(new ServerRequest('GET', '/comments/' . $comment->getKey()))->getStatusCode());
        self::assertSame(404, $this->kernel->handle(new ServerRequest('GET', '/comments/999'))->getStatusCode());

        $this->profiler->reset();
        foreach (['abc', '0', '-1', '01', '1.5'] as $bad) {
            self::assertSame(404, $this->kernel->handle(new ServerRequest('GET', '/comments/' . $bad))->getStatusCode(), $bad);
        }
        self::assertSame(0, $this->profiler->count());
    }

    public function testBoundOnlyReportsExplicitRegistrations(): void
    {
        $container = new Container();
        $container->singleton('registered', static fn(): string => 'x');

        self::assertTrue($container->bound('registered'));
        self::assertFalse($container->bound(\stdClass::class));
        self::assertTrue($container->has(\stdClass::class));
    }
}
