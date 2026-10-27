<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Unit\Security;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use VeyroPHP\Http\ClientIp;
use VeyroPHP\Security\TrustProxies;

#[CoversClass(TrustProxies::class)]
final class TrustProxiesTest extends TestCase
{
    /**
     * @param array<string, string> $headers
     */
    private static function request(string $remote, array $headers, string $uri = 'http://app.example.com:8080/login'): ServerRequest
    {
        return new ServerRequest('GET', $uri, $headers + ['Host' => 'app.example.com:8080'], null, '1.1', ['REMOTE_ADDR' => $remote]);
    }

    private static function middleware(): TrustProxies
    {
        return new TrustProxies(new ClientIp(['10.0.0.0/8']));
    }

    public function testTrustedProxyRestoresHttps(): void
    {
        $uri = self::middleware()->apply(self::request('10.0.0.5', ['X-Forwarded-Proto' => 'https']))->getUri();

        self::assertSame('https', $uri->getScheme());
        self::assertNull($uri->getPort(), 'the internal port 8080 is dropped');
        self::assertSame('https://app.example.com/login', (string) $uri);
    }

    public function testForwardedPortIsUsed(): void
    {
        $uri = self::middleware()->apply(self::request('10.0.0.5', ['X-Forwarded-Proto' => 'https', 'X-Forwarded-Port' => '8443']))->getUri();

        self::assertSame('https://app.example.com:8443/login', (string) $uri);
    }

    public function testFirstEntryOfAListIsUsed(): void
    {
        $uri = self::middleware()->apply(self::request('10.0.0.5', ['X-Forwarded-Proto' => 'HTTPS, http']))->getUri();

        self::assertSame('https', $uri->getScheme());
    }

    public function testUntrustedPeersCannotChangeTheScheme(): void
    {
        $request = self::request('203.0.113.9', ['X-Forwarded-Proto' => 'https', 'X-Forwarded-Port' => '443']);

        self::assertSame($request, self::middleware()->apply($request));
    }

    public function testInvalidValuesAreIgnored(): void
    {
        foreach (['ftp', 'javascript', '', 'https://evil'] as $proto) {
            $request = self::request('10.0.0.5', ['X-Forwarded-Proto' => $proto]);
            self::assertSame('http', self::middleware()->apply($request)->getUri()->getScheme(), $proto);
        }

        $uri = self::middleware()->apply(self::request('10.0.0.5', ['X-Forwarded-Proto' => 'https', 'X-Forwarded-Port' => '99999']))->getUri();
        self::assertNull($uri->getPort());
    }

    public function testHostIsNeverTakenFromForwardedHost(): void
    {
        $request = self::request('10.0.0.5', ['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'evil.example']);
        $result = self::middleware()->apply($request);

        self::assertSame('app.example.com', $result->getUri()->getHost());
        self::assertSame('app.example.com:8080', $result->getHeaderLine('Host'));
    }

    public function testWithoutTrustedProxiesNothingChanges(): void
    {
        $request = self::request('10.0.0.5', ['X-Forwarded-Proto' => 'https']);

        self::assertSame($request, (new TrustProxies(new ClientIp()))->apply($request));
    }
}
