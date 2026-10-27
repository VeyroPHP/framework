<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Unit\Security;

use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use VeyroPHP\Http\Exception\HttpException;
use VeyroPHP\Http\HttpErrorResponder;
use VeyroPHP\Http\ResponseFactory;
use VeyroPHP\Security\SecurityHeaders;

#[CoversClass(SecurityHeaders::class)]
final class SecurityHeadersTest extends TestCase
{
    /**
     * @param array<string, string> $responseHeaders
     */
    private static function apply(SecurityHeaders $middleware, string $uri = 'http://example.test/', array $responseHeaders = []): ResponseInterface
    {
        $handler = new class ($responseHeaders) implements RequestHandlerInterface {
            /**
             * @param array<string, string> $headers
             */
            public function __construct(private readonly array $headers) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200, $this->headers, 'ok');
            }
        };

        return $middleware->process(new ServerRequest('GET', $uri), $handler);
    }

    public function testAddsDefaultHeaders(): void
    {
        $response = self::apply(new SecurityHeaders());

        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('SAMEORIGIN', $response->getHeaderLine('X-Frame-Options'));
        self::assertSame('strict-origin-when-cross-origin', $response->getHeaderLine('Referrer-Policy'));
        self::assertSame('camera=(), microphone=(), geolocation=()', $response->getHeaderLine('Permissions-Policy'));
        self::assertSame('same-origin', $response->getHeaderLine('Cross-Origin-Opener-Policy'));
        self::assertSame(SecurityHeaders::DEFAULT_CSP, $response->getHeaderLine('Content-Security-Policy'));
        self::assertFalse($response->hasHeader('Strict-Transport-Security'));
    }

    public function testDefaultCspBlocksInlineScriptsAndFraming(): void
    {
        self::assertStringContainsString("script-src 'self';", SecurityHeaders::DEFAULT_CSP);
        self::assertStringNotContainsString("script-src 'self' 'unsafe-inline'", SecurityHeaders::DEFAULT_CSP);
        self::assertStringContainsString("object-src 'none'", SecurityHeaders::DEFAULT_CSP);
        self::assertStringContainsString("frame-ancestors 'self'", SecurityHeaders::DEFAULT_CSP);
    }

    public function testOverridesReplaceOrDisableDefaultsAndAddNewHeaders(): void
    {
        $response = self::apply(new SecurityHeaders([
            'X-Frame-Options' => 'DENY',
            'Content-Security-Policy' => null,
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ]));

        self::assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
        self::assertFalse($response->hasHeader('Content-Security-Policy'));
        self::assertSame('same-origin', $response->getHeaderLine('Cross-Origin-Resource-Policy'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
    }

    public function testKeepsHeadersTheHandlerSet(): void
    {
        $response = self::apply(new SecurityHeaders(), 'http://example.test/', ['Content-Security-Policy' => "default-src 'none'"]);

        self::assertSame("default-src 'none'", $response->getHeaderLine('Content-Security-Policy'));
    }

    public function testHstsOnlyOnHttpsAndWhenEnabled(): void
    {
        self::assertFalse(self::apply(new SecurityHeaders([], 31536000), 'http://example.test/')->hasHeader('Strict-Transport-Security'));
        self::assertFalse(self::apply(new SecurityHeaders(), 'https://example.test/')->hasHeader('Strict-Transport-Security'));
        self::assertSame(
            'max-age=31536000; includeSubDomains',
            self::apply(new SecurityHeaders([], 31536000), 'https://example.test/')->getHeaderLine('Strict-Transport-Security'),
        );
    }

    public function testErrorResponsesGetHeadersWhenAResponderIsGiven(): void
    {
        $failing = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new HttpException(403, 'CSRF token mismatch.');
            }
        };
        $psr17 = new Psr17Factory();

        $response = (new SecurityHeaders([], 0, new HttpErrorResponder(new ResponseFactory($psr17, $psr17))))
            ->process(new ServerRequest('POST', '/'), $failing);
        self::assertSame(403, $response->getStatusCode());
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));

        $this->expectException(HttpException::class);
        (new SecurityHeaders())->process(new ServerRequest('POST', '/'), $failing);
    }

    public function testRejectsHeaderInjection(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SecurityHeaders(['X-Custom' => "a\r\nSet-Cookie: evil=1"]);
    }
}
