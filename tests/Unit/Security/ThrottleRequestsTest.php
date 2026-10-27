<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Unit\Security;

use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use VeyroPHP\Cache\ArrayStore;
use VeyroPHP\Cache\Cache;
use VeyroPHP\Container\Container;
use VeyroPHP\Http\ClientIp;
use VeyroPHP\Http\HttpErrorResponder;
use VeyroPHP\Http\HttpKernel;
use VeyroPHP\Http\ResponseFactory;
use VeyroPHP\Routing\HandlerInvoker;
use VeyroPHP\Routing\Router;
use VeyroPHP\Security\RateLimiter;
use VeyroPHP\Security\ThrottleRequests;

#[CoversClass(ThrottleRequests::class)]
final class ThrottleRequestsTest extends TestCase
{
    private int $now = 1_000_000;

    private HttpKernel $kernel;

    protected function setUp(): void
    {
        $this->kernel = $this->kernel();
    }

    private function kernel(?int $ipv6Prefix = null): HttpKernel
    {
        $container = new Container();
        $limiter = new RateLimiter(new Cache(new ArrayStore(fn(): int => $this->now), 'app'), fn(): int => $this->now);
        $container->instance(RateLimiter::class, $limiter);
        $clientIp = new ClientIp(['10.0.0.0/8']);
        $container->instance(ClientIp::class, $clientIp);
        if ($ipv6Prefix !== null) {
            $container->instance(ThrottleRequests::class, new ThrottleRequests($limiter, $clientIp, $ipv6Prefix));
        }

        $router = new Router();
        $router->get('/limited', static fn(): string => 'ok')->middleware(ThrottleRequests::limit(3));
        $router->get('/shared-a', static fn(): string => 'a')->middleware(ThrottleRequests::limit(2, 1, 'shared'));
        $router->get('/shared-b', static fn(): string => 'b')->middleware(ThrottleRequests::limit(2, 1, 'shared'));
        $router->get('/slow/{id}', static fn(): string => 'slow')->middleware(ThrottleRequests::limit(1, 5));
        $router->get('/login', static fn(): string => 'login')->middleware(ThrottleRequests::limit(2));
        $router->get('/users/{id:\d+}', static fn(int $id): string => 'user ' . $id)->middleware(ThrottleRequests::limit(2));
        $router->get('/other', static fn(): string => 'other')->middleware(ThrottleRequests::limit(3));

        $psr17 = new Psr17Factory();
        $responses = new ResponseFactory($psr17, $psr17);

        return new HttpKernel($router, $container, new HandlerInvoker($container, $responses), new HttpErrorResponder($responses), $responses);
    }

    private function get(string $path, string $remote = '203.0.113.1', ?string $forwardedFor = null): ResponseInterface
    {
        $headers = $forwardedFor === null ? [] : ['X-Forwarded-For' => $forwardedFor];

        return $this->kernel->handle(new ServerRequest('GET', $path, $headers, null, '1.1', ['REMOTE_ADDR' => $remote]));
    }

    public function testLimitBuildsMiddlewareString(): void
    {
        self::assertSame(ThrottleRequests::class . ':5,1', ThrottleRequests::limit(5));
        self::assertSame(ThrottleRequests::class . ':10,2,login', ThrottleRequests::limit(10, 2, 'login'));
    }

    public function testAllowsUpToTheLimitThenRejectsWith429(): void
    {
        foreach ([2, 1, 0] as $remaining) {
            $response = $this->get('/limited');
            self::assertSame(200, $response->getStatusCode());
            self::assertSame('3', $response->getHeaderLine('X-RateLimit-Limit'));
            self::assertSame((string) $remaining, $response->getHeaderLine('X-RateLimit-Remaining'));
        }

        $this->now += 20;
        $blocked = $this->get('/limited');
        self::assertSame(429, $blocked->getStatusCode());
        self::assertSame('40', $blocked->getHeaderLine('Retry-After'));

        $this->now += 40;
        self::assertSame(200, $this->get('/limited')->getStatusCode());
    }

    public function testLimitsArePerClientIp(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->get('/limited', '203.0.113.1');
        }

