<?php

declare(strict_types=1);

namespace VeyroPHP\Port\Convert;

use VeyroPHP\Port\Tokens;

/**
 * Converts a Blade template into a VeyroPHP (plain PHP) template.
 *
 * {{ }} always escapes through $e(); directives become PHP alternative syntax
 * or $view calls; anonymous components (<x-name>) become $view->component().
 * Unknown "@words" are left alone, as Blade does (CSS @media stays CSS).
 * Laravel helpers in expressions: __()/trans() → $t(), trans_choice() → $tc();
 * the others are listed as TODO(port).
 *
 * Authorization directives (@can) fail closed: the guarded markup is hidden
 * until the permission check is ported.
 */
final class BladeConverter
{
    private const HELPERS_TODO = ['str', 'optional', 'blank', 'filled', 'data_get', 'value', 'old', 'auth', 'csrf_token', 'csrf_field', 'config', 'session', 'request', 'mix', 'vite', 'app', 'now', 'today', 'action', 'secure_url', 'method_field', 'storage_path', 'public_path', 'auth', 'cache', 'abort'];

    /** @var list<string> */
    private array $todos = [];

    /** @var list<array{string, string}> open blocks: [directive, extra] */
    private array $stack = [];

    private bool $usesLoop = false;

    private int $loops = 0;

    public function convert(string $source, string $template): ConvertedFile
    {
        $this->todos = [];
        $this->stack = [];
        $this->usesLoop = preg_match('/\$loop->/', $template) === 1;
        $this->loops = 0;

        // Literal parts first: @verbatim blocks, escaped @{{ and @@.
        $protected = [];
        $template = (string) preg_replace_callback('/@verbatim(.*?)@endverbatim/s', static function (array $m) use (&$protected): string {
            $protected[] = $m[1];

            return "\x01" . (count($protected) - 1) . "\x01";
        }, $template);
        $template = str_replace(['@{{', '@@'], ["\x02", "\x03"], $template);

        $template = (string) preg_replace_callback('/@php(?!\s*\()(.*?)@endphp/s', fn(array $m): string => '<?php' . $this->expression($m[1]) . '?>', $template);
        $template = (string) preg_replace_callback('/\{\{--(.*?)--\}\}/s', static fn(array $m): string => '<?php /*' . str_replace('*/', '* /', $m[1]) . '*/ ?>', $template);
        $template = (string) preg_replace_callback('/\{!!\s*(.+?)\s*!!\}/s', fn(array $m): string => '<?= ' . $this->expression($m[1]) . ' ?>', $template);
        $template = (string) preg_replace_callback('/\{\{\s*(.+?)\s*\}\}/s', fn(array $m): string => '<?= $e(' . $this->expression($m[1]) . ') ?>', $template);
        $template = $this->components($template);
        $template = $this->directives($template);

        foreach ($this->unclosed() as $open) {
            $this->todos[] = "@{$open} is not closed";
        }

        $template = str_replace(["\x02", "\x03"], ['{{', '@'], $template);
        $template = (string) preg_replace_callback("/\x01(\\d+)\x01/", static fn(array $m): string => $protected[(int) $m[1]], $template);

        if ($this->usesLoop) {
            $this->todos[] = '$loop is emulated in @foreach (index, iteration, first, last, count, remaining, even, odd); $loop->parent and $loop->depth are not';
        }

        if (preg_match('/\$errors->/', $template) === 1) {
            $this->todos[] = '$errors (Laravel\'s error bag) does not exist: pass validation errors to the view';
        }

        if (preg_match('/\$attributes\b/', $template) === 1) {
            $this->todos[] = '$attributes (component attribute bag) does not exist: pass the attributes as data';
        }

        $todos = array_values(array_unique($this->todos));
        $header = '<?php /* ' . Php::comment(ConvertedFile::MARKER . " from {$source}. Review every TODO(port).") . " */ ?>\n";

        foreach ($todos as $todo) {
            $header .= '<?php /* TODO(port): ' . Php::comment($todo) . " */ ?>\n";
        }

        $target = (string) preg_replace('/\.blade\.php$/D', '.php', $source);

        return new ConvertedFile('view', $source, $target, $header . $template, $todos);
    }

