<?php

declare(strict_types=1);

namespace VeyroPHP\View;

use Closure;
use Stringable;
use Throwable;
use VeyroPHP\View\Exception\ViewException;

/**
 * Renders plain PHP templates.
 *
 * The view name "pages.about" maps to "<directory>/pages/about.php". Each data
 * key becomes a variable in the template. Two variables are always available:
 *
 * - $e($value): HTML-escapes a value. Use it for every value you output:
 *   <?= $e($title) ?>  (HtmlString values, such as slots, are printed as they are)
 * - $view: this renderer, for partials, layouts, sections, components and stacks:
 *
 *     <?php $view->layout('layouts.app', ['title' => 'Orders']) ?>   page inside a layout ($content there)
 *     <?php $view->section('sidebar') ?> ... <?php $view->endSection() ?>
 *     <?= $view->yield('sidebar') ?>                                     (in the layout)
 *     <?= $view->component('alert', ['type' => 'error', 'message' => $msg]) ?>   components/alert.php
 *     <?php $view->startComponent('card', ['title' => 'Orders']) ?> ... <?= $view->endComponent() ?>
 *     <?php $view->push('scripts') ?> ... <?php $view->endPush() ?>     and <?= $view->stack('scripts') ?>
 *     <?= $view->render('partials.nav', ['active' => 'home']) ?>
 *
 * Output is not escaped automatically. Templates are PHP code and must be trusted.
 */
final class View
{
    private const RESERVED = ['e', 'view', 'this'];

    /** PHP's own variables: extract() would skip them silently, so they are refused as data keys. */
    private const SUPERGLOBALS = ['GLOBALS', '_GET', '_POST', '_COOKIE', '_FILES', '_SERVER', '_ENV', '_REQUEST', '_SESSION'];

    /** Schemes url() lets through; relative URLs are always allowed. */
    private const URL_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    /**
     * Attributes whose value is a URL, so attr() passes it through url().
     *
     * @internal also the list TemplateLinter warns about
     */
    public const URL_ATTRIBUTES = ['href', 'src', 'action', 'formaction', 'poster', 'cite', 'background', 'xlink:href'];

    private const JSON_FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR;

    /** @var Closure(string|int|float|bool|Stringable|null): string */
    private readonly Closure $escape;

    /** @var array<string, mixed> */
    private array $shared = [];

    /** @var list<array{layout: array{string, array<string, mixed>}|null, sections: array<string, string>, open: list<string>, components: int}> */
    private array $frames = [];

    /** @var list<array{name: string, data: array<string, mixed>, slots: array<string, HtmlString>, openSlot: string|null}> */
    private array $components = [];

    /** @var array<string, list<string>> */
    private array $stacks = [];

    /** @var list<array{string, array<string, mixed>}> template path and data of the templates being rendered */
    private static array $rendering = [];

