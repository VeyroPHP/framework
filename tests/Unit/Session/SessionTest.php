<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Unit\Session;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VeyroPHP\Cache\ArrayStore;
use VeyroPHP\Cache\Cache;
use VeyroPHP\Container\Container;
use VeyroPHP\Http\Cookie;
use VeyroPHP\Http\HttpErrorResponder;
use VeyroPHP\Http\HttpKernel;
use VeyroPHP\Http\Middleware\ConditionalGet;
use VeyroPHP\Http\ResponseFactory;
use VeyroPHP\Routing\HandlerInvoker;
use VeyroPHP\Routing\Router;
use VeyroPHP\Session\CsrfToken;
use VeyroPHP\Session\Session;
use VeyroPHP\Session\SessionConfig;
use VeyroPHP\Session\SessionException;
use VeyroPHP\Session\SessionHolder;
use VeyroPHP\Session\StartSession;
use VeyroPHP\Session\VerifyCsrfToken;

#[CoversClass(Session::class)]
#[CoversClass(StartSession::class)]
#[CoversClass(VerifyCsrfToken::class)]
#[CoversClass(SessionHolder::class)]
#[CoversClass(SessionConfig::class)]
#[CoversClass(SessionException::class)]
#[CoversClass(Cookie::class)]
#[CoversClass(ResponseFactory::class)]
final class SessionTest extends TestCase
{
    private Cache $store;

    private Container $container;

    private Router $router;

    /** Runs inside GET /slow, to simulate another request while that one is in flight. */
    private ?\Closure $during = null;

    protected function setUp(): void
    {
        $this->store = new Cache(new ArrayStore(), 'app');
        $this->container = new Container();
        $this->container->scoped(SessionHolder::class);
        $this->router = new Router();

        $this->router->get('/counter', static function (ServerRequestInterface $request): string {
            $session = $request->getAttribute(Session::class);
            self::assertInstanceOf(Session::class, $session);
            $stored = $session->get('count', 0);
            $count = (is_int($stored) ? $stored : 0) + 1;
            $session->put('count', $count);

            return (string) $count;
        });
        $this->router->get('/flash', static function (ServerRequestInterface $request): string {
            $session = $request->getAttribute(Session::class);
            self::assertInstanceOf(Session::class, $session);
            $session->flash('status', 'Saved!');

            return 'flashed';
        });
        $this->router->get('/read', static function (ServerRequestInterface $request): string {
            $session = $request->getAttribute(Session::class);
            self::assertInstanceOf(Session::class, $session);

            $status = $session->get('status', 'none');

            return is_string($status) ? $status : 'none';
        });
        $this->router->get('/form', static function (ServerRequestInterface $r): string {
            $token = $r->getAttribute(VerifyCsrfToken::ATTRIBUTE);
            self::assertInstanceOf(CsrfToken::class, $token);

            return $token->value();
        });
        $this->router->post('/form', static fn(): string => 'accepted');
        $this->router->post('/api/hook', static fn(): string => 'hook ok');
        $this->router->get('/login', static function (ServerRequestInterface $request): string {
            $session = $request->getAttribute(Session::class);
            self::assertInstanceOf(Session::class, $session);
            $session->put('user', 7);
            $session->regenerate();

            return 'logged in';
        });
        $this->router->get('/logout', static function (ServerRequestInterface $request): string {
            $session = $request->getAttribute(Session::class);
            self::assertInstanceOf(Session::class, $session);
            $session->invalidate();

            return 'logged out';
        });
        $this->router->get('/plain', static fn(): string => 'no session use');
        $this->router->get('/login-then-fail', static function (ServerRequestInterface $request): string {
            $session = $request->getAttribute(Session::class);
            self::assertInstanceOf(Session::class, $session);
            $session->put('user', 7);
            $session->regenerate();

            throw new \RuntimeException('a later step failed');
        });
        $this->router->get('/user', static function (ServerRequestInterface $request): string {
            $session = $request->getAttribute(Session::class);
            self::assertInstanceOf(Session::class, $session);
            $user = $session->get('user', 'guest');

            return is_scalar($user) ? (string) $user : 'guest';
        });
        $this->router->get('/slow', function (): string {
            if ($this->during !== null) {
                ($this->during)();
            }

            return 'slow done';
        });
    }

