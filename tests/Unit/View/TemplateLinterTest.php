<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Unit\View;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VeyroPHP\View\TemplateLinter;
use VeyroPHP\View\View;

#[CoversClass(TemplateLinter::class)]
final class TemplateLinterTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function safe(): iterable
    {
        yield 'escaped' => ['<p><?= $e($name) ?></p>'];
        yield 'escaped with nested calls' => ['<p><?= $e(strtoupper($user->name())) ?></p>'];
        yield 'partial' => ["<?= \$view->render('partials.nav', ['a' => \$a]) ?>"];
        yield 'component' => ["<?= \$view->component('alert', ['message' => \$m]) ?>"];
        yield 'end component' => ['<?= $view->endComponent() ?>'];
        yield 'yield and stack' => ["<?= \$view->yield('title') ?><?= \$view->stack('scripts') ?>"];
        yield 'url helper' => ['<a href="<?= $view->url($site) ?>">x</a>'];
        yield 'json helper' => ['<script type="application/ld+json"><?= $view->json($schema) ?></script>'];
        yield 'attr helper' => ['<div<?= $view->attr([\'class\' => $c]) ?>></div>'];
        yield 'layout content' => ['<main><?= $content ?></main>'];
        yield 'component slot' => ['<div><?= $slot ?></div>'];
        yield 'literal' => ["<?= 'checked' ?>"];
        yield 'number' => ['<?= 42 ?>'];
        yield 'ternary with literal branches' => ["<input <?= in_array(\$r, \$roles, true) ? 'checked' : '' ?>>"];
        yield 'int cast' => ['<?= (int) $count ?>'];
        yield 'float cast' => ['<?= (float) $price ?>'];
        yield 'code without output' => ['<?php if ($a): ?><b>x</b><?php endif ?>'];
        yield 'echo of escaped value' => ['<?php echo $e($a); ?>'];
        yield 'ignored on purpose' => ['<?= $trustedHtml /* view:lint-ignore */ ?>'];
    }

    #[DataProvider('safe')]
    public function testSafeOutputHasNoErrors(string $template): void
    {
        self::assertSame([], (new TemplateLinter())->errors($template));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unsafe(): iterable
    {
        yield 'raw variable' => ['<p><?= $name ?></p>', '$name'];
        yield 'raw property' => ['<p><?= $user->name ?></p>', '$user->name'];
        yield 'raw function' => ['<p><?= strtoupper($name) ?></p>', 'strtoupper($name)'];
        yield 'url helper object unescaped' => ['<a href="<?= $urls->route(\'home\') ?>">', '$urls->route(\'home\')'];
        yield 'concatenation with a variable' => ["<?= 'Hello ' . \$name ?>", "'Hello ' . \$name"];
        yield 'escaped then concatenated' => ['<?= $e($a) . $b ?>', '$e($a) . $b'];
        yield 'ternary with a variable branch' => ["<?= \$ok ? \$name : '' ?>", "\$ok ? \$name : ''"];
        yield 'null coalescing' => ["<?= \$name ?? 'guest' ?>", "\$name ?? 'guest'"];
        yield 'echo statement' => ['<?php echo $name; ?>', '$name'];
        yield 'print statement' => ['<?php print $name; ?>', '$name'];
        yield 'second echo argument' => ['<?php echo $e($a), $b; ?>', '$b'];
        yield 'view method that is not an output helper' => ['<?= $view->escape($a) . $b ?>', '$view->escape($a) . $b'];
        yield 'other variable called slot-like' => ['<?= $slots ?>', '$slots'];
    }

    #[DataProvider('unsafe')]
    public function testUnescapedOutputIsReported(string $template, string $expression): void
    {
        $errors = (new TemplateLinter())->errors($template);

        self::assertCount(1, $errors, json_encode($errors) ?: '');
        self::assertSame($expression, $errors[0]['expression']);
        self::assertSame(1, $errors[0]['line']);
    }

    public function testLineNumbers(): void
    {
        $errors = (new TemplateLinter())->errors("<p>\n<?= \$e(\$a) ?>\n</p>\n<p><?= \$b ?></p>");

        self::assertSame([['line' => 4, 'expression' => '$b']], $errors);
    }

    public function testEscapedValueInsideAUrlAttributeIsAWarning(): void
    {
        $linter = new TemplateLinter();
        $template = '<a href="<?= $e($site) ?>">x</a><img src = \'<?= $e($src) ?>\'><p title="<?= $e($t) ?>"></p>';

        self::assertSame([], $linter->errors($template));
        self::assertSame([
            ['line' => 1, 'expression' => '$e($site)'],
            ['line' => 1, 'expression' => '$e($src)'],
        ], $linter->warnings($template));
    }

    /**
     * The linter warns for exactly the attributes that $view->attr() treats as URLs
     * (one list, audit HTTP-11), whatever their case.
     */
    public function testTheLinterAndTheViewShareOneListOfUrlAttributes(): void
    {
        $linter = new TemplateLinter();

        foreach (View::URL_ATTRIBUTES as $attribute) {
            self::assertCount(1, $linter->warnings('<x ' . strtoupper($attribute) . '="<?= $e($u) ?>">'), $attribute);
        }

        self::assertSame([], $linter->warnings('<x title="<?= $e($u) ?>">'), 'not a URL attribute');
    }
}
