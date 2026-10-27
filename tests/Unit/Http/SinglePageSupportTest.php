<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Unit\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use VeyroPHP\Container\Container;
use VeyroPHP\Http\HttpErrorResponder;
use VeyroPHP\Http\HttpKernel;
use VeyroPHP\Http\Middleware\ConditionalGet;
use VeyroPHP\Http\ResponseFactory;
use VeyroPHP\Http\Sitemap;
use VeyroPHP\Routing\HandlerInvoker;
use VeyroPHP\Routing\Router;
use VeyroPHP\Security\Honeypot;

/**
 * Priority C: honeypot, conditional GET and the sitemap helper.
 */
#[CoversClass(Honeypot::class)]
#[CoversClass(ConditionalGet::class)]
#[CoversClass(Sitemap::class)]
final class SinglePageSupportTest extends TestCase
{
    private HttpKernel $kernel;

    private int $handled = 0;

    protected function setUp(): void
    {
        $container = new Container();
        $psr17 = new Psr17Factory();
        $responses = new ResponseFactory($psr17, $psr17);
        $container->instance(ResponseFactory::class, $responses);

        $router = new Router();
        $router->post('/contact', function (): string {
            $this->handled++;

            return 'sent';
        })->middleware(Honeypot::field('website'));
        $router->get('/page', static fn(): string => '<h1>Page</h1>')->middleware(ConditionalGet::class);
        $router->get('/cached', static fn(): string => 'cached')->middleware(ConditionalGet::cache(300));
        $router->get('/public', static fn(): string => 'public')->middleware(ConditionalGet::cache(600, true));
        $router->get('/with-cookie', static fn(ResponseFactory $r): ResponseInterface => $r->html('x', 200, ['Set-Cookie' => 'a=b']))->middleware(ConditionalGet::cache(600, true));
        $router->get('/error', static fn(ResponseFactory $r): ResponseInterface => $r->html('missing', 404))->middleware(ConditionalGet::class);
        $router->get('/own-etag', static fn(ResponseFactory $r): ResponseInterface => $r->html('x', 200, ['ETag' => '"mine"']))->middleware(ConditionalGet::class);

        $this->kernel = new HttpKernel($router, $container, new HandlerInvoker($container, $responses), new HttpErrorResponder($responses), $responses);
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>|null $body
     */
    private function send(string $method, string $path, array $headers = [], ?array $body = null): ResponseInterface
    {
        $request = new ServerRequest($method, 'http://localhost' . $path, $headers);

        return $this->kernel->handle($body === null ? $request : $request->withParsedBody($body));
    }

    // Honeypot

    public function testAFilledHoneypotIsRejectedWithoutRunningTheHandler(): void
    {
        $response = $this->send('POST', '/contact', [], ['name' => 'Bot', 'website' => 'http://spam.example']);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(0, $this->handled);
    }

    public function testAnEmptyOrMissingHoneypotPasses(): void
    {
        self::assertSame('sent', (string) $this->send('POST', '/contact', [], ['name' => 'Asha', 'website' => ''])->getBody());
        self::assertSame('sent', (string) $this->send('POST', '/contact', [], ['name' => 'Asha'])->getBody());
        self::assertSame('sent', (string) $this->send('POST', '/contact', [], ['name' => 'Asha', 'website' => '   '])->getBody(), 'whitespace from autofill is empty');
        self::assertSame(3, $this->handled);
    }

    public function testHoneypotRejectsArraysAndBadFieldNames(): void
    {
        self::assertSame(400, $this->send('POST', '/contact', [], ['website' => ['x']])->getStatusCode());

        $this->expectException(\InvalidArgumentException::class);
        Honeypot::field('bad name');
    }

    // Conditional GET

    public function testEtagAndNotModified(): void
    {
        $first = $this->send('GET', '/page');
        $etag = $first->getHeaderLine('ETag');

        self::assertSame(200, $first->getStatusCode());
        self::assertMatchesRegularExpression('/^"[A-Za-z0-9_-]{22}"$/D', $etag);

        $again = $this->send('GET', '/page', ['If-None-Match' => $etag]);
        self::assertSame(304, $again->getStatusCode());
        self::assertSame('', (string) $again->getBody());
        self::assertSame($etag, $again->getHeaderLine('ETag'));

        self::assertSame(304, $this->send('GET', '/page', ['If-None-Match' => '"other", ' . $etag])->getStatusCode(), 'a list of tags');
        self::assertSame(304, $this->send('GET', '/page', ['If-None-Match' => 'W/' . $etag])->getStatusCode(), 'weak comparison');
        self::assertSame(304, $this->send('GET', '/page', ['If-None-Match' => '*'])->getStatusCode());
        self::assertSame(200, $this->send('GET', '/page', ['If-None-Match' => '"stale"'])->getStatusCode());
        self::assertSame(304, $this->send('HEAD', '/page', ['If-None-Match' => $etag])->getStatusCode());
    }

    public function testOnlySuccessfulResponsesGetAnEtagAndOwnEtagsAreKept(): void
    {
        self::assertSame('', $this->send('GET', '/error')->getHeaderLine('ETag'));
        self::assertSame('"mine"', $this->send('GET', '/own-etag')->getHeaderLine('ETag'));
        self::assertSame(304, $this->send('GET', '/own-etag', ['If-None-Match' => '"mine"'])->getStatusCode());
    }

    public function testCacheControl(): void
    {
        self::assertSame('', $this->send('GET', '/page')->getHeaderLine('Cache-Control'), 'nothing unless asked');
        self::assertSame('private, max-age=300', $this->send('GET', '/cached')->getHeaderLine('Cache-Control'));
        self::assertSame('public, max-age=600', $this->send('GET', '/public')->getHeaderLine('Cache-Control'));
        self::assertSame('private, no-cache', $this->send('GET', '/with-cookie')->getHeaderLine('Cache-Control'), 'a response that sets a cookie is never cached publicly');
    }

    // Sitemap

    public function testSitemapXmlIsEscaped(): void
    {
        $xml = (new Sitemap())
            ->add('https://example.com/')
            ->add('https://example.com/search?q=a&b=<c>', new \DateTimeImmutable('2026-10-26T10:00:00+05:30'), 'weekly', 0.8)
            ->toXml();

        $document = new \DOMDocument();
        self::assertTrue($document->loadXML($xml), 'well-formed XML');
        self::assertStringContainsString('<loc>https://example.com/search?q=a&amp;b=&lt;c&gt;</loc>', $xml);
        self::assertStringContainsString('<lastmod>2026-10-26T10:00:00+05:30</lastmod>', $xml);
        self::assertStringContainsString('<changefreq>weekly</changefreq>', $xml);
        self::assertStringContainsString('<priority>0.8</priority>', $xml);
        self::assertSame(2, $document->getElementsByTagName('url')->length);
    }

    public function testSitemapRejectsBadEntries(): void
    {
        foreach ([
            static fn(Sitemap $s) => $s->add('/relative'),
            static fn(Sitemap $s) => $s->add('javascript:alert(1)'),
            static fn(Sitemap $s) => $s->add('https://example.com/', null, 'sometimes'),
            static fn(Sitemap $s) => $s->add('https://example.com/', null, null, 1.5),
            static fn(Sitemap $s) => $s->add("https://example.com/\x01"),
        ] as $i => $add) {
            try {
                $add(new Sitemap());
                self::fail('entry ' . $i . ' should be rejected');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testSitemapLimit(): void
    {
        $sitemap = new Sitemap();
        for ($i = 0; $i < Sitemap::MAX_URLS; $i++) {
            $sitemap->add('https://example.com/p' . $i);
        }

        $this->expectException(\LengthException::class);
        $sitemap->add('https://example.com/one-too-many');
    }
}
