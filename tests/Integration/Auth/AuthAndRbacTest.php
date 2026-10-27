<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Integration\Auth;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VeyroPHP\Application\Application;
use VeyroPHP\Auth\Auth;
use VeyroPHP\Auth\AuthConfig;
use VeyroPHP\Auth\Authenticate;
use VeyroPHP\Auth\AuthServiceProvider;
use VeyroPHP\Auth\Events\Login;
use VeyroPHP\Auth\Events\LoginFailed;
use VeyroPHP\Auth\Events\Logout;
use VeyroPHP\Auth\Rbac\Rbac;
use VeyroPHP\Auth\Rbac\RbacException;
use VeyroPHP\Auth\Rbac\RbacSchema;
use VeyroPHP\Auth\Rbac\RbacServiceProvider;
use VeyroPHP\Auth\Rbac\RequirePermission;
use VeyroPHP\Auth\Rbac\RequireRole;
use VeyroPHP\Cache\CacheServiceProvider;
use VeyroPHP\Config\Config;
use VeyroPHP\Container\Container;
use VeyroPHP\Database\Connection\Connection;
use VeyroPHP\Database\DatabaseServiceProvider;
use VeyroPHP\Database\Profiling\QueryProfiler;
use VeyroPHP\Database\Schema\Blueprint;
use VeyroPHP\Database\Schema\Schema;
use VeyroPHP\Events\EventDispatcher;
use VeyroPHP\Events\EventServiceProvider;
use VeyroPHP\Http\Exception\HttpException;
use VeyroPHP\Http\HttpKernel;
use VeyroPHP\Http\HttpServiceProvider;
use VeyroPHP\Orm\Orm;
use VeyroPHP\Orm\OrmServiceProvider;
use VeyroPHP\Routing\Router;
use VeyroPHP\Security\PasswordHasher;
use VeyroPHP\Session\CsrfToken;
use VeyroPHP\Session\SessionServiceProvider;
use VeyroPHP\Session\StartSession;
use VeyroPHP\Session\VerifyCsrfToken;
use VeyroPHP\Tests\Fixtures\Auth\AuthUser;
use VeyroPHP\Validation\Validator;

/**
 * Complete application: sessions in the cache, CSRF, ORM users, login, RBAC.
 */
#[CoversClass(Auth::class)]
#[CoversClass(AuthConfig::class)]
#[CoversClass(Authenticate::class)]
#[CoversClass(AuthServiceProvider::class)]
#[CoversClass(Rbac::class)]
#[CoversClass(RbacSchema::class)]
#[CoversClass(RbacServiceProvider::class)]
#[CoversClass(RequirePermission::class)]
#[CoversClass(RequireRole::class)]
#[CoversClass(RbacException::class)]
#[CoversClass(SessionServiceProvider::class)]
final class AuthAndRbacTest extends TestCase
{
    private Container $container;

    private HttpKernel $kernel;

    /** @var array<string, string> */
    private array $cookies = [];

    protected function setUp(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->container()->instance(Config::class, new Config([
            'database' => ['default' => 'memory', 'connections' => ['memory' => ['driver' => 'sqlite', 'database' => ':memory:']]],
            'cache' => ['default' => 'array', 'prefix' => 'test', 'stores' => ['array' => ['driver' => 'array']]],
            'auth' => ['model' => AuthUser::class, 'hash_algorithm' => 'bcrypt', 'hash_options' => ['cost' => 5]],
        ]));
        $app->register(new DatabaseServiceProvider());
        $app->register(new CacheServiceProvider());
        $app->register(new SessionServiceProvider());
        $app->register(new HttpServiceProvider([], [StartSession::class, VerifyCsrfToken::class]));
        $app->register(new OrmServiceProvider());
        $app->register(new AuthServiceProvider());
        $app->register(new RbacServiceProvider());
        $app->register(new EventServiceProvider());
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

        $this->defineRoutes($this->container->make(Router::class));
        $this->kernel = $this->container->make(HttpKernel::class);
    }

