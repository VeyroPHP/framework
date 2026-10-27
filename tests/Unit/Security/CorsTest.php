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
use RuntimeException;
use VeyroPHP\Http\Exception\HttpException;
use VeyroPHP\Http\HttpErrorResponder;
use VeyroPHP\Http\ResponseFactory;
use VeyroPHP\Security\Cors;

#[CoversClass(Cors::class)]
final class CorsTest extends TestCase
{
    private const APP = 'https://app.example.com';

    /** @var \ArrayObject<int, string> methods that reached the handler */
    private \ArrayObject $handled;

    protected function setUp(): void
    {
        $this->handled = new \ArrayObject();
    }

    /**
     * @param list<string> $origins
     * @param list<string> $exposed
     */
    private function cors(array $origins = [self::APP], bool $credentials = false, array $exposed = []): Cors
    {
        $psr17 = new Psr17Factory();
        $responses = new ResponseFactory($psr17, $psr17);

        return new Cors(new HttpErrorResponder($responses), $responses, $origins, ['GET', 'POST'], ['Content-Type', 'X-CSRF-Token'], $exposed, 600, $credentials);
    }

    /**
     * @param array<string, string> $headers
     */
    private function send(Cors $cors, string $method = 'GET', array $headers = [], ?\Throwable $throw = null): ResponseInterface
    {
        $handler = new class ($this->handled, $throw) implements RequestHandlerInterface {
            /**
             * @param \ArrayObject<int, string> $handled
             */
            public function __construct(private readonly \ArrayObject $handled, private readonly ?\Throwable $throw) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->handled[] = $request->getMethod();
                if ($this->throw !== null) {
                    throw $this->throw;
                }

                return new Response(200, ['Content-Type' => 'application/json'], '{"ok":true}');
            }
        };

        return $cors->process(new ServerRequest($method, 'https://api.example.com/data', $headers), $handler);
    }

    public function testAllowedOriginGetsHeaders(): void
    {
        $response = $this->send($this->cors(), 'GET', ['Origin' => self::APP]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(self::APP, $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('Origin', $response->getHeaderLine('Vary'));
        self::assertFalse($response->hasHeader('Access-Control-Allow-Credentials'));
    }

    public function testOtherOriginsAndSameOriginRequestsGetNoCorsHeaders(): void
    {
        foreach ([['Origin' => 'https://evil.example'], ['Origin' => 'https://app.example.com.evil.example'], []] as $headers) {
            $response = $this->send($this->cors(), 'GET', $headers);

            self::assertSame(200, $response->getStatusCode());
            self::assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
            self::assertSame('Origin', $response->getHeaderLine('Vary'), 'caches must still vary on Origin');
        }
    }

    public function testPreflightIsAnsweredWithoutReachingTheHandler(): void
    {
        $response = $this->send($this->cors(), 'OPTIONS', [
            'Origin' => self::APP,
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'Content-Type',
        ]);

        self::assertSame(204, $response->getStatusCode());
        self::assertSame([], $this->handled->getArrayCopy());
        self::assertSame(self::APP, $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('GET, POST', $response->getHeaderLine('Access-Control-Allow-Methods'));
        self::assertSame('Content-Type, X-CSRF-Token', $response->getHeaderLine('Access-Control-Allow-Headers'));
        self::assertSame('600', $response->getHeaderLine('Access-Control-Max-Age'));
        self::assertStringContainsString('Origin', $response->getHeaderLine('Vary'));
    }

    public function testPreflightFromDisallowedOriginGoesToTheApp(): void
    {
        $response = $this->send($this->cors(), 'OPTIONS', ['Origin' => 'https://evil.example', 'Access-Control-Request-Method' => 'POST']);

        self::assertSame(['OPTIONS'], $this->handled->getArrayCopy());
        self::assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
    }

    public function testPlainOptionsWithoutRequestMethodIsNotAPreflight(): void
    {
        $this->send($this->cors(), 'OPTIONS', ['Origin' => self::APP]);

        self::assertSame(['OPTIONS'], $this->handled->getArrayCopy());
    }

    public function testErrorsForAllowedOriginsKeepCorsHeaders(): void
    {
        $response = $this->send($this->cors(), 'GET', ['Origin' => self::APP], new HttpException(404, 'Not here'));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(self::APP, $response->getHeaderLine('Access-Control-Allow-Origin'));

        $crash = $this->send($this->cors(), 'GET', ['Origin' => self::APP], new RuntimeException('secret detail'));
        self::assertSame(500, $crash->getStatusCode());
        self::assertStringNotContainsString('secret detail', (string) $crash->getBody());
        self::assertSame(self::APP, $crash->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function testErrorsForOtherOriginsAreRethrown(): void
    {
        $this->expectException(RuntimeException::class);
        $this->send($this->cors(), 'GET', ['Origin' => 'https://evil.example'], new RuntimeException('boom'));
    }

    public function testWildcardAndCredentials(): void
    {
        $wildcard = $this->send($this->cors(['*']), 'GET', ['Origin' => 'https://anyone.example']);
        self::assertSame('*', $wildcard->getHeaderLine('Access-Control-Allow-Origin'));

        $credentials = $this->send($this->cors([self::APP], true, ['X-Total']), 'GET', ['Origin' => self::APP]);
        self::assertSame(self::APP, $credentials->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('true', $credentials->getHeaderLine('Access-Control-Allow-Credentials'));
        self::assertSame('X-Total', $credentials->getHeaderLine('Access-Control-Expose-Headers'));
    }

    public function testWildcardWithCredentialsIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('credentials');
        $this->cors(['*'], true);
    }

    public function testInvalidOriginsAreRejected(): void
    {
        foreach (['app.example.com', 'https://app.example.com/', 'https://app.example.com/path', 'ftp://x.example', 'null'] as $origin) {
            try {
                $this->cors([$origin]);
                self::fail('Expected rejection of ' . $origin);
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