        self::assertSame(429, $this->get('/limited', '203.0.113.1')->getStatusCode());
        self::assertSame(200, $this->get('/limited', '203.0.113.2')->getStatusCode());
    }

    public function testSpoofedForwardedForDoesNotEscapeTheLimit(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->get('/limited', '203.0.113.1', '198.51.100.' . $i);
        }

        self::assertSame(429, $this->get('/limited', '203.0.113.1', '198.51.100.99')->getStatusCode());
    }

    public function testTrustedProxyForwardsDistinctClients(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->get('/limited', '10.0.0.1', '198.51.100.1');
        }

        self::assertSame(429, $this->get('/limited', '10.0.0.1', '198.51.100.1')->getStatusCode());
        self::assertSame(200, $this->get('/limited', '10.0.0.1', '198.51.100.2')->getStatusCode());
    }

    public function testNamedBucketIsSharedAcrossRoutes(): void
    {
        self::assertSame(200, $this->get('/shared-a')->getStatusCode());
        self::assertSame(200, $this->get('/shared-b')->getStatusCode());
        self::assertSame(429, $this->get('/shared-a')->getStatusCode());
    }

    public function testDecayUsesMinutes(): void
    {
        self::assertSame(200, $this->get('/slow/1')->getStatusCode());
        self::assertSame(429, $this->get('/slow/1')->getStatusCode());

        // Without a bucket the key is the matched route (VQ-013): every id of /slow/{id} shares it.
        self::assertSame(429, $this->get('/slow/2')->getStatusCode());

        $this->now += 299;
        self::assertSame('1', $this->get('/slow/1')->getHeaderLine('Retry-After'));
        $this->now += 1;
        self::assertSame(200, $this->get('/slow/1')->getStatusCode());
    }

    /**
     * VQ-013: the same route reached through another spelling of the URL is the same bucket.
     */
    public function testEquivalentUrlsShareTheRouteBucket(): void
    {
        self::assertSame(200, $this->get('/login')->getStatusCode());
        self::assertSame(200, $this->get('/%6Cogin')->getStatusCode());
        self::assertSame(429, $this->get('/l%6Fgin')->getStatusCode());
        self::assertSame(429, $this->get('/%6C%6F%67%69%6E')->getStatusCode());

        self::assertSame(200, $this->get('/users/1')->getStatusCode());
        self::assertSame(200, $this->get('/users/01')->getStatusCode());
        self::assertSame(429, $this->get('/users/%31')->getStatusCode());
        self::assertSame(429, $this->get('/users/0001')->getStatusCode());
        self::assertSame(429, $this->get('/users/2')->getStatusCode(), 'other parameters share the route limit');
    }

    public function testDifferentRoutesHaveTheirOwnBuckets(): void
    {
        $this->get('/login');
        $this->get('/login');
        self::assertSame(429, $this->get('/login')->getStatusCode());
        self::assertSame(200, $this->get('/other')->getStatusCode());
        self::assertSame(200, $this->get('/limited')->getStatusCode());
    }

    public function testIpv6AddressesOfOneSlash64ShareABucket(): void
    {
        foreach (['2001:db8:1:2::1', '2001:db8:1:2::2', '2001:db8:1:2:ffff:ffff:ffff:ffff'] as $address) {
            self::assertSame(200, $this->get('/limited', $address)->getStatusCode(), $address);
        }

        self::assertSame(429, $this->get('/limited', '2001:db8:1:2::abcd')->getStatusCode(), 'same /64');
        self::assertSame(200, $this->get('/limited', '2001:db8:1:3::1')->getStatusCode(), 'another /64');
        self::assertSame(200, $this->get('/limited', '203.0.113.9')->getStatusCode(), 'IPv4 is not affected');
    }

    public function testTheIpv6PrefixIsConfigurable(): void
    {
        $kernel = $this->kernel(128);

        for ($i = 1; $i <= 4; $i++) {
            $response = $kernel->handle(new ServerRequest('GET', '/limited', [], null, '1.1', ['REMOTE_ADDR' => '2001:db8:1:2::' . $i]));
            self::assertSame(200, $response->getStatusCode(), 'with /128 every address has its own bucket');
        }
    }

    public function testClientsBehindOneIpv4AddressShareABucket(): void
    {
        // Documented impact: an office or carrier NAT shares one limit, as before.
        $this->get('/limited', '198.51.100.7');
        $this->get('/limited', '198.51.100.7');
        $this->get('/limited', '198.51.100.7');

        self::assertSame(429, $this->get('/limited', '198.51.100.7')->getStatusCode());
    }

    public function testRejectsBadArguments(): void
    {
        $middleware = new ThrottleRequests(new RateLimiter(new Cache(new ArrayStore())), new ClientIp());

        foreach ([['0'], ['abc'], ['5', '0'], ['5', '-1'], []] as $arguments) {
            try {
                $middleware->withArguments(...$arguments);
                self::fail('Expected rejection of ' . implode(',', $arguments));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
