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
use VeyroPHP\Auth\Authenticate;
use VeyroPHP\Auth\AuthServiceProvider;
use VeyroPHP\Auth\Console\PruneRememberTokensCommand;
use VeyroPHP\Auth\Events\Login;
use VeyroPHP\Auth\Events\OtherDevicesLoggedOut;
use VeyroPHP\Auth\Remember\RememberMe;
use VeyroPHP\Auth\Remember\RememberSchema;
use VeyroPHP\Auth\Remember\RememberTokens;
use VeyroPHP\Cache\CacheServiceProvider;
use VeyroPHP\Config\Config;
use VeyroPHP\Console\Input;
use VeyroPHP\Console\Output;
use VeyroPHP\Container\Container;
use VeyroPHP\Database\Connection\Connection;
use VeyroPHP\Database\DatabaseServiceProvider;
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

/**
 * "Remember me" and logging out other devices, through the HTTP kernel. Each browser
 * is a separate cookie jar.
 */
#[CoversClass(RememberTokens::class)]
#[CoversClass(RememberMe::class)]
#[CoversClass(RememberSchema::class)]
#[CoversClass(Auth::class)]
final class RememberMeTest extends TestCase
{
    private Container $container;

    private HttpKernel $kernel;

    private int $now = 1_800_000_000;

    /** @var array<string, array<string, string>> cookies per browser */
    private array $browsers = [];

    /** @var list<string> */
    private array $events = [];

    protected function setUp(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->container()->instance(Config::class, new Config([
            'database' => ['default' => 'memory', 'connections' => ['memory' => ['driver' => 'sqlite', 'database' => ':memory:']]],
            'cache' => ['default' => 'array', 'prefix' => 'test', 'stores' => ['array' => ['driver' => 'array']]],
            'auth' => ['model' => AuthUser::class, 'hash_algorithm' => 'bcrypt', 'hash_options' => ['cost' => 5]],
            'app' => ['key' => 'base64:' . base64_encode(str_repeat('k', 32))],
        ]));
        $app->register(new DatabaseServiceProvider());
        $app->register(new CacheServiceProvider());
        $app->register(new SessionServiceProvider());
        $app->register(new HttpServiceProvider([], [StartSession::class, RememberMe::class, VerifyCsrfToken::class]));
        $app->register(new OrmServiceProvider());
        $app->register(new AuthServiceProvider());
        $app->register(new EventServiceProvider());
        $app->boot();
        $this->container = $app->container();

        // The same tokens service, with a clock the tests control.
        $this->container->scoped(RememberTokens::class, fn(Container $c): RememberTokens => new RememberTokens(
            $c->make(Connection::class),
            lifetimeDays: 30,
            clock: fn(): int => $this->now,
        ));

        $schema = $this->container->make(Schema::class);
        $schema->create('users', static function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->string('email')->unique();
            $t->string('password');
            $t->timestamps();
        });
        RememberSchema::up($schema);

        $user = (new AuthUser())->fill(['name' => 'asha', 'email' => 'asha@example.test']);
        $user->set('password', $this->container->make(PasswordHasher::class)->hash('secret-pass'));
        $this->container->make(Orm::class)->save($user);

        $events = $this->container->make(EventDispatcher::class);
        $events->listen(Login::class, function (): void {
            $this->events[] = 'login';
        });
        $events->listen(OtherDevicesLoggedOut::class, function (): void {
            $this->events[] = 'others';
        });

        $router = $this->container->make(Router::class);
        $router->get('/csrf', static function (ServerRequestInterface $r): string {
            $token = $r->getAttribute(VerifyCsrfToken::ATTRIBUTE);

            return $token instanceof CsrfToken ? $token->value() : '';
        });
        $router->post('/login', static function (ServerRequestInterface $r, Auth $auth): array {
            $body = (array) $r->getParsedBody();

            if (!$auth->attempt(self::text($body['email'] ?? ''), self::text($body['password'] ?? ''), remember: ($body['remember'] ?? '') === '1')) {
                throw new HttpException(401);
            }

            return ['ok' => true];
        });
        $router->post('/logout', static function (Auth $auth): array {
            $auth->logout();

            return ['ok' => true];
        });
        $router->post('/logout-others', static function (ServerRequestInterface $r, Auth $auth): array {
            $body = (array) $r->getParsedBody();

            return ['ok' => $auth->logoutOtherDevices(self::text($body['password'] ?? ''))];
        })->middleware(Authenticate::class);
        $router->get('/me', static fn(Auth $auth): string => self::text($auth->user()?->get('email')))->middleware(Authenticate::class);

