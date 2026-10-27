<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Unit\View;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use VeyroPHP\View\Exception\ViewException;
use VeyroPHP\View\View;

#[CoversClass(View::class)]
#[CoversClass(ViewException::class)]
final class ViewTest extends TestCase
{
    private string $dir;

    private View $view;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'veyrophp-view-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->dir . DIRECTORY_SEPARATOR . 'partials', 0777, true));
        $this->view = new View($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/{,partials/}*.php', GLOB_BRACE) ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir . DIRECTORY_SEPARATOR . 'partials');
        rmdir($this->dir);
    }

    private function template(string $name, string $contents): void
    {
        file_put_contents($this->dir . DIRECTORY_SEPARATOR . str_replace('.', DIRECTORY_SEPARATOR, $name) . '.php', $contents);
    }

    public function testRendersWithDataAndEscaping(): void
    {
        $this->template('hello', '<h1><?= $e($title) ?></h1><p><?= $count ?></p>');

        self::assertSame(
            '<h1>&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt; &amp; &apos;</h1><p>3</p>',
            $this->view->render('hello', ['title' => '<script>alert("x")</script> & \'', 'count' => 3]),
        );
    }

    public function testEscapeHandlesScalarsAndNull(): void
    {
        self::assertSame('', $this->view->escape(null));
        self::assertSame('1', $this->view->escape(true));
        self::assertSame('2.5', $this->view->escape(2.5));
        self::assertSame("\u{FFFD}", $this->view->escape("\xB1"));
    }

    public function testNestedViewNamesAndPartials(): void
    {
        $this->template('partials.nav', '<nav><?= $e($active) ?></nav>');
        $this->template('page', '<?= $view->render("partials.nav", ["active" => "home"]) ?><main>x</main>');

        self::assertSame('<nav>home</nav><main>x</main>', $this->view->render('page'));
        self::assertTrue($this->view->exists('partials.nav'));
        self::assertFalse($this->view->exists('partials.footer'));
    }

    public function testTemplateCannotSeeRendererInternals(): void
    {
        $this->template('scope', '<?= implode(",", array_keys(get_defined_vars())) ?>|<?= isset($this) ? "this" : "no-this" ?>');

        // Only $e, $view and the data: no renderer internals such as the template's path.
        self::assertSame('e,view,a|no-this', $this->view->render('scope', ['a' => 1]));
    }

    public function testDataKeysThatLookLikeInternalsAreKeptAndSuperglobalsAreRejected(): void
    {
        $this->template('page', '<?= $e($__data) ?>/<?= $e($__path) ?>');
        self::assertSame('d/p', $this->view->render('page', ['__data' => 'd', '__path' => 'p']), 'not silently dropped');

        foreach (['GLOBALS', '_GET', '_POST', '_SERVER', '_COOKIE', '_SESSION', '_FILES', '_ENV', '_REQUEST'] as $key) {
            try {
                $this->view->render('page', [$key => 'x']);
                self::fail($key . ' should be rejected');
            } catch (ViewException $e) {
                self::assertStringContainsString('Invalid data key', $e->getMessage());
            }
        }
    }

    // Context-aware helpers (VQ-018)

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function urls(): iterable
    {
        yield 'https' => ['https://example.com/a?b=1&c=2', 'https://example.com/a?b=1&amp;c=2'];
        yield 'http' => ['http://example.com', 'http://example.com'];
        yield 'mailto' => ['mailto:asha@example.com', 'mailto:asha@example.com'];
        yield 'tel' => ['tel:+911234567890', 'tel:+911234567890'];
        yield 'absolute path' => ['/orders/7?tab=items', '/orders/7?tab=items'];
        yield 'relative path' => ['page.html', 'page.html'];
        yield 'fragment' => ['#contact', '#contact'];
        yield 'query' => ['?page=2', '?page=2'];
        yield 'protocol-relative' => ['//cdn.example.com/x.css', '//cdn.example.com/x.css'];
        yield 'colon after a slash is a path' => ['/a:b', '/a:b'];
        yield 'quotes are escaped' => ['/a"onmouseover="x', '/a&quot;onmouseover=&quot;x'];
        yield 'entity stays text' => ['javascript&colon;alert(1)', 'javascript&amp;colon;alert(1)'];
        yield 'javascript' => ['javascript:alert(1)', '#'];
        yield 'mixed case' => ['JaVaScRiPt:alert(1)', '#'];
        yield 'leading space' => [' javascript:alert(1)', '#'];
        yield 'tab inside the scheme' => ["java\tscript:alert(1)", '#'];
        yield 'newline inside the scheme' => ["java\nscript:alert(1)", '#'];
        yield 'leading control character' => ["\x01javascript:alert(1)", '#'];
        yield 'data' => ['data:text/html,<script>alert(1)</script>', '#'];
        yield 'vbscript' => ['vbscript:msgbox(1)', '#'];
        yield 'unknown scheme' => ['ftp://example.com', '#'];
        yield 'empty' => ['', ''];
    }

