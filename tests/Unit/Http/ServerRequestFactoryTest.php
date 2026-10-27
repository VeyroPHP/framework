<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use VeyroPHP\Http\ClientIp;
use VeyroPHP\Http\ServerRequestFactory;
use VeyroPHP\Security\TrustProxies;

/**
 * VQ-068: the scheme comes from the server, never from a client's X-Forwarded-Proto;
 * TrustProxies applies that header for trusted proxies only.
 */
#[CoversClass(ServerRequestFactory::class)]
final class ServerRequestFactoryTest extends TestCase
{
    /** @var array<mixed> */
    private array $server = [];

    protected function setUp(): void
    {
        $this->server = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
    }

    /**
     * @param array<string, string> $server
     */
    private static function fromServer(array $server): ServerRequestInterface
    {
        $_SERVER = $server + [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/login',
            'HTTP_HOST' => 'app.example.com',
            'SERVER_NAME' => 'app.example.com',
            'SERVER_PORT' => '80',
            'REMOTE_ADDR' => '203.0.113.9',
        ];

        return ServerRequestFactory::fromGlobals();
    }

    public function testPlainHttp(): void
    {
        self::assertSame('http://app.example.com/login', (string) self::fromServer([])->getUri());
    }

    public function testHttpsFromTheServer(): void
    {
        self::assertSame('https://app.example.com/login', (string) self::fromServer(['HTTPS' => 'on', 'SERVER_PORT' => '443'])->getUri());
        self::assertSame('https', self::fromServer(['REQUEST_SCHEME' => 'https', 'SERVER_PORT' => '443'])->getUri()->getScheme());
    }

    public function testAClientsForwardedProtoDoesNotChangeTheScheme(): void
    {
        $request = self::fromServer(['HTTP_X_FORWARDED_PROTO' => 'https']);

        self::assertSame('http://app.example.com/login', (string) $request->getUri());
        // The header itself stays, so TrustProxies can use it for trusted proxies.
        self::assertSame('https', $request->getHeaderLine('X-Forwarded-Proto'));
    }

    public function testAForwardedProtoDoesNotHideTheRealHttps(): void
    {
        self::assertSame('https://app.example.com/login', (string) self::fromServer(['HTTPS' => 'on', 'SERVER_PORT' => '443', 'HTTP_X_FORWARDED_PROTO' => 'http'])->getUri());
    }

    public function testInvalidSchemesFromClientsAreIgnored(): void
    {
        foreach (['javascript', 'ftp', '', 'https, http'] as $value) {
            self::assertSame('http', self::fromServer(['HTTP_X_FORWARDED_PROTO' => $value])->getUri()->getScheme(), $value);
        }
    }

    public function testTheInternalPortIsKeptUntilATrustedProxyRestoresTheScheme(): void
    {
        $server = ['HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_HOST' => 'app.example.com:8080', 'SERVER_PORT' => '8080'];
        $trust = new TrustProxies(new ClientIp(['10.0.0.0/8']));

        $fromProxy = $trust->apply(self::fromServer($server + ['REMOTE_ADDR' => '10.0.0.5']));
        self::assertSame('https://app.example.com/login', (string) $fromProxy->getUri());

        $fromClient = $trust->apply(self::fromServer($server));
        self::assertSame('http://app.example.com:8080/login', (string) $fromClient->getUri());
    }

    public function testWithoutTrustedProxiesNothingChanges(): void
    {
        $request = (new TrustProxies(new ClientIp([])))->apply(self::fromServer(['HTTP_X_FORWARDED_PROTO' => 'https', 'REMOTE_ADDR' => '127.0.0.1']));

        self::assertSame('http', $request->getUri()->getScheme());
    }
}