    private function kernel(?SessionConfig $config = null): HttpKernel
    {
        $psr17 = new Psr17Factory();
        $responses = new ResponseFactory($psr17, $psr17);

        return new HttpKernel(
            $this->router,
            $this->container,
            new HandlerInvoker($this->container, $responses),
            new HttpErrorResponder($responses),
            $responses,
            [
                new StartSession($this->store, $this->container->make(SessionHolder::class), $config ?? new SessionConfig(), new HttpErrorResponder($responses)),
                new VerifyCsrfToken(['/api/*']),
            ],
        );
    }

    /**
     * @param array<string, string> $cookies
     * @param array<string, string>|null $body
     * @param array<string, string> $headers
     */
    private function send(string $method, string $uri, array $cookies = [], ?array $body = null, array $headers = []): ResponseInterface
    {
        $request = (new ServerRequest($method, $uri, $headers))->withCookieParams($cookies);

        return $this->kernel()->handle($body === null ? $request : $request->withParsedBody($body));
    }

    /**
     * @return array<string, string>
     */
    private static function cookies(ResponseInterface $response): array
    {
        $cookies = [];
        foreach ($response->getHeader('Set-Cookie') as $header) {
            [$pair] = explode(';', $header, 2);
            [$name, $value] = explode('=', $pair, 2);
            $cookies[$name] = rawurldecode($value);
        }

        return $cookies;
    }

    public function testSessionPersistsAcrossRequestsWithSecureCookie(): void
    {
        $first = $this->send('GET', '/counter');
        $cookie = $first->getHeaderLine('Set-Cookie');

        self::assertSame('1', (string) $first->getBody());
        self::assertMatchesRegularExpression('/^veyrophp_session=[a-f0-9]{64}; Max-Age=7200; Expires=[^;]+; Path=\/; HttpOnly; SameSite=Lax$/', $cookie);

        $second = $this->send('GET', '/counter', self::cookies($first));
        self::assertSame('2', (string) $second->getBody());
    }

    /**
     * VQ-046: the session was saved under the new id, so the error response must carry
     * that id; otherwise the browser keeps the old (destroyed) id and the user is logged out.
     */
    /**
     * HTTP-1: ConditionalGet runs inside the route, before StartSession adds the session
     * cookie, so it cannot see it. StartSession itself keeps shared caches away.
     */
    public function testAResponseWithTheSessionCookieIsNeverPubliclyCacheable(): void
    {
        $this->router->get('/sitemap.xml', static fn(): string => '<urlset/>')->middleware(ConditionalGet::cache(3600, true));
        $this->router->get('/mine', static fn(): string => 'mine')->middleware(ConditionalGet::cache(300));
        $this->router->get('/edge', static fn(): ResponseInterface => new Response(200, ['Cache-Control' => 'S-MaxAge=600, no-store'], 'edge'));
        $session = self::cookies($this->send('GET', '/counter'));

        $withSession = $this->send('GET', '/sitemap.xml', $session);
        self::assertArrayHasKey('veyrophp_session', self::cookies($withSession), 'the session cookie is sent');
        self::assertSame('private, no-cache', $withSession->getHeaderLine('Cache-Control'));
        self::assertSame('private, no-store', $this->send('GET', '/edge', $session)->getHeaderLine('Cache-Control'), 'no-store is never weakened');
        self::assertSame('private, max-age=300', $this->send('GET', '/mine', $session)->getHeaderLine('Cache-Control'), 'a private cache is fine');

        $anonymous = $this->send('GET', '/sitemap.xml');
        self::assertSame([], $anonymous->getHeader('Set-Cookie'));
        self::assertSame('public, max-age=3600', $anonymous->getHeaderLine('Cache-Control'), 'without a session nothing changes');
    }

    public function testAnErrorAfterRegenerateStillSendsTheNewSessionCookie(): void
    {
        $first = $this->send('GET', '/counter');
        $oldId = self::cookies($first)['veyrophp_session'];

        $failed = $this->send('GET', '/login-then-fail', self::cookies($first));

        self::assertSame(500, $failed->getStatusCode());
        self::assertStringNotContainsString('a later step failed', (string) $failed->getBody(), 'no details without debug');
        $newCookies = self::cookies($failed);
        self::assertArrayHasKey('veyrophp_session', $newCookies);
        self::assertNotSame($oldId, $newCookies['veyrophp_session']);
        self::assertSame('7', (string) $this->send('GET', '/user', $newCookies)->getBody());
        self::assertSame('guest', (string) $this->send('GET', '/user', ['veyrophp_session' => $oldId])->getBody(), 'the old id stays destroyed');
    }