    #[DataProvider('urls')]
    public function testUrlAllowsOnlySafeSchemes(string $url, string $expected): void
    {
        self::assertSame($expected, (string) $this->view->url($url));
    }

    public function testHelpersAreNotEscapedTwice(): void
    {
        $this->template('link', '<a href="<?= $view->url($site) ?>"><?= $e($view->url($site)) ?></a>');

        self::assertSame('<a href="/a?b=1&amp;c=2">/a?b=1&amp;c=2</a>', $this->view->render('link', ['site' => '/a?b=1&c=2']));
    }

    public function testJsonIsSafeInsideAScriptBlock(): void
    {
        $data = ['name' => '</script><script>alert("x")</script>', 'note' => "it's & \u{2028}"];
        $this->template('ld', '<script type="application/ld+json"><?= $view->json($data) ?></script>');

        $html = $this->view->render('ld', ['data' => $data]);
        $inner = substr($html, strlen('<script type="application/ld+json">'), -strlen('</script>'));

        foreach (['<', '>', '&', "'", "\u{2028}"] as $character) {
            self::assertStringNotContainsString($character, $inner, 'no raw ' . json_encode($character));
        }
        self::assertSame($data, json_decode($inner, true), 'still the same data');

        $this->expectException(ViewException::class);
        $this->view->json(NAN);
    }

    public function testAttr(): void
    {
        $html = (string) $this->view->attr([
            'class' => 'card "big"',
            'disabled' => true,
            'hidden' => false,
            'title' => null,
            'data-id' => 5,
            'href' => 'javascript:alert(1)',
            'src' => '/img/a.png?x=1&y=2',
            'data-config' => ['a' => 1, 'b' => '<b>'],
        ]);

        self::assertSame(' class="card &quot;big&quot;" disabled data-id="5" href="#" src="/img/a.png?x=1&amp;y=2" data-config="{&quot;a&quot;:1,&quot;b&quot;:&quot;\u003Cb\u003E&quot;}"', $html);

        foreach (['onclick', 'ONLOAD', 'on click', '"x', 'a=b', ''] as $name) {
            try {
                $this->view->attr([$name => 'x']);
                self::fail($name . ' should be rejected');
            } catch (ViewException $e) {
                self::assertStringContainsString('attribute', $e->getMessage());
            }
        }
    }

    public function testMissingView(): void
    {
        $this->expectException(ViewException::class);
        $this->expectExceptionMessage('View "missing" not found');

        $this->view->render('missing');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidNames(): iterable
    {
        yield 'traversal' => ['../secret'];
        yield 'slash' => ['partials/nav'];
        yield 'empty' => [''];
        yield 'double dot' => ['a..b'];
        yield 'leading dot' => ['.hidden'];
        yield 'null byte' => ["a\0b"];
    }

    #[DataProvider('invalidNames')]
    public function testRejectsInvalidNames(string $name): void
    {
        $this->expectException(ViewException::class);
        $this->expectExceptionMessage('Invalid view name');

        $this->view->render($name);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function reservedKeys(): iterable
    {
        yield 'e' => ['e'];
        yield 'view' => ['view'];
        yield 'this' => ['this'];
        yield 'invalid identifier' => ['my-key'];
        yield 'starts with digit' => ['1a'];
    }

    #[DataProvider('reservedKeys')]
    public function testRejectsReservedOrInvalidDataKeys(string $key): void
    {
        $this->template('page', 'x');

        $this->expectException(ViewException::class);
        $this->expectExceptionMessage('Invalid data key');

        $this->view->render('page', [$key => 'value']);
    }

    public function testExceptionInTemplateDiscardsPartialOutput(): void
    {
        $this->template('broken', "partial output<?php throw new RuntimeException('template failed'); ?>");
        $level = ob_get_level();

        try {
            $this->view->render('broken');
            self::fail('Expected ViewException.');
        } catch (ViewException $e) {
            self::assertInstanceOf(RuntimeException::class, $e->getPrevious());
            self::assertStringContainsString('template failed', $e->getMessage());
        }

        self::assertSame($level, ob_get_level());
    }

    public function testErrorInPartialIsNotWrappedTwice(): void
    {
        $this->template('partials.bad', "<?php throw new RuntimeException('inner'); ?>");
        $this->template('outer', '<?= $view->render("partials.bad") ?>');

        try {
            $this->view->render('outer');
            self::fail('Expected ViewException.');
        } catch (ViewException $e) {
            self::assertStringContainsString('Rendering view "partials.bad" failed', $e->getMessage());
        }
    }
}