    /**
     * Rewrites Laravel helpers inside a PHP expression.
     */
    private function expression(string $expression): string
    {
        $expression = (string) preg_replace('/(?<![\w$>:\\\\])(?:__|trans|lang)\s*\(/', '$t(', $expression);
        $expression = (string) preg_replace('/(?<![\w$>:\\\\])trans_choice\s*\(/', '$tc(', $expression);
        $expression = (string) preg_replace('/(?<![\w$>:\\\\])app\(\)->getLocale\(\)/', '$locale()', $expression);
        $expression = (string) preg_replace('/(?<![\w$>:\\\\])e\s*\(/', '$e(', $expression);
        $expression = (string) preg_replace('/\$__env->yieldContent\(/', '(string) $view->yield(', $expression);

        if (preg_match('/\$__env->/', $expression) === 1) {
            $this->todos[] = '$__env (Blade\'s view factory) is used directly: rewrite with $view';
        }

        if (preg_match('/\\\\?App\\\\Models\\\\(\w+)::(\w+)\(/', $expression, $model) === 1) {
            $this->todos[] = "the view calls {$model[1]}::{$model[2]}(): load the data in the handler (or a service) and pass it to the view";
        }

        if (preg_match('/(?<![\w\\\\])(Str|Arr)::/', $expression, $support) === 1) {
            $this->todos[] = "{$support[1]}:: is Laravel's support class: composer require illuminate/support and import it, or use plain PHP";
        }

        // URL helpers shared by VeyroPHP\View\ShareUrlHelpers (paths, not absolute URLs).
        if (preg_match('/(?<![\w$>:\\\\])(route|url|asset)\s*\(/', $expression) === 1) {
            $this->todos[] = 'route()/url()/asset() became $urls->route()/to(): they return paths with the base path, not absolute URLs (VeyroPHP\View\ShareUrlHelpers must be in the middleware)';
        }

        $expression = (string) preg_replace('/(?<![\w$>:\\\\])url\(\)->current\(\)/', '$urls->current()', $expression);
        $expression = (string) preg_replace('/(?<![\w$>:\\\\])request\(\)->(is|routeIs)\(/', '$urls->$1(', $expression);
        $expression = (string) preg_replace('/(?<![\w$>:\\\\])request\(\)->url\(\)/', '$urls->current()', $expression);
        $expression = (string) preg_replace('/(?<![\w$>:\\\\])route\s*\(/', '$urls->route(', $expression);
        $expression = (string) preg_replace('/(?<![\w$>:\\\\])(?:url|asset|secure_url|secure_asset)\s*\(/', '$urls->to(', $expression);

        foreach (Tokens::functionCalls(token_get_all('<?php ' . $expression . ';')) as $function => $count) {
            if (in_array($function, self::HELPERS_TODO, true)) {
                $this->todos[] = "{$function}() is a Laravel helper: pass the value from the handler or use the VeyroPHP service";
            }
        }

        return $expression;
    }

    private function directives(string $template): string
    {
        $out = '';
        $length = strlen($template);
        $i = 0;

        while ($i < $length) {
            $at = strpos($template, '@', $i);

            if ($at === false) {
                $out .= substr($template, $i);

                break;
            }

            $out .= substr($template, $i, $at - $i);

            if ($at > 0 && preg_match('/[\w.]/', $template[$at - 1]) === 1 || preg_match('/\G@(\w+)/', $template, $m, 0, $at) !== 1) {
                $out .= '@';
                $i = $at + 1;

                continue;
            }

            $name = $m[1];
            $next = $at + 1 + strlen($name);
            $args = null;
            $peek = $next;

            while ($peek < $length && ($template[$peek] === ' ' || $template[$peek] === "\t")) {
                $peek++;
            }

            if ($peek < $length && $template[$peek] === '(') {
                $close = Php::matchingParen($template, $peek);

                if ($close !== null) {
                    $args = substr($template, $peek + 1, $close - $peek - 1);
                    $next = $close + 1;
                }
            }

            $replacement = $this->directive(strtolower($name), $args);

            if ($replacement === null) {
                $out .= '@' . $name; // not a Blade directive (CSS at-rule, text)
                $i = $at + 1 + strlen($name);

                continue;
            }

            $out .= $replacement;
            $i = $next;
        }

        return $out;
    }

    /**
     * @return list<string> directives still open after the template
     */
    private function unclosed(): array
    {
        return array_column($this->stack, 0);
    }

