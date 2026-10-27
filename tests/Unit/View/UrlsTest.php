<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Unit\View;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use VeyroPHP\Routing\Router;
use VeyroPHP\View\Urls;

/**
 * Priority C: absolute URLs for canonical links and versioned asset URLs.
 */
#[CoversClass(Urls::class)]
final class UrlsTest extends TestCase
{
    private string $public;

    protected function setUp(): void
    {
        $this->public = sys_get_temp_dir() . '/veyrophp-urls-' . bin2hex(random_bytes(4));
        mkdir($this->public . '/css', 0777, true);
        file_put_contents($this->public . '/css/site.css', 'body{}');
        touch($this->public . '/css/site.css', 1_793_000_000);
    }

    protected function tearDown(): void
    {
        unlink($this->public . '/css/site.css');
        rmdir($this->public . '/css');
        rmdir($this->public);
    }

    private function urls(string $path = '/about', ?string $appUrl = 'https://example.com', string $base = ''): Urls
    {
        return new Urls(new Router(), $base, new ServerRequest('GET', 'http://attacker.example' . $base . $path), $appUrl, $this->public);
    }

    public function testAbsoluteUsesTheConfiguredOriginNeverTheHostHeader(): void
    {
        self::assertSame('https://example.com/about', $this->urls()->absolute());
        self::assertSame('https://example.com/contact', $this->urls()->absolute('/contact'));
        self::assertSame('https://example.com/shop/about', $this->urls('/about', 'https://example.com', '/shop')->absolute(), 'the base path is part of the current path');
        self::assertSame('https://example.com:8443/x', $this->urls('/x', 'https://example.com:8443/ignored-path')->absolute(), 'only the origin of app.url');
    }

    public function testAbsoluteCollapsesLeadingSlashes(): void
    {
        // A request for //evil.example/page must not become a protocol-relative URL.
        self::assertSame('https://example.com/evil.example/page', $this->urls('//evil.example/page')->absolute());
        self::assertSame('https://example.com/a', $this->urls()->absolute('///a'));
        self::assertSame('https://example.com/b', $this->urls()->absolute('b'));
    }

    public function testAbsoluteNeedsAnHttpAppUrl(): void
    {
        foreach ([null, '', 'ftp://example.com', 'example.com'] as $appUrl) {
            try {
                $this->urls('/x', $appUrl)->absolute();
                self::fail(var_export($appUrl, true) . ' should be refused');
            } catch (\LogicException $e) {
                self::assertStringContainsString('APP_URL', $e->getMessage());
            }
        }
    }

    public function testAssetAddsAVersionFromTheFile(): void
    {
        $urls = $this->urls('/x', null, '/shop');

        self::assertSame('/shop/css/site.css?v=' . base_convert('1793000000', 10, 36), $urls->asset('/css/site.css'));
        self::assertSame('/shop/css/site.css?v=' . base_convert('1793000000', 10, 36), $urls->asset('css/site.css'));

        touch($this->public . '/css/site.css', 1_793_000_100);
        clearstatcache();
        self::assertSame('/shop/css/site.css?v=' . base_convert('1793000100', 10, 36), $urls->asset('/css/site.css'), 'a changed file gets a new URL');

        self::assertSame('/shop/js/missing.js', $urls->asset('/js/missing.js'), 'a missing file has no version');
    }

    public function testAssetRejectsPathsOutsidePublic(): void
    {
        foreach (['../.env', '/css/../../secret', "css/site.css\0.php", 'css\\..\\..\\x'] as $path) {
            try {
                $this->urls()->asset($path);
                self::fail($path . ' should be refused');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testExistingConstructorStillWorks(): void
    {
        $urls = new Urls(new Router(), '/app');

        self::assertSame('/app/css/x.css', $urls->to('/css/x.css'));
        self::assertSame('/app/css/x.css', $urls->asset('/css/x.css'), 'without a public directory, no version');
    }
}