    public function __construct(private readonly string $directory)
    {
        $this->escape = static fn(string|int|float|bool|Stringable|null $value): string => $value instanceof HtmlString
            ? (string) $value
            : htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws ViewException when the name or data is invalid, the file is missing, or rendering throws
     */
    public function render(string $name, array $data = []): string
    {
        $outermost = $this->frames === [];

        try {
            return $this->renderFrame($name, $data, []);
        } finally {
            if ($outermost) {
                $this->stacks = [];
                $this->components = [];
            }
        }
    }

    /**
     * Makes a value available in every template, for helpers such as $t
     * (translations). Templates' own data cannot replace shared names.
     */
    public function share(string $name, mixed $value): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name) !== 1 || in_array($name, self::RESERVED, true)) {
            throw ViewException::invalidDataKey('(shared)', $name);
        }

        $this->shared[$name] = $value;
    }

    public function exists(string $name): bool
    {
        return is_file($this->path($name));
    }

    /**
     * HTML-escapes a value; the same function templates receive as $e.
     */
    public function escape(string|int|float|bool|Stringable|null $value): string
    {
        return ($this->escape)($value);
    }

    // Context-aware helpers: $e() is right for text and quoted attributes; these cover the rest.

    /**
     * A URL for href/src: escaped, and replaced by "#" unless it is relative or uses
     * http, https, mailto or tel. Blocks javascript:, data:, vbscript: and similar,
     * also when hidden with case, leading spaces or control characters, or tabs and
     * line breaks inside the scheme (browsers ignore those).
     *
     *     <a href="<?= $view->url($user->website) ?>">
     */
    public function url(string|Stringable|null $url): HtmlString
    {
        $value = (string) $url;
        $normalized = str_replace(["\t", "\n", "\r"], '', ltrim($value, "\x00..\x20"));

        if (preg_match('/^([A-Za-z][A-Za-z0-9+.-]*):/', $normalized, $match) === 1 && !in_array(strtolower($match[1]), self::URL_SCHEMES, true)) {
            return new HtmlString('#');
        }

        return new HtmlString($this->escape($value));
    }

    /**
     * JSON for inside a <script> block, such as JSON-LD or initial data for a script.
     * <, >, &, ' and " inside values are written as \u escapes, so the data cannot
     * close the script element. For an attribute value use attr() instead.
     *
     *     <script type="application/ld+json"><?= $view->json($schema) ?></script>
     *
     * @throws ViewException when the value cannot be encoded (for example NAN or invalid UTF-8)
     */
    public function json(mixed $value): HtmlString
    {
        try {
            return new HtmlString(json_encode($value, self::JSON_FLAGS));
        } catch (\JsonException $e) {
            throw new ViewException('json(): the value cannot be encoded as JSON: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * HTML attributes from an array, each with a leading space:
     *
     *     <div<?= $view->attr(['class' => $class, 'hidden' => !$open, 'data-config' => $config]) ?>>
     *
     * - true writes the name only, false and null leave the attribute out;
     * - arrays become escaped JSON; URL attributes (href, src, ...) go through url();
     * - names are checked, and event handlers (onclick, ...) are refused: use a script file.
     *
     * @param array<array-key, mixed> $attributes
     *
     * @throws ViewException for an invalid name, an event handler or a value that cannot be written
     */
    public function attr(array $attributes): HtmlString
    {
        $html = '';

        foreach ($attributes as $name => $value) {
            $name = (string) $name;

            if (preg_match('/^[A-Za-z_:][A-Za-z0-9_:.-]*$/D', $name) !== 1) {
                throw new ViewException(sprintf('Invalid attribute name "%s".', $this->escape($name)));
            }

            if (str_starts_with(strtolower($name), 'on')) {
                throw new ViewException(sprintf('Event handler attribute "%s" is not allowed: attach the handler in a script file.', $name));
            }

            if ($value === null || $value === false) {
                continue;
            }

            if ($value === true) {
                $html .= ' ' . $name;

                continue;
            }

            $text = match (true) {
                is_array($value), $value instanceof \JsonSerializable => $this->escape((string) $this->json($value)),
                is_string($value), is_int($value), is_float($value), $value instanceof Stringable => in_array(strtolower($name), self::URL_ATTRIBUTES, true)
                    ? (string) $this->url((string) $value)
                    : $this->escape($value),
                default => throw new ViewException(sprintf('Attribute "%s" has a value of type %s, which cannot be written.', $name, get_debug_type($value))),
            };

            $html .= ' ' . $name . '="' . $text . '"';
        }

        return new HtmlString($html);
    }

    // Layouts and sections

    /**
     * Renders the current template inside $layout; the layout receives the
     * template's output as $content (an HtmlString) plus $data.
     *
     * @param array<string, mixed> $data
     */
    public function layout(string $layout, array $data = []): void
    {
        $this->path($layout);
        $this->frames[$this->current()]['layout'] = [$layout, $data];
    }

    /**
     * Starts a section block, or sets it to escaped $text. A section that is
     * already set (by the page, before its layout runs) is not replaced.
     */
    public function section(string $name, ?string $text = null): void
    {
        self::assertName($name, 'section');
        $frame = $this->current();

        if ($text !== null) {
            $this->frames[$frame]['sections'][$name] ??= $this->escape($text);

            return;
        }

        $this->frames[$frame]['open'][] = 'section:' . $name;
        ob_start();
    }

    public function endSection(): void
    {
        $index = $this->current();
        $frame = $this->frames[$index];
        $open = array_pop($frame['open']);

        if ($open === null || !str_starts_with($open, 'section:')) {
            throw new ViewException('endSection() without a matching section().');
        }

        $content = (string) ob_get_clean();
        $frame['sections'][substr($open, 8)] ??= $content;
        $this->frames[$index] = $frame;
    }

    /**
     * A section's content, or the escaped $default.
     */
    public function yield(string $name, string $default = ''): HtmlString
    {
        return new HtmlString($this->frames[$this->current()]['sections'][$name] ?? $this->escape($default));
    }

    // Components

    /**
     * Renders "components/<name>.php" with $data and an empty $slot.
     *
     * @param array<string, mixed> $data
     */
    public function component(string $name, array $data = []): HtmlString
    {
        return new HtmlString($this->render('components.' . $name, $data + ['slot' => new HtmlString('')]));
    }

    /**
     * Starts a component whose body becomes $slot; finish with endComponent().
     *
     * @param array<string, mixed> $data
     */
    public function startComponent(string $name, array $data = []): void
    {
        $this->path('components.' . $name);
        $this->components[] = ['name' => $name, 'data' => $data, 'slots' => [], 'openSlot' => null];
        ob_start();
    }

    public function endComponent(): HtmlString
    {
        $component = array_pop($this->components) ?? throw new ViewException('endComponent() without a matching startComponent().');

        if ($component['openSlot'] !== null) {
            throw new ViewException(sprintf('Slot "%s" in component "%s" is not closed.', $component['openSlot'], $component['name']));
        }

        $slot = new HtmlString((string) ob_get_clean());

        return $this->component($component['name'], $component['slots'] + $component['data'] + ['slot' => $slot]);
    }

    /**
     * A named slot inside startComponent(): available in the component as $<name>.
     */
    public function slot(string $name): void
    {
        self::assertName($name, 'slot');
        $index = array_key_last($this->components) ?? throw new ViewException('slot() outside a component.');

        if ($this->components[$index]['openSlot'] !== null) {
            throw new ViewException('Slots cannot be nested.');
        }

        $this->components[$index]['openSlot'] = $name;
        ob_start();
    }

    public function endSlot(): void
    {
        $index = array_key_last($this->components);
        $name = $index === null ? null : $this->components[$index]['openSlot'];

        if ($index === null || $name === null) {
            throw new ViewException('endSlot() without a matching slot().');
        }

        $this->components[$index]['slots'][$name] = new HtmlString((string) ob_get_clean());
        $this->components[$index]['openSlot'] = null;
    }

    // Stacks

    /**
     * Adds the following output to a stack (for example page-specific script
     * tags), printed where the layout calls stack().
     */
    public function push(string $stack): void
    {
        self::assertName($stack, 'stack');
        $this->frames[$this->current()]['open'][] = 'push:' . $stack;
        ob_start();
    }

    public function endPush(): void
    {
        $index = $this->current();
        $frame = $this->frames[$index];
        $open = array_pop($frame['open']);

        if ($open === null || !str_starts_with($open, 'push:')) {
            throw new ViewException('endPush() without a matching push().');
        }

        $this->frames[$index] = $frame;
        $this->stacks[substr($open, 5)][] = (string) ob_get_clean();
    }

    public function stack(string $stack): HtmlString
    {
        return new HtmlString(implode('', $this->stacks[$stack] ?? []));
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $sections inherited from the page a layout wraps
     */
    private function renderFrame(string $name, array $data, array $sections): string
    {
        $this->frames[] = ['layout' => null, 'sections' => $sections, 'open' => [], 'components' => count($this->components)];

        try {
            $output = $this->renderFile($name, $data);
            $frame = $this->frames[$this->current()];

            if ($frame['open'] !== []) {
                throw new ViewException(sprintf('View "%s" did not close %s.', $name, implode(', ', $frame['open'])));
            }

            if (count($this->components) !== $frame['components']) {
                throw new ViewException(sprintf('View "%s" did not close a startComponent().', $name));
            }
        } finally {
            array_pop($this->frames);
        }

        if ($frame['layout'] === null) {
            return $output;
        }

        [$layout, $layoutData] = $frame['layout'];

        return $this->renderFrame($layout, ['content' => new HtmlString($output)] + $layoutData, $frame['sections']);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function renderFile(string $name, array $data): string
    {
        $path = $this->path($name);

        if (!is_file($path)) {
            throw ViewException::notFound($name, $path);
        }

        foreach (array_keys($data) as $key) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $key) !== 1 || in_array($key, self::RESERVED, true) || in_array($key, self::SUPERGLOBALS, true) || array_key_exists($key, $this->shared)) {
                throw ViewException::invalidDataKey($name, $key);
            }
        }

        $level = ob_get_level();
        ob_start();

        try {
            // The path and data are read from a stack, not passed as parameters, so the
            // template sees no variables of the renderer: only $e, $view and its data.
            self::$rendering[] = [$path, $data + $this->shared];

            try {
                (static function (Closure $e, View $view): void {
                    extract(self::$rendering[count(self::$rendering) - 1][1], EXTR_SKIP);
                    require self::$rendering[count(self::$rendering) - 1][0];
                })($this->escape, $this);
            } finally {
                array_pop(self::$rendering);
            }

            if (ob_get_level() !== $level + 1) {
                throw new ViewException(sprintf('View "%s" left an output block open (section, slot, push or component).', $name));
            }

            return (string) ob_get_clean();
        } catch (Throwable $exception) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }

            throw $exception instanceof ViewException ? $exception : ViewException::renderFailed($name, $exception);
        }
    }

    private function current(): int
    {
        return array_key_last($this->frames) ?? throw new ViewException('Layouts, sections and stacks can only be used while a view renders.');
    }

    private static function assertName(string $name, string $what): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name) !== 1 || in_array($name, [...self::RESERVED, 'slot', 'content'], true)) {
            throw new ViewException(sprintf('Invalid %s name "%s".', $what, $name));
        }
    }

    private function path(string $name): string
    {
        if (preg_match('/^[A-Za-z0-9_-]+(\.[A-Za-z0-9_-]+)*$/D', $name) !== 1) {
            throw ViewException::invalidName($name);
        }

        return rtrim($this->directory, '/\\') . DIRECTORY_SEPARATOR . str_replace('.', DIRECTORY_SEPARATOR, $name) . '.php';
    }
}
