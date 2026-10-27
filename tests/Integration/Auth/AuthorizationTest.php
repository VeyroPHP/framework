<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Integration\Auth;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use VeyroPHP\Application\Application;
use VeyroPHP\Auth\Access\AccessException;
use VeyroPHP\Auth\Access\AccessRules;
use VeyroPHP\Auth\Access\AuthorizationException;
use VeyroPHP\Auth\Access\Authorize;
use VeyroPHP\Auth\Access\Gate;
use VeyroPHP\Auth\AuthServiceProvider;
use VeyroPHP\Auth\Rbac\Rbac;
use VeyroPHP\Auth\Rbac\RbacSchema;
use VeyroPHP\Auth\Rbac\RbacServiceProvider;
use VeyroPHP\Cache\CacheServiceProvider;
use VeyroPHP\Config\Config;
use VeyroPHP\Container\Container;
use VeyroPHP\Database\DatabaseServiceProvider;
use VeyroPHP\Database\Schema\Blueprint;
use VeyroPHP\Database\Schema\Schema;
use VeyroPHP\Orm\Orm;
use VeyroPHP\Orm\OrmServiceProvider;
use VeyroPHP\Session\SessionServiceProvider;
use VeyroPHP\Tests\Fixtures\Auth\AccessPost;
use VeyroPHP\Tests\Fixtures\Auth\AccessPostPolicy;
use VeyroPHP\Tests\Fixtures\Auth\AuthUser;

/**
 * The gate as the providers register it, with RBAC on SQLite.
 */
#[CoversClass(Gate::class)]
#[CoversClass(Authorize::class)]
#[CoversClass(AuthServiceProvider::class)]
final class AuthorizationTest extends TestCase
{
    private Container $container;

    private Rbac $rbac;

    private AuthUser $admin;

    private AuthUser $editor;

    private AuthUser $reader;

    protected function setUp(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->container()->instance(Config::class, new Config([
            'database' => ['default' => 'memory', 'connections' => ['memory' => ['driver' => 'sqlite', 'database' => ':memory:']]],
            'cache' => ['default' => 'array', 'prefix' => 'test', 'stores' => ['array' => ['driver' => 'array']]],
            'auth' => ['model' => AuthUser::class],
        ]));
        $app->register(new DatabaseServiceProvider());
        $app->register(new CacheServiceProvider());
        $app->register(new SessionServiceProvider());
        $app->register(new OrmServiceProvider());
        $app->register(new AuthServiceProvider());
        $app->register(new RbacServiceProvider());
        $app->boot();
        $this->container = $app->container();

        $schema = $this->container->make(Schema::class);
        $schema->create('users', static function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->string('email')->unique();
            $t->string('password');
            $t->timestamps();
        });
        RbacSchema::up($schema);

        $orm = $this->container->make(Orm::class);
        [$this->admin, $this->editor, $this->reader] = array_map(static function (string $name) use ($orm): AuthUser {
            $user = (new AuthUser())->fill(['name' => $name, 'email' => $name . '@example.test']);
            $user->set('password', 'x');
            $orm->save($user);

            return $user;
        }, ['admin', 'editor', 'reader']);

        $this->rbac = $this->container->make(Rbac::class);
        $this->rbac->seed([
            'roles' => ['super-admin' => null, 'editor' => null],
            'permissions' => ['posts.edit' => null, 'posts.delete' => null, 'reports.export' => null],
            'role_permissions' => ['editor' => ['posts.edit', 'reports.export']],
        ]);
        $this->rbac->assignRole($this->admin, 'super-admin');
        $this->rbac->assignRole($this->editor, 'editor');

        $this->container->make(AccessRules::class)->policy(AccessPost::class, AccessPostPolicy::class);
    }

    private function gate(?AuthUser $user): Gate
    {
        return $this->container->make(Gate::class)->forUser($user);
    }

    public function testTheProvidersRegisterOneRuleSetAndAScopedGate(): void
    {
        self::assertSame($this->container->make(AccessRules::class), $this->container->make(AccessRules::class));
        self::assertSame($this->container->make(Gate::class), $this->container->make(Gate::class), 'one gate per request scope');
    }

    public function testAbilitiesWithoutARuleFallBackToRbacPermissions(): void
    {
        self::assertTrue($this->gate($this->editor)->allows('posts.edit'));
        self::assertFalse($this->gate($this->reader)->allows('posts.edit'));
        self::assertFalse($this->gate(null)->allows('posts.edit'));
        self::assertFalse($this->gate($this->editor)->allows('posts.delete'), 'unknown permission');
    }

    public function testRbacDenialsAndDirectGrantsApply(): void
    {
        $this->rbac->deny($this->editor, 'reports.export');
        $this->rbac->grant($this->reader, 'reports.export');

        self::assertFalse($this->gate($this->editor)->allows('reports.export'));
        self::assertTrue($this->gate($this->reader)->allows('reports.export'));
    }

    public function testSuperAdminsPassEveryCheckIncludingPolicies(): void
    {
        $othersPost = AccessPost::hydrate(['id' => 1, 'user_id' => $this->editor->getKey()]);

        self::assertTrue($this->gate($this->admin)->allows('update', $othersPost));
        self::assertTrue($this->gate($this->admin)->allows('anything.at.all'));
        self::assertFalse($this->gate($this->reader)->allows('update', $othersPost));
        self::assertTrue($this->gate($this->editor)->allows('update', $othersPost), 'the policy: own post');
    }

    public function testBeforeHooksRunBeforeTheSuperAdminRule(): void
    {
        $this->container->make(AccessRules::class)->before(static fn(mixed $user, string $ability): ?bool => $ability === 'site.shutdown' ? false : null);

        self::assertFalse($this->gate($this->admin)->allows('site.shutdown'));
    }

    public function testDefinedAbilitiesComeBeforeRbacPermissions(): void
    {
        $this->container->make(AccessRules::class)->define('posts.edit', static fn(AuthUser $user): bool => $user->get('name') === 'reader');

        self::assertTrue($this->gate($this->reader)->allows('posts.edit'));
        self::assertFalse($this->gate($this->editor)->allows('posts.edit'));
    }

    public function testAuthorizeMiddleware(): void
    {
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200);
            }
        };
        $request = new ServerRequest('GET', '/reports/export');
        [$class, $ability] = explode(':', Authorize::for('reports.export'));
        self::assertSame(Authorize::class, $class);

        $allowed = (new Authorize($this->gate($this->editor)))->withArguments($ability)->process($request, $handler);
        self::assertSame(200, $allowed->getStatusCode());

        foreach ([[null, 401], [$this->reader, 403]] as [$user, $status]) {
            try {
                (new Authorize($this->gate($user)))->withArguments($ability)->process($request, $handler);
                self::fail('AuthorizationException expected');
            } catch (AuthorizationException $e) {
                self::assertSame($status, $e->getStatusCode());
            }
        }

        $this->expectException(AccessException::class);
        (new Authorize($this->gate($this->editor)))->process($request, $handler);
    }

    public function testAuthorizeMiddlewareTakesExactlyOneAbility(): void
    {
        $this->expectException(AccessException::class);
        (new Authorize($this->gate($this->editor)))->withArguments('a', 'b');
    }
}