    private function directive(string $name, ?string $args): ?string
    {
        $a = $args === null ? null : $this->expression($args);
        $parts = $a === null ? [] : Php::splitArgs($a);

        switch ($name) {
            case 'if':
                $this->stack[] = ['if', ''];

                return "<?php if ({$a}): ?>";
            case 'elseif':
                return "<?php elseif ({$a}): ?>";
            case 'else':
                return '<?php else: ?>';
            case 'endif':
            case 'endunless':
            case 'endisset':
            case 'endauth':
            case 'endguest':
            case 'endcan':
            case 'endcannot':
            case 'enderror':
                $this->pop($name);

                return '<?php endif ?>';
            case 'unless':
                $this->stack[] = ['unless', ''];

                return "<?php if (!({$a})): ?>";
            case 'isset':
                $this->stack[] = ['isset', ''];

                return "<?php if (isset({$a})): ?>";
            case 'empty':
                if ($a === null && end($this->stack) !== false && end($this->stack)[0] === 'forelse') {
                    return '<?php endforeach; else: ?>';
                }

                $this->stack[] = ['empty', ''];

                return "<?php if (empty({$a})): ?>";
            case 'endempty':
                $this->pop($name);

                return '<?php endif ?>';
            case 'foreach':
                $this->stack[] = [$name, ''];

                return $this->usesLoop ? $this->loopForeach((string) $a) : "<?php foreach ({$a}): ?>";
            case 'for':
            case 'while':
                $this->stack[] = [$name, ''];

                return "<?php {$name} ({$a}): ?>";
            case 'endforeach':
            case 'endfor':
            case 'endwhile':
                $this->pop($name);

                return '<?php ' . $name . ' ?>';
            case 'forelse':
                $this->stack[] = ['forelse', ''];
                $list = trim((string) preg_replace('/\s+as\s+.*$/sD', '', (string) $a));

                return $this->usesLoop
                    ? "<?php if (!empty({$list})): ?>" . $this->loopForeach((string) $a)
                    : "<?php if (!empty({$list})): foreach ({$a}): ?>";
            case 'endforelse':
                $this->pop($name);

                return '<?php endif ?>';
            case 'break':
            case 'continue':
                return $a === null ? "<?php {$name}; ?>" : "<?php if ({$a}) {$name}; ?>";
            case 'php':
                if ($a !== null) {
                    return "<?php {$a}; ?>";
                }

                $this->stack[] = ['php', ''];

                return '<?php';
            case 'endphp':
                $this->pop($name);

                return '?>';
            case 'extends':
                return "<?php \$view->layout({$a}) ?>";
            case 'section':
                $section = $this->name($parts[0] ?? "''");

                if (count($parts) >= 2) {
                    return $section === 'CONTENT' ? '<?= $e(' . $parts[1] . ') ?>' : "<?php \$view->section({$section}, (string) {$parts[1]}) ?>";
                }

                // The main section: VeyroPHP passes everything outside sections to the layout as $content.
                $this->stack[] = ['section', $section];

                return $section === 'CONTENT' ? '' : "<?php \$view->section({$section}) ?>";
            case 'endsection':
            case 'stop':
                return $this->pop('endsection') === 'CONTENT' ? '' : '<?php $view->endSection() ?>';
            case 'show':
                $section = $this->pop('endsection');

                return $section === 'CONTENT' ? '' : '<?php $view->endSection() ?><?= $view->yield(' . $section . ') ?>';
            case 'yield':
                $section = $this->name($parts[0] ?? "''");

                if ($section === 'CONTENT') {
                    return '<?= $content ?? \'\' ?>';
                }

                return count($parts) >= 2 ? "<?= \$view->yield({$section}, (string) {$parts[1]}) ?>" : "<?= \$view->yield({$section}) ?>";
            case 'include':
                $this->todos[] = '@include: an included view only receives the data passed to it, not the parent view\'s variables';

                return "<?= \$view->render({$a}) ?>";
            case 'push':
                $this->stack[] = ['push', ''];

                return '<?php $view->push(' . $this->name((string) $a) . ') ?>';
            case 'endpush':
                $this->pop($name);

                return '<?php $view->endPush() ?>';
            case 'stack':
                return '<?= $view->stack(' . $this->name((string) $a) . ') ?>';
            case 'component':
                $this->stack[] = ['component', ''];

                return "<?php \$view->startComponent({$a}) ?>";
            case 'endcomponent':
                $this->pop($name);

                return '<?= $view->endComponent() ?>';
            case 'slot':
                $this->stack[] = ['slot', ''];

                return '<?php $view->slot(' . $this->name((string) $a) . ') ?>';
            case 'endslot':
                $this->pop($name);

                return '<?php $view->endSlot() ?>';
            case 'props':
                return $this->props((string) $a);
            case 'csrf':
                $this->todos[] = '@csrf: pass $csrfToken to the view (the session CSRF token)';

                return '<input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">';
            case 'json':
                return '<?= json_encode(' . ($parts[0] ?? 'null') . ', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>';
            case 'lang':
                return "<?= \$e(\$t({$a})) ?>";
            case 'choice':
                return "<?= \$e(\$tc({$a})) ?>";
            case 'selected':
            case 'checked':
            case 'disabled':
            case 'readonly':
            case 'required':
                return "<?= ({$a}) ? '{$name}' : '' ?>";
            case 'auth':
            case 'guest':
                $this->stack[] = [$name, ''];
                $this->todos[] = "@{$name}: pass the logged-in user to the view as \$user (from Auth)";

                return $name === 'auth' ? '<?php if (isset($user)): ?>' : '<?php if (!isset($user)): ?>';
            case 'can':
            case 'cannot':
                $this->stack[] = [$name, ''];
                $this->todos[] = "@{$name}({$a}): permission check not ported; the block is hidden until you pass a flag from the handler (RBAC)";

                return '<?php if (false /* TODO(port): @' . $name . '(' . str_replace('*/', '* /', (string) $a) . ') */): ?>';
            case 'error':
                $this->stack[] = ['error', ''];
                $this->todos[] = '@error: pass validation errors to the view as $errors (field => list of messages)';
                $field = $parts[0] ?? "''";

                return "<?php if (isset(\$errors[{$field}])): \$message = \$errors[{$field}][0]; ?>";
            case 'method':
                $this->todos[] = "@method({$a}): method spoofing is not supported; use a real POST route";

                return '';
            case 'vite':
            case 'vitereactrefresh':
            case 'livewire':
            case 'livewirestyles':
            case 'livewirescripts':
            case 'livewirescriptconfig':
            case 'inertia':
            case 'inertiahead':
                $this->todos[] = "@{$name} has no VeyroPHP equivalent";

                return "<?php /* TODO(port): @{$name}" . ($a === null ? '' : '(' . str_replace('*/', '* /', $a) . ')') . ' */ ?>';
            case 'class':
            case 'style':
                // ['a', 'b' => $cond]: numeric keys always, string keys when the value is truthy.
                $separator = $name === 'class' ? ' ' : '; ';

                return $name . '="<?= $e(implode(' . var_export($separator, true) . ', array_keys(array_filter(array_combine(array_map(static fn($k, $v) => is_int($k) ? $v : $k, array_keys($__a = ' . $a . '), $__a), array_map(static fn($k, $v) => is_int($k) || $v, array_keys($__a), $__a)))))) ?>"';
            case 'env':
            case 'production':
            case 'once':
            case 'includeif':
            case 'includewhen':
            case 'includefirst':
            case 'each':
            case 'prepend':
            case 'switch':
            case 'dd':
            case 'dump':
                $this->todos[] = "@{$name} is not converted automatically";

                return "<?php /* TODO(port): @{$name}" . ($a === null ? '' : '(' . str_replace('*/', '* /', $a) . ')') . ' */ ?>';
            default:
                return null;
        }
    }

