<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Unit\View;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use VeyroPHP\View\Exception\ViewException;
use VeyroPHP\View\HtmlString;
use VeyroPHP\View\View;

#[CoversClass(View::class)]
#[CoversClass(HtmlString::class)]
final class LayoutsAndComponentsTest extends TestCase
{
    private string $dir;

    private View $view;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'veyrophp-views-' . bin2hex(random_bytes(4));
        foreach (['layouts', 'components', 'pages'] as $sub) {
            mkdir($this->dir . '/' . $sub, 0777, true);
        }

        $files = [
            'layouts/app' => '<html><title><?= $view->yield("title", "Default & title") ?></title><?= $view->yield("sidebar") ?><main><?= $content ?></main><?= $view->stack("scripts") ?></html>',
            'layouts/admin' => '<?php $view->layout("layouts.app") ?><?php $view->section("sidebar") ?><nav>admin menu</nav><?php $view->endSection() ?><div class="admin"><?= $content ?></div>',
            'pages/home' => '<?php $view->layout("layouts.app") ?><?php $view->section("title", $title) ?><?php $view->section("sidebar") ?><nav>side</nav><?php $view->endSection() ?><p><?= $e($body) ?></p><?php $view->push("scripts") ?><script src="/home.js"></script><?php $view->endPush() ?>',
            'pages/plain' => '<p>plain</p>',
            'pages/users' => '<?php $view->layout("layouts.admin") ?><?php $view->section("title", "Users") ?><ul><li>Asha</li></ul>',
            'pages/cards' => '<?php $view->startComponent("card", ["title" => "Orders <3"]) ?><p>3 open</p><?php $view->slot("footer") ?><a href="/orders">All</a><?php $view->endSlot() ?><?= $view->endComponent() ?><?= $view->component("alert", ["type" => "error", "message" => "<b>Oops</b>"]) ?>',
            'pages/unclosed' => '<?php $view->section("x") ?>never closed',
            'pages/stray-end' => '<?php $view->endSection() ?>',
            'pages/throws' => '<?php $view->section("x") ?><?php throw new RuntimeException("boom") ?>',
            'pages/pusher' => '<?php $view->push("scripts") ?><script src="/one.js"></script><?php $view->endPush() ?>',
            'components/alert' => '<div class="alert <?= $e($type) ?>"><?= $e($message ?? "") ?><?= $slot ?></div><?php $view->push("scripts") ?><script src="/alert.js"></script><?php $view->endPush() ?>',
            'components/card' => '<section><h2><?= $e($title) ?></h2><?= $slot ?><footer><?= $footer ?? "" ?></footer></section>',
        ];

        foreach ($files as $name => $contents) {
            file_put_contents($this->dir . '/' . $name . '.php', $contents);
        }

        $this->view = new View($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (['layouts', 'components', 'pages'] as $sub) {
            foreach (glob($this->dir . '/' . $sub . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->dir . '/' . $sub);
        }
        rmdir($this->dir);
    }

    public function testPageInLayoutWithSectionsAndStack(): void
    {
        self::assertSame(
            '<html><title>Home &lt;1&gt;</title><nav>side</nav><main><p>a &amp; b</p></main><script src="/home.js"></script></html>',
            $this->view->render('pages.home', ['title' => 'Home <1>', 'body' => 'a & b']),
        );
    }

    public function testNestedLayoutsKeepThePagesSections(): void
    {
        self::assertSame(
            '<html><title>Users</title><nav>admin menu</nav><main><div class="admin"><ul><li>Asha</li></ul></div></main></html>',
            $this->view->render('pages.users'),
        );
    }

    public function testDefaultsAndPlainPages(): void
    {
        self::assertSame('<p>plain</p>', $this->view->render('pages.plain'));

        file_put_contents($this->dir . '/pages/bare.php', '<?php $view->layout("layouts.app") ?>x');
        self::assertSame('<html><title>Default &amp; title</title><main>x</main></html>', $this->view->render('pages.bare'));
    }

    public function testComponentsWithSlotsEscapeTheirData(): void
    {
        self::assertSame(
            '<section><h2>Orders &lt;3</h2><p>3 open</p><footer><a href="/orders">All</a></footer></section><div class="alert error">&lt;b&gt;Oops&lt;/b&gt;</div>',
            $this->view->render('pages.cards'),
        );
    }

    public function testStacksCollectFromComponentsAndResetBetweenRenders(): void
    {
        file_put_contents($this->dir . '/pages/both.php', '<?php $view->layout("layouts.app") ?><?= $view->component("alert", ["type" => "info"]) ?><?php $view->push("scripts") ?><script src="/page.js"></script><?php $view->endPush() ?>');

        $html = $this->view->render('pages.both');
        self::assertStringEndsWith('<script src="/alert.js"></script><script src="/page.js"></script></html>', $html);

        $this->view->render('pages.pusher');
        self::assertStringNotContainsString('/one.js', $this->view->render('pages.both'), 'a new top-level render starts with empty stacks');
    }

    public function testEscapeLeavesHtmlStringsAlone(): void
    {
        self::assertSame('<b>safe</b>', $this->view->escape(new HtmlString('<b>safe</b>')));
        self::assertSame('&lt;b&gt;', $this->view->escape('<b>'));
    }

    public function testMistakesGiveClearErrorsAndCleanBuffers(): void
    {
        $level = ob_get_level();

        foreach ([
            ['pages.unclosed', 'left an output block open'],
            ['pages.stray-end', 'endSection() without a matching section()'],
            ['pages.throws', 'boom'],
        ] as [$name, $message]) {
            try {
                $this->view->render($name);
                self::fail('expected ViewException for ' . $name);
            } catch (ViewException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }

            self::assertSame($level, ob_get_level(), 'no output buffer left open after ' . $name);
        }

        foreach ([
            fn() => $this->view->endComponent(),
            fn() => $this->view->slot('footer'),
            fn() => $this->view->endSlot(),
            fn() => $this->view->section('x'),
            fn() => $this->view->component('missing'),
            fn() => $this->view->startComponent('../evil'),
        ] as $i => $call) {
            try {
                $call();
                self::fail('expected ViewException ' . $i);
            } catch (ViewException) {
                $this->addToAssertionCount(1);
            }
        }

        file_put_contents($this->dir . '/pages/bad-name.php', '<?php $view->section("content") ?>x<?php $view->endSection() ?>');
        $this->expectException(ViewException::class);
        $this->expectExceptionMessage('Invalid section name "content"');
        $this->view->render('pages.bad-name');
    }
}
