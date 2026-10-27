<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Unit\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VeyroPHP\Http\CallableHandler;
use VeyroPHP\Http\Exception\BadRequestHttpException;
use VeyroPHP\Http\Exception\EmitterException;
use VeyroPHP\Http\Exception\HttpException;
use VeyroPHP\Http\Middleware\JsonBodyParser;
use VeyroPHP\Http\ResponseEmitter;
use VeyroPHP\Http\ResponseFactory;

#[CoversClass(ResponseEmitter::class)]
#[CoversClass(ResponseFactory::class)]
#[CoversClass(JsonBodyParser::class)]
#[CoversClass(HttpException::class)]
#[CoversClass(BadRequestHttpException::class)]
#[CoversClass(EmitterException::class)]
final class HttpComponentsTest extends TestCase
{
    // ResponseEmitter

    /**
     * @return array{list<array{string, bool, int}>, string}
     */
    private static function emit(ResponseInterface $response, int $chunkSize = 8192): array
    {
        $headers = [];
        $output = '';

        (new ResponseEmitter(
            static function (string $line, bool $replace, int $status) use (&$headers): void {
                $headers[] = [$line, $replace, $status];
            },
            static fn(): ?array => null,
            static function (string $chunk) use (&$output): void {
                $output .= $chunk;
            },
            $chunkSize,
        ))->emit($response);

        return [$headers, $output];
    }

    public function testEmitsStatusLineHeadersAndBody(): void
    {
        $response = (new Response(201, ['Content-Type' => 'text/plain'], 'created'))
            ->withAddedHeader('Set-Cookie', 'a=1')
            ->withAddedHeader('Set-Cookie', 'b=2');

        [$headers, $output] = self::emit($response);

        self::assertSame([
            ['HTTP/1.1 201 Created', true, 201],
            ['Content-Type: text/plain', true, 201],
            ['Set-Cookie: a=1', true, 201],
            ['Set-Cookie: b=2', false, 201],
        ], $headers);
        self::assertSame('created', $output);
    }

    public function testStreamsBodyInChunksFromStart(): void
    {
        $response = new Response(200, [], str_repeat('x', 25));
        $response->getBody()->read(10);

        [, $output] = self::emit($response, 4);

        self::assertSame(str_repeat('x', 25), $output);
    }

    public function testNoBodyFor204And304(): void
    {
        foreach ([204, 304] as $status) {
            [, $output] = self::emit(new Response($status, [], 'ignored'));
            self::assertSame('', $output, (string) $status);
        }
    }

    public function testStatusLineWithoutReasonPhrase(): void
    {
        [$headers] = self::emit(new Response(299));

        self::assertSame('HTTP/1.1 299', $headers[0][0]);
    }

    public function testFailsWhenHeadersAlreadySent(): void
    {
        $this->expectException(EmitterException::class);
        $this->expectExceptionMessage('output started at index.php:3');

        (new ResponseEmitter(static function (): void {}, static fn(): array => ['index.php', 3]))->emit(new Response());
    }

    public function testRemovesPhpVersionHeaderUnlessTheResponseSetsIt(): void
    {
        $removed = [];
        $emitter = new ResponseEmitter(
            static function (): void {},
            static fn(): ?array => null,
            static function (): void {},
            8192,
            static function (string $name) use (&$removed): void {
                $removed[] = $name;
            },
        );

        $emitter->emit(new Response());
        self::assertSame(['X-Powered-By'], $removed);

        $removed = [];
        $emitter->emit(new Response(200, ['X-Powered-By' => 'my-app']));
        self::assertSame([], $removed);
    }

    // ResponseFactory