    /**
     * A section, stack or slot name as VeyroPHP accepts it: letters, digits
     * and "_", not a reserved name. "content" is the main output (CONTENT).
     * Names that are not literals are kept.
     */
    private function name(string $expression): string
    {
        $name = Php::string(trim($expression));

        if ($name === null) {
            return trim($expression);
        }

        if ($name === 'content') {
            return 'CONTENT';
        }

        $clean = (string) preg_replace('/[^A-Za-z0-9_]/', '_', $name);
        $clean = preg_match('/^[A-Za-z_]/', $clean) === 1 ? $clean : '_' . $clean;

        if (in_array($clean, ['e', 'view', 'this', 'slot'], true)) {
            $clean .= '_section';
        }

        if ($clean !== $name) {
            $this->todos[] = "name \"{$name}\" became \"{$clean}\" (VeyroPHP section, stack and slot names are identifiers)";
        }

        return var_export($clean, true);
    }

    /**
     * foreach that sets $loop like Blade does. Each loop has its own counters,
     * so nested loops do not disturb each other.
     */
    private function loopForeach(string $expression): string
    {
        if (preg_match('/^(.*?)\s+as\s+(.*)$/sD', $expression, $m) !== 1) {
            return "<?php foreach ({$expression}): ?>";
        }

        $n = ++$this->loops;
        $items = "\$__loop{$n}Items";
        $count = "\$__loop{$n}Count";
        $index = "\$__loop{$n}Index";

        return "<?php {$items} = {$m[1]}; {$count} = is_countable({$items}) ? count({$items}) : null; {$index} = 0; "
            . "foreach ({$items} as {$m[2]}): "
            . "\$loop = (object) ['index' => {$index}, 'iteration' => {$index} + 1, 'first' => {$index} === 0, 'last' => {$count} !== null && {$index} === {$count} - 1, "
            . "'count' => {$count}, 'remaining' => {$count} === null ? null : {$count} - {$index} - 1, 'even' => ({$index} + 1) % 2 === 0, 'odd' => ({$index} + 1) % 2 === 1]; {$index}++; ?>";
    }