    private function defineRoutes(Router $router): void
    {
        $router->get('/csrf', static function (ServerRequestInterface $r): string {
            $token = $r->getAttribute(VerifyCsrfToken::ATTRIBUTE);

            return $token instanceof CsrfToken ? $token->value() : '';
        });
        $router->post('/login', static function (ServerRequestInterface $r, Auth $auth, Validator $validator): array {
            $body = $r->getParsedBody();
            $data = $validator->validate(is_array($body) ? $body : [], ['email' => 'required|email', 'password' => 'required|string|max:72']);

            $email = is_string($data['email'] ?? null) ? $data['email'] : '';
            $password = is_string($data['password'] ?? null) ? $data['password'] : '';

            if (!$auth->attempt($email, $password)) {
                throw new HttpException(401, 'The email address or password is incorrect.');
            }

            return ['logged_in' => true];
        });
        $router->post('/logout', static function (Auth $auth): array {
            $auth->logout();

            return ['logged_out' => true];
        });
        $router->get('/me', static fn(Auth $auth): ?AuthUser => $auth->user() instanceof AuthUser ? $auth->user() : null)
            ->middleware(Authenticate::class);
        $router->get('/admin/posts', static fn(): string => 'post editor')->middleware(RequirePermission::for('posts.edit'));
        $router->get('/admin/reports', static fn(): string => 'reports')->middleware(RequirePermission::for('reports.view', 'reports.export'));
        $router->get('/admin/settings', static fn(): string => 'settings')->middleware(RequireRole::for('admin'));
    }

    private function createUser(string $email, string $password = 'secret-pass'): AuthUser
    {
        $user = (new AuthUser())->fill(['name' => strtok($email, '@') ?: 'user', 'email' => $email]);
        $user->set('password', $this->container->make(PasswordHasher::class)->hash($password));
        $this->container->make(Orm::class)->save($user);

        return $user;
    }

    /**
     * @param array<string, string>|null $body
     * @param array<string, string> $headers
     */
    private function send(string $method, string $path, ?array $body = null, array $headers = []): ResponseInterface
    {
        $request = (new ServerRequest($method, $path, $headers))->withCookieParams($this->cookies);
        $response = $this->kernel->handle($body === null ? $request : $request->withParsedBody($body));

        foreach ($response->getHeader('Set-Cookie') as $header) {
            [$pair] = explode(';', $header, 2);
            [$name, $value] = explode('=', $pair, 2);
            $this->cookies[$name] = rawurldecode($value);
        }

        return $response;
    }

    private function login(string $email, string $password = 'secret-pass'): ResponseInterface
    {
        $token = (string) $this->send('GET', '/csrf')->getBody();

        return $this->send('POST', '/login', ['email' => $email, 'password' => $password, '_token' => $token], ['Accept' => 'application/json']);
    }

    private function rbac(): Rbac
    {
        return new Rbac($this->container->make(Connection::class));
    }

    // Authentication

    public function testLoginRegeneratesSessionAndMeReturnsUserWithoutPassword(): void
    {
        $this->createUser('ravi@example.com');
        $this->send('GET', '/csrf');
        $before = $this->cookies['veyrophp_session'] ?? null;

        self::assertSame('{"logged_in":true}', (string) $this->login('ravi@example.com')->getBody());
        self::assertNotSame($before, $this->cookies['veyrophp_session'] ?? null, 'session id must change on login');

        $me = $this->send('GET', '/me', null, ['Accept' => 'application/json']);
        self::assertSame(200, $me->getStatusCode());
        self::assertStringContainsString('"email":"ravi@example.com"', (string) $me->getBody());
        self::assertStringNotContainsString('password', (string) $me->getBody());
    }

    public function testWrongPasswordAndUnknownEmailGiveTheSameAnswer(): void
    {
        $this->createUser('ravi@example.com');

        $wrong = $this->login('ravi@example.com', 'not-the-password');
        $unknown = $this->login('nobody@example.com', 'whatever');

        self::assertSame(401, $wrong->getStatusCode());
        self::assertStringContainsString('The email address or password is incorrect.', (string) $wrong->getBody());
        self::assertSame((string) $wrong->getBody(), (string) $unknown->getBody(), 'responses must not reveal which accounts exist');
        self::assertSame(401, $this->send('GET', '/me', null, ['Accept' => 'application/json'])->getStatusCode());
    }

