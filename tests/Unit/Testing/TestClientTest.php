<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Unit\Testing;

use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use VeyroPHP\Testing\TestClient;

#[CoversClass(TestClient::class)]
final class TestClientTest extends TestCase
{
    /** @var \ArrayObject<int, ServerRequestInterface> */
    private \ArrayObject $requests;

    private TestClient $client;

    protected function setUp(): void
    {
        $this->requests = new \ArrayObject();
        $kernel = new class ($this->requests) implements RequestHandlerInterface {
            /**
             * @param \ArrayObject<int, ServerRequestInterface> $requests
             */
            public function __construct(private readonly \ArrayObject $requests) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->requests->append($request);

                return match ($request->getUri()->getPath()) {
                    '/form' => new Response(200, ['Set-Cookie' => ['sid=a%2Bb%20c; Path=/; HttpOnly', 'theme=dark']], '<form><input type="hidden" name="_token" value="t&amp;1"></form>'),
                    '/swapped' => new Response(200, [], '<input value="xyz" type="hidden" name="_token">'),
                    '/logout' => new Response(302, ['Location' => 'http://localhost/login?bye=1', 'Set-Cookie' => 'sid=deleted; Max-Age=0'], ''),
                    default => new Response(200, [], 'page'),
                };
            }
        };

        $this->client = new TestClient($kernel, ['REMOTE_ADDR' => '203.0.113.9']);
    }

    private function last(): ServerRequestInterface
    {
        $request = $this->requests[count($this->requests) - 1] ?? null;
        self::assertInstanceOf(ServerRequestInterface::class, $request);

        return $request;
    }

    public function testQueryStringBecomesQueryParams(): void
    {
        $this->client->get('/search?q=a%20b&tags[]=x&tags[]=y&signature=abc');

        self::assertSame(['q' => 'a b', 'tags' => ['x', 'y'], 'signature' => 'abc'], $this->last()->getQueryParams());

        $this->client->get('/plain');
        self::assertSame([], $this->last()->getQueryParams());
    }

    public function testKeepsCookiesBetweenRequests(): void
    {
        $this->client->get('/form');
        self::assertSame(['sid' => 'a+b c', 'theme' => 'dark'], $this->client->cookies());

        $this->client->get('/next');
        self::assertSame(['sid' => 'a+b c', 'theme' => 'dark'], $this->last()->getCookieParams());
        self::assertSame('203.0.113.9', $this->last()->getServerParams()['REMOTE_ADDR'] ?? null);
    }

    public function testPostsFormsWithTheLastToken(): void
    {
        $this->client->get('/form');
        $this->client->post('/save', ['name' => 'Asha'], withToken: true);

        self::assertSame('POST', $this->last()->getMethod());
        self::assertSame(['name' => 'Asha', '_token' => 't&1'], $this->last()->getParsedBody());
        self::assertSame('application/x-www-form-urlencoded', $this->last()->getHeaderLine('Content-Type'));

        $this->client->get('/swapped');
        self::assertSame('xyz', $this->client->formToken());
    }

    public function testFollowsRedirectsAndDropsExpiredCookies(): void
    {
        $this->client->get('/form');
        $response = $this->client->post('/logout');
        $this->client->follow($response);

        self::assertSame('/login', $this->last()->getUri()->getPath());
        self::assertSame('bye=1', $this->last()->getUri()->getQuery());
        self::assertSame(['theme' => 'dark'], $this->client->cookies());

        $this->client->clearCookies();
        self::assertSame([], $this->client->cookies());
    }

    public function testErrors(): void
    {
        $this->client->get('/plain');

        try {
            $this->client->formToken();
            self::fail('Expected missing token error');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('no <input name="_token">', $e->getMessage());
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Expected a redirect, got status 200.');
        $this->client->follow($this->client->get('/plain'));
    }
}