    private function props(string $args): string
    {
        [$ok, $props] = Php::literal($args);

        if (!$ok || !is_array($props)) {
            $this->todos[] = '@props is not a literal array: set defaults with ??= by hand';

            return '';
        }

        $lines = [];

        foreach ($props as $key => $default) {
            if (is_string($key)) {
                $lines[] = '$' . $key . ' ??= ' . Php::export($default) . ';';
            }
        }

        return $lines === [] ? '' : '<?php ' . implode(' ', $lines) . ' ?>';
    }

    private function pop(string $closing): string
    {
        $top = array_pop($this->stack);

        if ($top === null) {
            $this->todos[] = "@{$closing} without a matching opening directive";

            return "''";
        }

        return $top[1] === '' ? "''" : $top[1];
    }

    /**
     * <x-alert type="error" :message="$m" /> and <x-card>...</x-card>.
     */
    private function components(string $template): string
    {
        $template = (string) preg_replace_callback('/<x-slot(?::([\w-]+)|\s+name=["\']([\w-]+)["\'])\s*>/', static fn(array $m): string => "<?php \$view->slot('" . ($m[1] !== '' ? $m[1] : $m[2]) . "') ?>", $template);
        $template = (string) preg_replace('/<\/x-slot(?::[\w-]+)?\s*>/', '<?php $view->endSlot() ?>', $template);

        $template = (string) preg_replace_callback('/<x-([\w.:-]+)((?:\s+[:\w.-]+(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'))?)*)\s*(\/?)>/', function (array $m): string {
            $name = $m[1];

            if ($name === 'dynamic-component' || str_contains($name, '::')) {
                $this->todos[] = "<x-{$name}> is not converted (dynamic or package component)";

                return $m[0];
            }

            $data = $this->attributes($m[2]);

            return $m[3] === '/'
                ? "<?= \$view->component('{$name}', {$data}) ?>"
                : "<?php \$view->startComponent('{$name}', {$data}) ?>";
        }, $template);

        return (string) preg_replace('/<\/x-[\w.:-]+\s*>/', '<?= $view->endComponent() ?>', $template);
    }

    private function attributes(string $text): string
    {
        preg_match_all('/(:?)([\w.-]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'))?/', $text, $matches, PREG_SET_ORDER);
        $items = [];

        foreach ($matches as $match) {
            $bound = $match[1] === ':';
            $key = lcfirst(str_replace(' ', '', ucwords(str_replace(['-', '.'], ' ', $match[2]))));
            $value = ($match[3] ?? '') !== '' ? $match[3] : ($match[4] ?? '');
            $hasValue = isset($match[3]) || isset($match[4]);

            if (!$hasValue) {
                $items[] = var_export($key, true) . ' => true';
            } elseif ($bound) {
                $items[] = var_export($key, true) . ' => ' . $this->expression($value);
            } elseif (str_contains($value, '<?= $e(')) {
                // an attribute that contained {{ }}: turn the echo back into an expression
                $items[] = var_export($key, true) . ' => ' . (string) preg_replace('/^<\?= \$e\((.*)\) \?>$/sD', '$1', $value);
            } else {
                $items[] = var_export($key, true) . ' => ' . var_export($value, true);
            }
        }

        return '[' . implode(', ', $items) . ']';
    }
}