        $this->kernel = $this->container->make(HttpKernel::class);
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /**
     * @param array<string, string>|null $body
     */
    private function send(string $browser, string $method, string $path, ?array $body = null): ResponseInterface
    {
        $cookies = $this->browsers[$browser] ?? [];

        if ($body !== null) {
            $body['_token'] = (string) $this->send($browser, 'GET', '/csrf')->getBody();
            $cookies = $this->browsers[$browser] ?? [];
        }

        $request = (new ServerRequest($method, 'https://localhost' . $path, ['Accept' => 'application/json']))->withCookieParams($cookies);
        $response = $this->kernel->handle($body === null ? $request : $request->withParsedBody($body));

        foreach ($response->getHeader('Set-Cookie') as $header) {
            [$pair] = explode(';', $header, 2);
            [$name, $value] = explode('=', $pair, 2);

            if ($value === '' || str_contains($header, 'Max-Age=0')) {
                unset($this->browsers[$browser][$name]);
            } else {
                $this->browsers[$browser][$name] = rawurldecode($value);
            }
        }

        return $response;
    }

    private function login(string $browser, bool $remember): void
    {
        $response = $this->send($browser, 'POST', '/login', ['email' => 'asha@example.test', 'password' => 'secret-pass', 'remember' => $remember ? '1' : '0']);
        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * The browser was closed: the session cookie is gone, the remember cookie stays.
     */
    private function restart(string $browser): void
    {
        unset($this->browsers[$browser]['veyrophp_session']);
    }

    private function meStatus(string $browser): int
    {
        return $this->send($browser, 'GET', '/me')->getStatusCode();
    }

    private function tokenCount(): int
    {
        return $this->container->make(Connection::class)->table(RememberSchema::TABLE)->count();
    }

    public function testARememberedBrowserIsLoggedInAfterARestart(): void
    {
        $this->login('phone', remember: true);
        self::assertArrayHasKey(RememberTokens::COOKIE, $this->browsers['phone']);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{22}:[A-Za-z0-9_-]{43}$/', $this->browsers['phone'][RememberTokens::COOKIE]);

        $this->restart('phone');
        $this->events = [];
        self::assertSame(200, $this->meStatus('phone'));
        self::assertSame(['login'], $this->events, 'logging in from the cookie sends Login');
        self::assertArrayHasKey('veyrophp_session', $this->browsers['phone'], 'a new session was started');
        self::assertSame(200, $this->meStatus('phone'), 'the next request uses the session');
        self::assertSame(['login'], $this->events);
    }

    public function testTheCookieIsHttpOnlySecureAndLastsTheLifetime(): void
    {
        $response = $this->send('phone', 'POST', '/login', ['email' => 'asha@example.test', 'password' => 'secret-pass', 'remember' => '1']);
        $header = implode("\n", array_filter($response->getHeader('Set-Cookie'), static fn(string $h): bool => str_starts_with($h, RememberTokens::COOKIE . '=')));

        self::assertStringContainsString('Max-Age=' . (30 * 86400), $header);
        self::assertStringContainsString('HttpOnly', $header);
        self::assertStringContainsString('Secure', $header, 'an HTTPS request, like the session cookie');
        self::assertStringContainsString('SameSite=Lax', $header);
    }

    public function testWithoutRememberARestartLogsOut(): void
    {
        $this->login('laptop', remember: false);
        self::assertArrayNotHasKey(RememberTokens::COOKIE, $this->browsers['laptop']);

        $this->restart('laptop');
        self::assertSame(401, $this->meStatus('laptop'));
        self::assertSame(0, $this->tokenCount());
    }

    public function testLogoutDeletesTheTokenAndACopiedCookieNoLongerWorks(): void
    {
        $this->login('phone', remember: true);
        $copy = $this->browsers['phone'][RememberTokens::COOKIE];

        $this->send('phone', 'POST', '/logout', []);
        self::assertArrayNotHasKey(RememberTokens::COOKIE, $this->browsers['phone']);
        self::assertSame(0, $this->tokenCount());

        $this->browsers['thief'] = [RememberTokens::COOKIE => $copy];
        self::assertSame(401, $this->meStatus('thief'));
    }

    public function testAWrongValidatorDeletesTheToken(): void
    {
        $this->login('phone', remember: true);
        [$selector] = explode(':', $this->browsers['phone'][RememberTokens::COOKIE]);

        $this->browsers['attacker'] = [RememberTokens::COOKIE => $selector . ':' . str_repeat('A', 43)];
        self::assertSame(401, $this->meStatus('attacker'));
        self::assertArrayNotHasKey(RememberTokens::COOKIE, $this->browsers['attacker'], 'the cookie is removed');
        self::assertSame(0, $this->tokenCount(), 'the guessed selector is burnt');

        $this->restart('phone');
        self::assertSame(401, $this->meStatus('phone'));
    }

    public function testMalformedAndUnknownCookiesAreRemoved(): void
    {
        foreach (['not-a-token', str_repeat('a', 22) . ':' . str_repeat('b', 43)] as $cookie) {
            $this->browsers['other'] = [RememberTokens::COOKIE => $cookie];
            self::assertSame(401, $this->meStatus('other'));
            self::assertArrayNotHasKey(RememberTokens::COOKIE, $this->browsers['other']);
        }
    }

    public function testExpiredTokensAreRefusedAndPruned(): void
    {
        $this->login('phone', remember: true);
        $this->now += 30 * 86400;

        $this->restart('phone');
        self::assertSame(401, $this->meStatus('phone'));
        self::assertSame(0, $this->tokenCount());

        $this->login('tablet', remember: true);
        $this->now += 31 * 86400;
        $this->login('laptop', remember: true);
        self::assertSame(1, $this->container->make(RememberTokens::class)->prune(), 'only the expired token');
        self::assertSame(1, $this->tokenCount());

        $this->now += 31 * 86400;
        $out = fopen('php://memory', 'w+');
        self::assertIsResource($out);
        $command = new PruneRememberTokensCommand($this->container->make(RememberTokens::class));
        self::assertSame(0, $command->execute(Input::parse([], $command->definition()), new Output($out, $out)));
        rewind($out);
        self::assertStringContainsString('Deleted 1 expired "remember me" tokens.', (string) stream_get_contents($out));
    }

    public function testAPasswordChangeEndsRememberedBrowsers(): void
    {
        $this->login('phone', remember: true);
        $user = $this->container->make(Orm::class)->findOrFail(AuthUser::class, 1);
        $user->set('password', $this->container->make(PasswordHasher::class)->hash('new-password'));
        $this->container->make(Orm::class)->save($user);

        $this->restart('phone');
        self::assertSame(401, $this->meStatus('phone'));
        self::assertSame(0, $this->tokenCount(), 'the stale token is deleted');
    }

    public function testLogoutOtherDevicesKeepsOnlyThisBrowser(): void
    {
        $this->login('laptop', remember: true);
        $this->login('phone', remember: true);
        $this->login('tablet', remember: false);
        self::assertSame(2, $this->tokenCount());

        $wrong = $this->send('laptop', 'POST', '/logout-others', ['password' => 'nope']);
        self::assertStringContainsString('"ok":false', (string) $wrong->getBody());
        self::assertSame(200, $this->meStatus('phone'), 'a wrong password changes nothing');

        $this->events = [];
        $right = $this->send('laptop', 'POST', '/logout-others', ['password' => 'secret-pass']);
        self::assertStringContainsString('"ok":true', (string) $right->getBody());
        self::assertSame(['others'], $this->events);

        self::assertSame(200, $this->meStatus('laptop'), 'this session stays');
        self::assertSame(401, $this->meStatus('tablet'), 'other sessions end');
        self::assertSame(1, $this->tokenCount(), 'other remembered browsers end');

        $this->restart('phone');
        self::assertSame(401, $this->meStatus('phone'));

        $this->restart('laptop');
        self::assertSame(200, $this->meStatus('laptop'), 'this browser is still remembered');
    }

    public function testRememberMeWithoutTheServiceFailsClearly(): void
    {
        $auth = new Auth(
            $this->container->make(Orm::class),
            new \VeyroPHP\Session\Session(\VeyroPHP\Session\Session::generateId()),
            $this->container->make(PasswordHasher::class),
            $this->container->make(\VeyroPHP\Auth\AuthConfig::class),
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('"Remember me" needs RememberTokens');
        $auth->attempt('asha@example.test', 'secret-pass', remember: true);
    }
}