    public function testAnErrorWithoutSessionChangesSetsNoCookie(): void
    {
        $this->router->get('/fail', static fn(): string => throw new \RuntimeException('boom'));

        $response = $this->send('GET', '/fail');

        self::assertSame(500, $response->getStatusCode());
        self::assertSame([], $response->getHeader('Set-Cookie'));
    }

    public function testSecureFlagOnHttps(): void
    {
        $response = $this->send('GET', 'https://example.com/counter');

        self::assertStringContainsString('; Secure;', $response->getHeaderLine('Set-Cookie'));
    }

    public function testUnusedSessionIsNotStoredAndSetsNoCookie(): void
    {
        $response = $this->send('GET', '/plain');

        self::assertFalse($response->hasHeader('Set-Cookie'));
    }

    public function testUnknownOrMalformedSessionIdsAreNeverAdopted(): void
    {
        $attackerId = str_repeat('a', 64);

        $response = $this->send('GET', '/counter', ['veyrophp_session' => $attackerId]);
        $issued = self::cookies($response)['veyrophp_session'] ?? '';

        self::assertNotSame($attackerId, $issued, 'session fixation: a client-chosen id must be replaced');
        self::assertSame('1', (string) $this->send('GET', '/counter', ['veyrophp_session' => '../../etc/passwd'])->getBody());
    }

    /**
     * VQ-014: a request that was already running with the old id when the user logged
     * out must not write the session back under that id when it finishes.
     */
    public function testARequestInFlightDoesNotResurrectALoggedOutSession(): void
    {
        $id = self::cookies($this->send('GET', '/login'))['veyrophp_session'] ?? '';
        $stored = $this->store->get('session.' . $id);
        self::assertIsArray($stored);
        self::assertSame(7, $stored['user'] ?? null);

        // The user logs out in another tab while this request is still running.
        $this->during = fn() => $this->send('GET', '/logout', ['veyrophp_session' => $id]);
        $slow = $this->send('GET', '/slow', ['veyrophp_session' => $id]);

        self::assertSame('slow done', (string) $slow->getBody());
        self::assertFalse(is_array($this->store->get('session.' . $id)), 'the old id holds no session data');
        self::assertArrayNotHasKey('veyrophp_session', self::cookies($slow), 'no cookie for the destroyed session');

        $next = $this->send('GET', '/counter', ['veyrophp_session' => $id]);
        self::assertSame('1', (string) $next->getBody(), 'a fresh session, not the old one');
        self::assertNotSame($id, self::cookies($next)['veyrophp_session'] ?? $id);
    }

    public function testRegeneratedIdsCannotBeWrittenBackEither(): void
    {
        $first = self::cookies($this->send('GET', '/counter'))['veyrophp_session'] ?? '';

        $this->during = fn() => $this->send('GET', '/login', ['veyrophp_session' => $first]); // regenerates the id
        $this->send('GET', '/slow', ['veyrophp_session' => $first]);

        self::assertFalse(is_array($this->store->get('session.' . $first)));
    }

    public function testSessionIdFormatIsExact(): void
    {
        self::assertTrue(Session::isValidId(str_repeat('a', 64)));
        self::assertFalse(Session::isValidId(str_repeat('a', 64) . "\n"), 'trailing newline');
        self::assertFalse(Session::isValidId(str_repeat('a', 63)));
        self::assertFalse(Session::isValidId(str_repeat('A', 64)));
    }

    public function testFlashDataLastsExactlyOneFollowingRequest(): void
    {
        $cookies = self::cookies($this->send('GET', '/flash'));

        self::assertSame('Saved!', (string) $this->send('GET', '/read', $cookies)->getBody());
        self::assertSame('none', (string) $this->send('GET', '/read', $cookies)->getBody());
    }

