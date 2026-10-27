<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Security;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use VeyroPHP\Application\Application;
use VeyroPHP\Cache\CacheServiceProvider;
use VeyroPHP\Config\Config;
use VeyroPHP\Container\Container;
use VeyroPHP\Http\ClientIp;
use VeyroPHP\Http\HttpKernel;
use VeyroPHP\Http\HttpServiceProvider;
use VeyroPHP\Routing\Router;
use VeyroPHP\Security\Cors;
use VeyroPHP\Security\RateLimiter;
use VeyroPHP\Security\SecurityHeaders;
use VeyroPHP\Security\SecurityServiceProvider;
use VeyroPHP\Security\ThrottleRequests;
use VeyroPHP\Security\TrustProxies;
use VeyroPHP\Security\UploadStore;
use VeyroPHP\Session\CsrfToken;
use VeyroPHP\Session\SessionServiceProvider;
use VeyroPHP\Session\StartSession;
use VeyroPHP\Session\VerifyCsrfToken;

/**
 * Attacks run against a fully wired application (providers, global middleware,
 * router). Each test names the threat from docs/security/threat-model.md.
 */
#[CoversClass(SecurityServiceProvider::class)]
final class AttackScenariosTest extends TestCase
{
    private Container $container;

    private HttpKernel $kernel;