    public function testResponseFactoryHelpers(): void
    {
        $psr17 = new Psr17Factory();
        $factory = new ResponseFactory($psr17, $psr17);

        $html = $factory->html('<p>x</p>', 202, ['X-A' => 'b']);
        self::assertSame(202, $html->getStatusCode());
        self::assertSame('text/html; charset=utf-8', $html->getHeaderLine('Content-Type'));
        self::assertSame('b', $html->getHeaderLine('X-A'));

        self::assertSame('text/plain; charset=utf-8', $factory->text('x')->getHeaderLine('Content-Type'));

        $json = $factory->json(['path' => '/a/b', 'name' => 'café', 'n' => 1.0]);
        self::assertSame('{"path":"/a/b","name":"café","n":1.0}', (string) $json->getBody());

        self::assertSame(204, $factory->empty()->getStatusCode());
        self::assertSame('', (string) $factory->withoutBody($html)->getBody());
    }

    public function testCallerHeadersOverrideTheDefaultContentType(): void
    {
        $psr17 = new Psr17Factory();
        $factory = new ResponseFactory($psr17, $psr17);

        self::assertSame('application/xml; charset=UTF-8', $factory->html('<urlset/>', 200, ['Content-Type' => 'application/xml; charset=UTF-8'])->getHeaderLine('Content-Type'));
        self::assertSame('text/csv', $factory->text('a,b', 200, ['content-type' => 'text/csv'])->getHeaderLine('Content-Type'));
        self::assertSame('application/problem+json', $factory->json([], 400, ['Content-Type' => 'application/problem+json'])->getHeaderLine('Content-Type'));
        self::assertSame('text/html; charset=utf-8', $factory->html('<p>')->getHeaderLine('Content-Type'), 'The default stays when none is given.');
    }

    public function testJsonEncodingFailureThrows(): void
    {
        $psr17 = new Psr17Factory();

        $this->expectException(\JsonException::class);

        (new ResponseFactory($psr17, $psr17))->json(['bad' => "\xB1\x31"]);
    }

    // JsonBodyParser

    private static function parse(ServerRequestInterface $request): mixed
    {
        $captured = null;

        (new JsonBodyParser())->process($request, new CallableHandler(static function (ServerRequestInterface $r) use (&$captured): ResponseInterface {
            $captured = $r->getParsedBody();

            return new Response();
        }));

        return $captured;
    }

    private static function jsonRequest(string $body, string $contentType = 'application/json'): ServerRequestInterface
    {
        return new ServerRequest('POST', '/', ['Content-Type' => $contentType], $body);
    }

    public function testParsesJsonObjectAndArray(): void
    {
        self::assertSame(['name' => 'Ravi', 'tags' => ['a']], self::parse(self::jsonRequest('{"name":"Ravi","tags":["a"]}')));
        self::assertSame([1, 2], self::parse(self::jsonRequest('[1,2]', 'application/vnd.api+json; charset=utf-8')));
    }

    public function testIgnoresOtherContentTypesAndEmptyBodies(): void
    {
        self::assertNull(self::parse(self::jsonRequest('{"a":1}', 'text/plain')));
        self::assertNull(self::parse(self::jsonRequest('   ')));
    }

    public function testRejectsMalformedJson(): void
    {
        try {
            self::parse(self::jsonRequest('{"a":'));
            self::fail('Expected BadRequestHttpException.');
        } catch (BadRequestHttpException $e) {
            self::assertSame(400, $e->getStatusCode());
            self::assertSame('Malformed JSON request body.', $e->getMessage());
        }
    }

    public function testRejectsScalarJson(): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('must be an object or an array');

        self::parse(self::jsonRequest('"just a string"'));
    }

    public function testRejectsTooDeepJson(): void
    {
        $this->expectException(BadRequestHttpException::class);

        $deep = str_repeat('[', 70) . str_repeat(']', 70);
        self::parse(self::jsonRequest($deep));
    }

    public function testLargeIntegersStayStrings(): void
    {
        self::assertSame(['id' => '123456789012345678901234567890'], self::parse(self::jsonRequest('{"id":123456789012345678901234567890}')));
    }

    // HttpException

    public function testHttpExceptionDefaultsAndValidation(): void
    {
        self::assertSame('Not Found', (new HttpException(404))->getMessage());
        self::assertSame('Client Error', (new HttpException(499))->getMessage());
        self::assertSame('Server Error', (new HttpException(599))->getMessage());

        $this->expectException(\InvalidArgumentException::class);
        new HttpException(302);
    }
}