    public function testLoginValidationErrorsAre422(): void
    {
        $token = (string) $this->send('GET', '/csrf')->getBody();
        $response = $this->send('POST', '/login', ['email' => 'not-an-email', '_token' => $token], ['Accept' => 'application/json']);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('"email":["The email field must be a valid email address."]', (string) $response->getBody());
        self::assertStringContainsString('"password":["The password field is required."]', (string) $response->getBody());
    }

    public function testLoginWithoutCsrfTokenIs403(): void
    {
        $this->createUser('ravi@example.com');

        $response = $this->send('POST', '/login', ['email' => 'ravi@example.com', 'password' => 'secret-pass'], ['Accept' => 'application/json']);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testGuestsGet401OrRedirectToLogin(): void
    {
        self::assertSame(401, $this->send('GET', '/me', null, ['Accept' => 'application/json'])->getStatusCode());

        $browser = $this->send('GET', '/me');
        self::assertSame(302, $browser->getStatusCode());
        self::assertSame('/login', $browser->getHeaderLine('Location'));
    }

    public function testLogoutInvalidatesTheSession(): void
    {
        $this->createUser('ravi@example.com');
        $this->login('ravi@example.com');
        $loggedInCookies = $this->cookies;

        $token = (string) $this->send('GET', '/csrf')->getBody();
        $this->send('POST', '/logout', ['_token' => $token], ['Accept' => 'application/json']);

        self::assertSame(401, $this->send('GET', '/me', null, ['Accept' => 'application/json'])->getStatusCode());

        $this->cookies = $loggedInCookies;
        self::assertSame(401, $this->send('GET', '/me', null, ['Accept' => 'application/json'])->getStatusCode(), 'the old session cookie must not work after logout');
    }

    public function testLoginLogoutAndFailedLoginSendEvents(): void
    {
        $user = $this->createUser('ravi@example.com');
        /** @var list<string> $seen */
        $seen = [];
        $events = $this->container->make(EventDispatcher::class);
        $events->listen(Login::class, static function (Login $e) use (&$seen): void {
            $seen[] = 'login:' . $e->user->getKey();
        });
        $events->listen(Logout::class, static function (Logout $e) use (&$seen): void {
            $seen[] = 'logout:' . $e->user?->getKey();
        });
        $events->listen(LoginFailed::class, static function (LoginFailed $e) use (&$seen): void {
            $seen[] = 'failed:' . $e->identifier . ':' . ($e->user?->getKey() ?? 'none');
        });

        $this->login('nobody@example.com');
        $this->login('ravi@example.com', 'wrong-password');
        $this->login('ravi@example.com');
        $this->send('POST', '/logout', ['_token' => (string) $this->send('GET', '/csrf')->getBody()]);

        $id = (string) $user->getKey();
        self::assertSame(['failed:nobody@example.com:none', 'failed:ravi@example.com:' . $id, 'login:' . $id, 'logout:' . $id], $seen);
    }

    public function testOutdatedHashIsUpgradedOnLogin(): void
    {
        $user = $this->createUser('ravi@example.com');
        $weak = password_hash('secret-pass', PASSWORD_BCRYPT, ['cost' => 4]);
        $user->set('password', $weak);
        $this->container->make(Orm::class)->save($user);

        $this->login('ravi@example.com');

        $stored = $this->container->make(Connection::class)->table('users')->where('id', $user->getKey())->value('password');
        self::assertIsString($stored);
        self::assertNotSame($weak, $stored);
        self::assertSame(5, password_get_info($stored)['options']['cost'] ?? null);
    }

    /**
     * VQ-012: as in production, every attempt uses a new hasher and Auth (one per web
     * request), with no warm-up. Medians of alternating samples must be close in both
     * directions: an unknown email must not be slower (extra hashing) or faster (no check).
     */
    public function testUnknownEmailTakesComparableTimeToWrongPassword(): void
    {
        $options = ['cost' => 10];
        $user = (new AuthUser())->fill(['name' => 'ravi', 'email' => 'ravi@example.com']);
        $user->set('password', (new PasswordHasher('bcrypt', $options))->hash('secret-pass'));
        $this->container->make(Orm::class)->save($user);

        $attempt = function (string $email) use ($options): float {
            $auth = new Auth($this->container->make(Orm::class), new \VeyroPHP\Session\Session(\VeyroPHP\Session\Session::generateId()), new PasswordHasher('bcrypt', $options), $this->container->make(AuthConfig::class));
            $start = hrtime(true);
            $auth->attempt($email, 'wrong-password');

            return (hrtime(true) - $start) / 1e6;
        };

        $existing = [];
        $unknown = [];
        for ($i = 0; $i < 9; $i++) {
            $existing[] = $attempt('ravi@example.com');
            $unknown[] = $attempt('nobody' . $i . '@example.com');
        }
        sort($existing);
        sort($unknown);
        $ratio = $unknown[4] / $existing[4];

        $summary = sprintf('median unknown %.1f ms vs existing %.1f ms (ratio %.2f)', $unknown[4], $existing[4], $ratio);
        self::assertGreaterThan(0.6, $ratio, $summary);
        self::assertLessThan(1.5, $ratio, $summary);
    }

    // RBAC through routes

    public function testPermissionsFromRolesGrantsDenialsAndSuperAdmin(): void
    {
        $rbac = $this->rbac();
        $rbac->seed([
            'roles' => ['super-admin' => 'Super Admin', 'admin' => 'Admin', 'editor' => 'Editor', 'user' => 'User'],
            'permissions' => ['posts.edit' => 'Edit posts', 'reports.view' => null, 'reports.export' => null],
            'role_permissions' => ['editor' => ['posts.edit'], 'admin' => ['posts.edit', 'reports.view']],
        ]);

        $editor = $this->createUser('editor@example.com');
        $plain = $this->createUser('plain@example.com');
        $boss = $this->createUser('boss@example.com');
        $rbac->assignRole($editor, 'editor');
        $rbac->assignRole($plain, 'user');
        $rbac->assignRole($boss, 'super-admin');

        self::assertSame(401, $this->send('GET', '/admin/posts', null, ['Accept' => 'application/json'])->getStatusCode(), 'guest');

        $this->login('editor@example.com');
        self::assertSame('post editor', (string) $this->send('GET', '/admin/posts')->getBody());
        self::assertSame(403, $this->send('GET', '/admin/reports')->getStatusCode());
        self::assertSame(403, $this->send('GET', '/admin/settings')->getStatusCode());

        $rbac->deny($editor, 'posts.edit');
        self::assertSame(403, $this->send('GET', '/admin/posts')->getStatusCode(), 'a denial beats the role');

        $rbac->grant($editor, 'reports.export');
        self::assertSame('reports', (string) $this->send('GET', '/admin/reports')->getBody(), 'any of the listed permissions is enough');

        $this->cookies = [];
        $this->login('plain@example.com');
        self::assertSame(403, $this->send('GET', '/admin/posts')->getStatusCode());

        $this->cookies = [];
        $this->login('boss@example.com');
        self::assertSame('post editor', (string) $this->send('GET', '/admin/posts')->getBody());
        self::assertSame('reports', (string) $this->send('GET', '/admin/reports')->getBody());
        self::assertSame(403, $this->send('GET', '/admin/settings')->getStatusCode(), 'roles are exact; super-admin bypasses permissions, not role checks');
    }

    public function testRbacServiceApi(): void
    {
        $rbac = $this->rbac();
        $rbac->createRole('admin', 'Administrator');
        $rbac->createPermission('users.delete');
        $rbac->createPermission('users.view');
        $rbac->givePermissionToRole('admin', 'users.delete', 'users.view');
        $rbac->givePermissionToRole('admin', 'users.view'); // idempotent
        $user = $this->createUser('ravi@example.com');

        self::assertFalse($rbac->can($user, 'users.view'));
        $rbac->assignRole($user, 'admin');
        $rbac->assignRole($user, 'admin'); // idempotent
        self::assertTrue($rbac->hasRole($user, 'admin'));
        self::assertSame(['users.delete', 'users.view'], $rbac->permissions($user));

        $rbac->revokePermissionFromRole('admin', 'users.delete');
        self::assertSame(['users.view'], $rbac->permissions($user));

        $rbac->deny($user, 'users.view');
        self::assertFalse($rbac->can($user, 'users.view'));
        $rbac->clearOverride($user, 'users.view');
        self::assertTrue($rbac->can($user, 'users.view'));

        $rbac->removeRole($user, 'admin');
        self::assertSame([], $rbac->roles($user));
        self::assertFalse($rbac->can((int) $user->getKey(), 'users.view'), 'user ids work as well as models');
    }

    public function testChecksAreMemoizedPerRequest(): void
    {
        $rbac = $this->rbac();
        $rbac->seed(['roles' => ['editor' => null], 'permissions' => ['a' => null, 'b' => null], 'role_permissions' => ['editor' => ['a']]]);
        $user = $this->createUser('ravi@example.com');
        $rbac->assignRole($user, 'editor');

        $profiler = new QueryProfiler();
        $this->container->make(Connection::class)->listen($profiler->listener());

        for ($i = 0; $i < 5; $i++) {
            $rbac->can($user, 'a');
            $rbac->can($user, 'b');
        }

        self::assertSame(3, $profiler->count(), 'roles, role permissions and overrides are read once');
    }

    public function testAdminListingAndSyncMethods(): void
    {
        $rbac = $this->rbac();
        $rbac->seed([
            'roles' => ['super-admin' => 'Super Admin', 'editor' => 'Editor', 'viewer' => null],
            'permissions' => ['posts.edit' => 'Edit posts', 'posts.view' => null, 'users.view' => null],
            'role_permissions' => ['editor' => ['posts.edit', 'posts.view'], 'viewer' => ['posts.view']],
        ]);
        $user = $this->createUser('ravi@example.com');

        self::assertSame('super-admin', $rbac->superAdminRole());
        self::assertSame([['name' => 'editor', 'label' => 'Editor'], ['name' => 'super-admin', 'label' => 'Super Admin'], ['name' => 'viewer', 'label' => null]], $rbac->allRoles());
        self::assertSame(['posts.edit', 'posts.view', 'users.view'], array_column($rbac->allPermissions(), 'name'));
        self::assertSame(['editor' => ['posts.edit', 'posts.view'], 'super-admin' => [], 'viewer' => ['posts.view']], $rbac->permissionsByRole());

        $rbac->syncRolePermissions('viewer', ['users.view', 'posts.view', 'users.view']);
        self::assertSame(['posts.view', 'users.view'], $rbac->permissionsByRole()['viewer']);
        $rbac->syncRolePermissions('viewer', []);
        self::assertSame([], $rbac->permissionsByRole()['viewer']);

        $rbac->syncRoles($user, ['editor', 'viewer']);
        self::assertSame(['editor', 'viewer'], $rbac->roles($user));
        self::assertSame(1, $rbac->countUsersWithRole('editor'));
        $rbac->syncRoles($user, ['viewer']);
        self::assertSame(['viewer'], $rbac->roles($user));
        self::assertSame(0, $rbac->countUsersWithRole('editor'));

        $rbac->grant($user, 'users.view');
        $rbac->deny($user, 'posts.edit');
        self::assertSame(['posts.edit' => false, 'users.view' => true], $rbac->overrides($user));

        // Unknown names are rejected and nothing is half-applied.
        foreach ([fn() => $rbac->syncRoles($user, ['viewer', 'nope']), fn() => $rbac->syncRolePermissions('viewer', ['posts.view', 'nope'])] as $call) {
            try {
                $call();
                self::fail('Expected RbacException');
            } catch (RbacException) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertSame(['viewer'], $rbac->roles($user));

        $rbac->syncRoles($user, ['editor']);
        $rbac->deleteRole('editor');
        self::assertSame([], $rbac->roles($user));
        self::assertNotContains('editor', array_column($rbac->allRoles(), 'name'));

        $this->expectException(RbacException::class);
        $this->expectExceptionMessage('cannot be deleted');
        $rbac->deleteRole('super-admin');
    }

    public function testPreloadReadsManyUsersWithThreeQueriesAndMatchesSingleLookups(): void
    {
        $rbac = $this->rbac();
        $rbac->seed([
            'roles' => ['admin' => null, 'editor' => null],
            'permissions' => ['posts.edit' => null, 'reports.view' => null, 'users.view' => null],
            'role_permissions' => ['admin' => ['posts.edit', 'reports.view'], 'editor' => ['posts.edit']],
        ]);

        $users = [];
        for ($i = 1; $i <= 30; $i++) {
            $user = $this->createUser("u$i@example.com");
            match ($i % 5) {
                0 => $rbac->assignRole($user, 'admin', 'editor'),
                1 => $rbac->assignRole($user, 'editor'),
                2 => $rbac->grant($user, 'users.view'),
                3 => (function () use ($rbac, $user): void {
                    $rbac->assignRole($user, 'admin');
                    $rbac->deny($user, 'reports.view');
                })(),
                default => null, // no roles at all
            };
            $users[] = $user;
        }

        // Expected values from the single-user path on a fresh service.
        $single = new Rbac($this->container->make(Connection::class));
        $expected = array_map(static fn(AuthUser $u): array => [$single->roles($u), $single->permissions($u)], $users);

        $profiler = new QueryProfiler();
        $this->container->make(Connection::class)->listen($profiler->listener());

        $batch = new Rbac($this->container->make(Connection::class));
        $batch->preload($users);
        $actual = array_map(static fn(AuthUser $u): array => [$batch->roles($u), $batch->permissions($u)], $users);

        self::assertSame($expected, $actual);
        self::assertSame(3, $profiler->count(), '30 users: 3 queries, not 90');
        self::assertSame([['admin'], ['posts.edit']], $actual[2]); // u3: admin with reports.view denied
        self::assertSame([[], []], $actual[3]); // u4: nothing

        $batch->preload($users); // already loaded: no queries
        self::assertSame(3, $profiler->count());
    }

    public function testSeedIsIdempotentAndNamesAreValidated(): void
    {
        $rbac = $this->rbac();
        $definition = ['roles' => ['admin' => 'Admin'], 'permissions' => ['posts.edit' => null], 'role_permissions' => ['admin' => ['posts.edit']]];
        $rbac->seed($definition);
        $rbac->seed($definition);

        $db = $this->container->make(Connection::class);
        self::assertSame(1, $db->table('roles')->count());
        self::assertSame(1, $db->table('permission_role')->count());

        foreach ([fn() => $rbac->createRole('Admin Role'), fn() => $rbac->createPermission("posts'; drop"), fn() => $rbac->assignRole(1, 'missing-role'), fn() => $rbac->assignRole(new AuthUser(), 'admin')] as $invalid) {
            try {
                $invalid();
                self::fail('Expected RbacException.');
            } catch (RbacException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * SVC-13: a wrong config/rbac.php shape is a clear RbacException naming the key, not a
     * TypeError, and nothing from it is written.
     */
    public function testSeedRejectsAWrongShapeBeforeWritingAnything(): void
    {
        $rbac = $this->rbac();
        $db = $this->container->make(Connection::class);

        foreach ([
            'roles as a list' => [['roles' => ['admin']], 'roles'],
            'roles not an array' => [['roles' => 'admin'], 'roles'],
            'a label that is not a string' => [['roles' => ['admin' => 5]], 'roles.admin'],
            'permissions of a role as a string' => [['roles' => ['admin' => 'Admin'], 'role_permissions' => ['admin' => 'posts.edit']], 'role_permissions.admin'],
            'a permission name that is not a string' => [['role_permissions' => ['admin' => [1]]], 'role_permissions.admin'],
            'an unknown key' => [['role' => ['admin' => null]], 'role'],
        ] as $case => [$definition, $key]) {
            try {
                $rbac->seed($definition);
                self::fail($case . ' was accepted');
            } catch (RbacException $e) {
                self::assertStringContainsString('"' . $key . '"', $e->getMessage(), $case);
            }
        }

        self::assertSame(0, $db->table('roles')->count(), 'a rejected definition writes nothing');
    }
}