    protected function setUp(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->container()->instance(Config::class, new Config([
            'app' => ['debug' => false],
            'cache' => ['default' => 'array', 'prefix' => 'sec', 'stores' => ['array' => ['driver' => 'array']]],
            'security' => [
                'trusted_proxies' => ['10.0.0.0/8'],
                'hsts_seconds' => 600,
                'cors' => ['allowed_origins' => ['https://spa.example.com'], 'allow_credentials' => true],
                'uploads_path' => 'veyrophp-sec-uploads',
            ],
        ]));
        $app->register(new CacheServiceProvider());
        $app->register(new SessionServiceProvider());
        $app->register(new HttpServiceProvider([], [TrustProxies::class, Cors::class, SecurityHeaders::class, StartSession::class, VerifyCsrfToken::class]));
        $app->register(new SecurityServiceProvider());
        $app->boot();

        $this->container = $app->container();
        $router = $this->container->make(Router::class);
        $router->get('/form', static function (ServerRequestInterface $r): string {
            $token = $r->getAttribute(VerifyCsrfToken::ATTRIBUTE);

            return $token instanceof CsrfToken ? $token->value() : '';
        });
        $router->post('/transfer', static fn(): string => 'transferred');
        $router->post('/login', static fn(): string => 'checked')->middleware(ThrottleRequests::limit(3, 1, 'login'));
        $router->get('/crash', static function (): never {
            throw new RuntimeException('DB password is hunter2');
        });

        $this->kernel = $this->container->make(HttpKernel::class);
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, string> $cookies
     */
    private function send(string $method, string $uri, array $headers = [], array $cookies = [], string $remote = '203.0.113.5'): ResponseInterface
    {
        $request = new ServerRequest($method, $uri, $headers, null, '1.1', ['REMOTE_ADDR' => $remote]);

        return $this->kernel->handle($request->withCookieParams($cookies));
    }

    public function testHttpsEndingAtATrustedProxyKeepsSecureCookiesAndHsts(): void
    {
        // TLS ends at the load balancer (10.0.0.0/8 is trusted); PHP sees plain http.
        $response = $this->send('GET', 'http://bank.example/form', ['X-Forwarded-Proto' => 'https'], [], '10.1.2.3');

        self::assertSame('max-age=600; includeSubDomains', $response->getHeaderLine('Strict-Transport-Security'));
        self::assertStringContainsStringIgnoringCase('; Secure', $response->getHeaderLine('Set-Cookie'));
    }

    public function testForgedForwardedProtoFromAClientIsIgnored(): void
    {
        $response = $this->send('GET', 'http://bank.example/form', ['X-Forwarded-Proto' => 'https']);

        self::assertFalse($response->hasHeader('Strict-Transport-Security'));
        self::assertStringNotContainsStringIgnoringCase('; Secure', $response->getHeaderLine('Set-Cookie'));
    }

    public function testProviderWiresConfiguredServices(): void
    {
        self::assertInstanceOf(RateLimiter::class, $this->container->make(RateLimiter::class));
        self::assertTrue($this->container->make(ClientIp::class)->isTrusted('10.9.9.9'));
        self::assertFalse($this->container->make(ClientIp::class)->isTrusted('203.0.113.5'));
        self::assertInstanceOf(UploadStore::class, $this->container->make(UploadStore::class));
        self::assertSame($this->container->make(Cors::class), $this->container->make(Cors::class));
    }

    public function testCsrfForgedPostIsRejectedAndStillHardened(): void
    {
        $response = $this->send('POST', 'https://bank.example/transfer');

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('SAMEORIGIN', $response->getHeaderLine('X-Frame-Options'));
        self::assertSame('max-age=600; includeSubDomains', $response->getHeaderLine('Strict-Transport-Security'));
    }

    public function testClickjackingAndSniffingHeadersOnNormalPages(): void
    {
        $response = $this->send('GET', 'http://bank.example/form');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString("frame-ancestors 'self'", $response->getHeaderLine('Content-Security-Policy'));
        self::assertFalse($response->hasHeader('Strict-Transport-Security'), 'no HSTS over plain HTTP');

        $cookie = $response->getHeaderLine('Set-Cookie');
        self::assertStringContainsStringIgnoringCase('HttpOnly', $cookie);
        self::assertStringContainsStringIgnoringCase('SameSite=Lax', $cookie);
    }

    public function testBruteForceLoginIsThrottledEvenWithSpoofedForwardedFor(): void
    {
        // The attacker uses a real session and CSRF token, like a scripted browser would.
        $form = $this->send('GET', 'http://bank.example/form');
        [$pair] = explode(';', $form->getHeaderLine('Set-Cookie'), 2);
        [$name, $value] = explode('=', $pair, 2);
        $cookies = [$name => urldecode($value)];
        $headers = ['X-CSRF-Token' => (string) $form->getBody()];

        $statuses = [];
        for ($i = 0; $i < 5; $i++) {
            // Rotating X-Forwarded-For is ignored because the peer is not a trusted proxy.
            $statuses[] = $this->send('POST', 'http://bank.example/login', $headers + ['X-Forwarded-For' => '198.51.100.' . $i], $cookies)->getStatusCode();
        }

        self::assertSame([200, 200, 200, 429, 429], $statuses);
        self::assertNotSame('', $this->send('POST', 'http://bank.example/login', $headers, $cookies)->getHeaderLine('Retry-After'));
    }

    public function testRequestsWithoutCsrfTokenNeverReachTheLoginCheck(): void
    {
        // CSRF runs first, so tokenless floods are rejected cheaply and do not use up the user's limit.
        for ($i = 0; $i < 5; $i++) {
            self::assertSame(403, $this->send('POST', 'http://bank.example/login')->getStatusCode());
        }
    }

    public function testErrorsDoNotLeakInternals(): void
    {
        $response = $this->send('GET', 'http://bank.example/crash');

        self::assertSame(500, $response->getStatusCode());
        self::assertStringNotContainsString('hunter2', (string) $response->getBody());
        self::assertStringNotContainsString('RuntimeException', (string) $response->getBody());
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
    }

    public function testForeignOriginGetsNoCorsGrant(): void
    {
        $evil = $this->send('GET', 'http://bank.example/form', ['Origin' => 'https://evil.example']);
        self::assertFalse($evil->hasHeader('Access-Control-Allow-Origin'));
        self::assertFalse($evil->hasHeader('Access-Control-Allow-Credentials'));

        $spa = $this->send('OPTIONS', 'http://bank.example/transfer', ['Origin' => 'https://spa.example.com', 'Access-Control-Request-Method' => 'POST']);
        self::assertSame(204, $spa->getStatusCode());
        self::assertSame('https://spa.example.com', $spa->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('true', $spa->getHeaderLine('Access-Control-Allow-Credentials'));
    }

    public function testCorsDoesNotBypassCsrf(): void
    {
        // A credentialed cross-origin POST from an allowed origin still needs the CSRF token.
        $response = $this->send('POST', 'http://bank.example/transfer', ['Origin' => 'https://spa.example.com']);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('https://spa.example.com', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }
}