    public function testRegenerateIssuesNewIdAndDeletesOldData(): void
    {
        $initial = self::cookies($this->send('GET', '/counter'));
        $afterLogin = self::cookies($this->send('GET', '/login', $initial));

        self::assertNotSame($initial['veyrophp_session'], $afterLogin['veyrophp_session']);
        self::assertSame('2', (string) $this->send('GET', '/counter', $afterLogin)->getBody(), 'data survives regeneration');
        self::assertSame('1', (string) $this->send('GET', '/counter', $initial)->getBody(), 'the old id no longer works');
    }

    public function testInvalidateClearsData(): void
    {
        $cookies = self::cookies($this->send('GET', '/counter'));
        $afterLogout = self::cookies($this->send('GET', '/logout', $cookies));

        self::assertSame('1', (string) $this->send('GET', '/counter', $afterLogout)->getBody());
    }

    public function testCsrfTokenRequiredForStateChangingRequests(): void
    {
        $page = $this->send('GET', '/form');
        $token = (string) $page->getBody();
        $cookies = self::cookies($page);

        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
        self::assertSame(403, $this->send('POST', '/form', $cookies, [])->getStatusCode(), 'missing token');
        self::assertSame(403, $this->send('POST', '/form', $cookies, ['_token' => str_repeat('0', 64)])->getStatusCode(), 'wrong token');
        self::assertSame(403, $this->send('POST', '/form', [], ['_token' => $token])->getStatusCode(), 'token without its session');
        self::assertSame('accepted', (string) $this->send('POST', '/form', $cookies, ['_token' => $token])->getBody());
        self::assertSame('accepted', (string) $this->send('POST', '/form', $cookies, null, ['X-CSRF-Token' => $token])->getBody());
    }

    public function testCsrfExceptPathsAreSkipped(): void
    {
        self::assertSame('hook ok', (string) $this->send('POST', '/api/hook')->getBody());
    }

    public function testCsrfMismatchResponseExplainsTheProblem(): void
    {
        $response = $this->send('POST', '/form', [], [], ['Accept' => 'application/json']);

        self::assertStringContainsString('"status":403', (string) $response->getBody());
        self::assertStringContainsString('CSRF token is invalid', (string) $response->getBody());
    }

    public function testSessionApiDirectly(): void
    {
        $session = new Session(Session::generateId());
        $session->put('a', 1);
        $session->put('b', 2);

        self::assertSame(1, $session->pull('a'));
        self::assertFalse($session->has('a'));
        $session->token();
        self::assertSame(['b' => 2], $session->all(), 'internal keys are hidden');

        $this->expectException(\InvalidArgumentException::class);
        new Session('not-a-valid-id');
    }

    public function testHolderWithoutSessionThrows(): void
    {
        $this->expectException(SessionException::class);
        (new SessionHolder())->session();
    }

    public function testCookieValidation(): void
    {
        self::assertSame('a=; Max-Age=0; Expires=Thu, 01 Jan 1970 00:00:00 GMT; Path=/; HttpOnly; SameSite=Lax', Cookie::forget('a')->toHeaderValue());

        foreach ([fn() => new Cookie('bad name', 'v'), fn() => new Cookie('a', 'v', sameSite: 'none'), fn() => new Cookie('a', 'v', path: '/;evil'), fn() => new Cookie('a', 'v', sameSite: 'weird')] as $invalid) {
            try {
                $invalid();
                self::fail('Expected InvalidArgumentException.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testRedirectOnlyAllowsLocalPathsByDefault(): void
    {
        $psr17 = new Psr17Factory();
        $responses = new ResponseFactory($psr17, $psr17);

        self::assertSame('/dashboard?tab=1', $responses->redirect('/dashboard?tab=1')->getHeaderLine('Location'));
        self::assertSame('https://partner.example', $responses->redirect('https://partner.example', allowExternal: true)->getHeaderLine('Location'));

        foreach (['https://evil.example', '//evil.example', '/\\evil.example', '/a\\b', 'evil.example', 'javascript:alert(1)', "/ok\r\nSet-Cookie: x=y"] as $unsafe) {
            try {
                $responses->redirect($unsafe);
                self::fail('Accepted unsafe redirect: ' . $unsafe);
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(\InvalidArgumentException::class);
        $responses->redirect('/x', 200);
    }
}
