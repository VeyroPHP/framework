<?php

declare(strict_types=1);

namespace VeyroPHP\Port\Convert;

/**
 * Converts a Laravel routes file (Route:: facade calls) into a VeyroPHP routes
 * file ($router calls): verbs, match/any, view and redirect routes, resource
 * routes (expanded), groups with prefix/middleware/name, name/where/middleware
 * modifiers. Anything else is kept as a TODO(port) comment.
 *
 * The code is tokenized, never executed.
 */
final class RouteConverter
{
    private const VERBS = ['get', 'post', 'put', 'patch', 'delete', 'options'];

    private const ALL_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];

    private const GROUP_ATTRIBUTES = ['prefix', 'middleware', 'name', 'as', 'group', 'controller', 'domain', 'withoutmiddleware', 'scopebindings'];

    private const MIDDLEWARE = [
        'auth' => '\VeyroPHP\Auth\Authenticate::class',
        'auth:web' => '\VeyroPHP\Auth\Authenticate::class',
        'auth:sanctum' => '\VeyroPHP\Auth\Tokens\AuthenticateToken::class',
        'verified' => '\VeyroPHP\Auth\Verification\EnsureEmailIsVerified::class',
        'signed' => '\VeyroPHP\Security\ValidateSignature::class',
    ];

    private const WHERE = [
        'wherenumber' => '\d+',
        'wherealpha' => '[a-zA-Z]+',
        'wherealphanumeric' => '[a-zA-Z0-9]+',
        'whereuuid' => '[\da-fA-F]{8}-[\da-fA-F]{4}-[\da-fA-F]{4}-[\da-fA-F]{4}-[\da-fA-F]{12}',
        'whereulid' => '[0-7][0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{25}',
    ];

    private const RESOURCE = [
        // action => [method, path suffix, uses the parameter]
        'index' => ['get', '', false],
        'create' => ['get', '/create', false],
        'store' => ['post', '', false],
        'show' => ['get', '', true],
        'edit' => ['get', '/edit', true],
        'update' => ['map', '', true],
        'destroy' => ['delete', '', true],
    ];

    /** @var list<string> */
    private array $todos = [];

    private bool $usesRouter = false;

    public function convert(string $source, string $code): ConvertedFile
    {
        $this->todos = [];
        $this->usesRouter = false;
        [$uses, $body] = $this->statements($code, '');

        if (basename($source) === 'api.php') {
            // Laravel loads routes/api.php under /api with the "api" middleware group.
            $this->usesRouter = true;
            $this->todos[] = 'routes/api.php was under /api with Laravel\'s "api" middleware group: add API middleware (e.g. AuthenticateToken) where needed';
            $body = ["\$router->group('/api', static function (Router \$router): void {", ...array_map(static fn(string $l): string => $l === '' ? '' : '    ' . $l, $body), '});'];
        }

        if ($this->usesRouter) {
            $uses[] = 'use VeyroPHP\Routing\Router;';
        }

        $uses = array_values(array_unique($uses));
        sort($uses);
        $target = 'routes/ported/' . basename($source);

        // Notes that are not already written above a route go into the header.
        $inline = implode("\n", $body);
        $header = [];

        foreach (array_unique($this->todos) as $todo) {
            $todo = Php::comment((string) preg_replace('/\s+/', ' ', $todo));

            if (!str_contains($inline, $todo)) {
                $header[] = '// TODO(port): ' . $todo;
            }
        }

        $content = implode("\n", [
            '<?php',
            '',
            'declare(strict_types=1);',
            '',
            '// ' . Php::comment(ConvertedFile::MARKER . " from {$source}. Review every TODO(port)."),
            '// ' . Php::comment("Not loaded yet: add '{$target}' to routeFiles in bootstrap/app.php when it is ready."),
            ...$header,
            '',
            ...($uses === [] ? [] : [...$uses, '']),
            '/** @var VeyroPHP\Routing\Router $router */',
            '',
            ...$body,
        ]);

        return new ConvertedFile('routes', $source, $target, rtrim($content) . "\n", $this->todos);
    }

    /**
     * Converts top-level statements.
     *
     * @return array{list<string>, list<string>} [use statements, body lines]
     */
    private function statements(string $code, string $indent): array
    {
        $uses = $lines = [];

        foreach ($this->split($code) as $statement) {
            $sig = $statement['sig'];
            $first = $sig[0] ?? null;

            if ($first === null) {
                continue;
            }

            if ($first[0] === T_USE) {
                if (!str_contains($statement['text'], 'Illuminate\\')) {
                    $use = trim((string) preg_replace('/\s+/', ' ', $statement['text']));
                    $uses[] = $use;

                    if (preg_match('/^use\s+(App\\\\(?!Http\\\\Controllers\\\\)[\w\\\\]+)/', $use, $class) === 1) {
                        $this->todos[] = "uses {$class[1]}: port that class too, or the routes will not load";
                    }
                }

                continue;
            }

            if ($first[0] === T_DECLARE || $first[0] === T_NAMESPACE) {
                continue;
            }

            if ($first[1] === 'Route' && ($sig[1][0] ?? null) === T_DOUBLE_COLON) {
                foreach ($this->route($statement['text'], $indent) as $line) {
                    $lines[] = $line;
                }

                $lines[] = '';

                continue;
            }

            $lines[] = $this->todoLine($indent, 'not a route definition, not converted', $statement['text']);
        }

        return [$uses, $lines];
    }

    /**
     * Splits PHP code (with or without an opening tag) into top-level statements.
     *
     * @return list<array{text: string, sig: list<array{int, string}>}>
     */
    private function split(string $code): array
    {
        $tokens = token_get_all(str_starts_with(ltrim($code), '<?php') ? $code : "<?php\n" . $code);
        $statements = [];
        $text = '';
        $sig = [];
        $depth = 0;

        foreach ($tokens as $index => $token) {
            [$id, $value] = is_array($token) ? [$token[0], $token[1]] : [0, $token];

            if (in_array($id, [T_OPEN_TAG, T_CLOSE_TAG, T_INLINE_HTML], true)) {
                continue;
            }

            if (in_array($id, [T_COMMENT, T_DOC_COMMENT], true) && $sig === []) {
                continue; // comments between statements are dropped
            }

            $text .= $value;

            if (!in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $sig[] = [$id, $value];
            }

            if (in_array($value, ['(', '[', '{'], true) || in_array($id, [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                $depth++;
            } elseif (in_array($value, [')', ']', '}'], true)) {
                $depth--;
            }

            if ($depth === 0 && ($value === ';' || $value === '}' && ($sig[0][0] ?? 0) !== T_USE && !self::continues($tokens, $index))) {
                $statements[] = ['text' => trim($text), 'sig' => $sig];
                $text = '';
                $sig = [];
            }
        }

        if (trim($text) !== '') {
            $statements[] = ['text' => trim($text), 'sig' => $sig];
        }

        return $statements;
    }

    /**
     * A closing brace at depth 0 ends a block statement unless the statement
     * continues (e.g. "}, ['middleware' => ...]);" is never at depth 0).
     *
     * The position, not the token, identifies the brace: every "}" token is the same
     * string, so searching for it found the file's first one (CORE-1).
     *
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function continues(array $tokens, int $index): bool
    {
        for ($i = $index + 1, $n = count($tokens); $i < $n; $i++) {
            $next = $tokens[$i];

            if (is_array($next) && in_array($next[0], [T_WHITESPACE, T_COMMENT], true)) {
                continue;
            }

            return is_array($next) ? in_array($next[0], [T_ELSE, T_ELSEIF, T_CATCH, T_FINALLY], true) : in_array($next, [')', ',', ';', '->'], true);
        }

        return false;
    }

    /**
     * @return list<array{string, list<string>}> [method, raw arguments] for each call of a "Route::a(...)->b(...)" chain
     */
    private function chain(string $statement): array
    {
        $tokens = token_get_all("<?php\n" . $statement);
        $calls = [];
        $i = 0;
        $n = count($tokens);

        // skip to the first method name after "Route::"
        while ($i < $n && !(is_array($tokens[$i]) && $tokens[$i][0] === T_DOUBLE_COLON)) {
            $i++;
        }

        $i++;

        while ($i < $n) {
            while ($i < $n && is_array($tokens[$i]) && $tokens[$i][0] === T_WHITESPACE) {
                $i++;
            }

            // Method names can be keywords for the tokenizer (match, list, ...).
            if (!isset($tokens[$i]) || !is_array($tokens[$i]) || preg_match('/^[A-Za-z_]\w*$/D', $tokens[$i][1]) !== 1) {
                break;
            }

            $name = $tokens[$i][1];
            $i++;

            while ($i < $n && is_array($tokens[$i]) && $tokens[$i][0] === T_WHITESPACE) {
                $i++;
            }

            if (($tokens[$i] ?? null) !== '(') {
                break;
            }

            $depth = 0;
            $args = '';

            for (; $i < $n; $i++) {
                $value = is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];

                if (in_array($value, ['(', '[', '{'], true) || is_array($tokens[$i]) && in_array($tokens[$i][0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                    $depth++;
                } elseif (in_array($value, [')', ']', '}'], true)) {
                    $depth--;
                }

                if ($depth === 0) {
                    $i++;

                    break;
                }

                if (!($depth === 1 && $value === '(' && $args === '')) {
                    $args .= $value;
                }
            }

            $calls[] = [$name, Php::splitArgs(substr($args, 0))];

            while ($i < $n && is_array($tokens[$i]) && $tokens[$i][0] === T_WHITESPACE) {
                $i++;
            }

            if (is_array($tokens[$i] ?? null) && in_array($tokens[$i][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
                $i++;

                continue;
            }

            break;
        }

        return $calls;
    }

    /**
     * @return list<string>
     */
    private function route(string $statement, string $indent): array
    {
        $calls = $this->chain($statement);
        $head = strtolower($calls[0][0] ?? '');

        if ($calls === []) {
            return [$this->todoLine($indent, 'could not read this route', $statement)];
        }

        if (in_array($head, self::GROUP_ATTRIBUTES, true)) {
            return $this->group($calls, $statement, $indent);
        }

        $before = count($this->todos);
        $lines = $this->definitions($head, $calls, $statement, $indent);

        // This route's notes also go into the file, right above it.
        $notes = array_map(static fn(string $todo): string => $indent . '// TODO(port): ' . Php::comment((string) preg_replace('/\s+/', ' ', $todo)), array_slice($this->todos, $before));

        return str_starts_with($lines[0] ?? '', $indent . '// TODO(port)') ? $lines : [...$notes, ...$lines];
    }

    /**
     * @param list<array{string, list<string>}> $calls
     * @return list<string>
     */
    private function definitions(string $head, array $calls, string $statement, string $indent): array
    {
        $definitions = match (true) {
            in_array($head, self::VERBS, true) => $this->verb($head, $calls[0][1]),
            $head === 'match' => $this->match($calls[0][1]),
            $head === 'any' => $this->map(self::ALL_METHODS, $calls[0][1]),
            $head === 'view' => $this->view($calls[0][1]),
            $head === 'redirect', $head === 'permanentredirect' => $this->redirect($head, $calls[0][1]),
            $head === 'resource', $head === 'apiresource' => $this->resource($head, $calls),
            default => null,
        };

        if ($definitions === null) {
            return [$this->todoLine($indent, "Route::{$calls[0][0]}() has no VeyroPHP equivalent", $statement)];
        }

        $modifiers = in_array($head, ['resource', 'apiresource'], true) ? '' : $this->modifiers(array_slice($calls, 1));

        return array_map(static fn(string $definition): string => $indent . $definition . $modifiers . ';', $definitions);
    }

    /**
     * @param list<string> $args
     * @return list<string>|null
     */
    private function verb(string $method, array $args): ?array
    {
        if (count($args) < 2) {
            return null;
        }

        return ["\$router->{$method}(" . $this->path($args[0]) . ', ' . $this->handler($args[1]) . ')'];
    }

    /**
     * @param list<string> $args
     * @return list<string>|null
     */
    private function match(array $args): ?array
    {
        [$ok, $methods] = Php::literal($args[0] ?? '');

        if (!$ok || !is_array($methods) || count($args) < 3) {
            return null;
        }

        return $this->map(array_values(array_map(static fn(mixed $m): string => strtoupper(is_string($m) ? $m : ''), $methods)), array_slice($args, 1));
    }

    /**
     * @param list<string> $methods
     * @param list<string> $args
     * @return list<string>|null
     */
    private function map(array $methods, array $args): ?array
    {
        if (count($args) < 2) {
            return null;
        }

        return ['$router->map(' . Php::export($methods) . ', ' . $this->path($args[0]) . ', ' . $this->handler($args[1]) . ')'];
    }

    /**
     * @param list<string> $args
     * @return list<string>|null
     */
    private function view(array $args): ?array
    {
        $view = Php::string($args[1] ?? '');

        if ($view === null) {
            return null;
        }

        $data = isset($args[2]) ? ', ' . $args[2] : '';

        return ['$router->get(' . $this->path($args[0]) . ', static fn (\\VeyroPHP\\View\\View $view): string => $view->render(' . var_export(str_replace('/', '.', $view), true) . $data . '))'];
    }

    /**
     * @param list<string> $args
     * @return list<string>|null
     */
    private function redirect(string $head, array $args): ?array
    {
        if (count($args) < 2) {
            return null;
        }

        $status = $args[2] ?? ($head === 'permanentredirect' ? '301' : '302');

        return ['$router->get(' . $this->path($args[0]) . ", static fn (\\VeyroPHP\\Http\\ResponseFactory \$response) => \$response->redirect({$args[1]}, {$status}))"];
    }

    /**
     * Expands Route::resource('photos', PhotoController::class) into its routes.
     *
     * @param list<array{string, list<string>}> $calls
     * @return list<string>|null
     */
    private function resource(string $head, array $calls): ?array
    {
        $name = Php::string($calls[0][1][0] ?? '');
        $controller = $calls[0][1][1] ?? null;

        if ($name === null || $controller === null || str_contains($name, '.')) {
            return null; // nested resources ("photos.comments") are left to a developer
        }

        $actions = array_keys(self::RESOURCE);

        if ($head === 'apiresource') {
            $actions = array_values(array_diff($actions, ['create', 'edit']));
        }

        foreach (array_slice($calls, 1) as [$method, $args]) {
            [$ok, $list] = Php::literal(count($args) === 1 ? $args[0] : '[' . implode(', ', $args) . ']');
            $list = $ok && is_array($list) ? array_filter($list, 'is_string') : null;

            match (strtolower($method)) {
                'only' => $list !== null ? $actions = array_values(array_intersect($actions, $list)) : $this->todos[] = 'resource only() is not a literal list',
                'except' => $list !== null ? $actions = array_values(array_diff($actions, $list)) : $this->todos[] = 'resource except() is not a literal list',
                default => $this->todos[] = "resource {$name}: ->{$method}() was dropped",
            };
        }

        $segment = trim($name, '/');

        // The name becomes a path, a route parameter and a route name: only plain path characters.
        if (preg_match('/^[A-Za-z0-9_-]+(?:\/[A-Za-z0-9_-]+)*$/D', $segment) !== 1) {
            return null;
        }

        $parameter = str_replace('-', '_', Words::singular((string) basename($segment)));
        $lines = [];

        foreach ($actions as $action) {
            [$method, $suffix, $withParameter] = self::RESOURCE[$action];
            $path = Php::export('/' . $segment . ($withParameter ? '/{' . $parameter . '}' : '') . $suffix);
            $handler = "[{$controller}, " . Php::export($action) . ']';
            $route = $method === 'map' ? "\$router->map(['PUT', 'PATCH'], {$path}, {$handler})" : "\$router->{$method}({$path}, {$handler})";
            $lines[] = $route . '->name(' . Php::export(str_replace('/', '.', $segment) . '.' . $action) . ')';
        }

        return $lines;
    }

    /**
     * Route::middleware(...)->prefix(...)->name(...)->group(function () { ... })
     * and Route::group(['prefix' => ..., 'middleware' => ...], function () { ... }).
     *
     * @param list<array{string, list<string>}> $calls
     * @return list<string>
     */
    private function group(array $calls, string $statement, string $indent): array
    {
        $before = count($this->todos);
        $prefix = '';
        $middleware = [];
        $namePrefix = '';
        $closure = null;

        foreach ($calls as [$method, $args]) {
            switch (strtolower($method)) {
                case 'prefix':
                    $prefix = $this->prefix($args[0] ?? '');
                    break;
                case 'middleware':
                    $middleware = [...$middleware, ...$this->middleware($args)];
                    break;
                case 'name':
                case 'as':
                    $namePrefix .= Php::string($args[0] ?? '') ?? '';
                    break;
                case 'group':
                    $closure = $args[count($args) - 1] ?? null;

                    if (count($args) === 2) {
                        [$ok, $attributes] = Php::literal($args[0]);

                        if (!$ok || !is_array($attributes)) {
                            return [$this->todoLine($indent, 'group attributes are not a literal array', $statement)];
                        }

                        $prefix = is_string($attributes['prefix'] ?? null) ? $this->prefix(var_export($attributes['prefix'], true)) : $prefix;
                        $namePrefix .= is_string($attributes['as'] ?? null) ? $attributes['as'] : '';

                        if (isset($attributes['middleware'])) {
                            $middleware = [...$middleware, ...$this->middleware([Php::export($attributes['middleware'])])];
                        }
                    }

                    break;
                case 'withoutmiddleware':
                    $this->todos[] = 'group ->withoutMiddleware(' . implode(', ', $args) . ') was dropped: global middleware cannot be skipped per group; move those middleware from the global list to the groups that need them';
                    break;
                default:
                    $this->todos[] = "group ->{$method}() was dropped";
            }
        }

        if ($closure === null || preg_match('/^(static\s+)?function\s*\([^)]*\)\s*(use\s*\([^)]*\)\s*)?\{(.*)\}$/sD', trim($closure), $m) !== 1) {
            return [$this->todoLine($indent, 'route group without a closure', $statement)];
        }

        $this->usesRouter = true;
        $notes = array_map(static fn(string $todo): string => $indent . '// TODO(port): ' . Php::comment((string) preg_replace('/\s+/', ' ', $todo)), array_slice($this->todos, $before));
        [, $body] = $this->statements($m[3], $indent . '    ');
        $arguments = [var_export($prefix, true), 'static function (Router $router): void {'];
        $lines = [...$notes, $indent . '$router->group(' . implode(', ', $arguments)];

        foreach ($body as $line) {
            $lines[] = $line;
        }

        while (end($lines) === '') {
            array_pop($lines);
        }

        $named = '';

        if ($middleware !== []) {
            $named .= ', middleware: [' . implode(', ', $middleware) . ']';
        }

        if ($namePrefix !== '') {
            $named .= ', namePrefix: ' . var_export($namePrefix, true);
        }

        $lines[] = $indent . '}' . $named . ');';

        return $lines;
    }

    /**
     * @param list<array{string, list<string>}> $calls
     */
    private function modifiers(array $calls): string
    {
        $out = '';

        foreach ($calls as [$method, $args]) {
            $lower = strtolower($method);

            if ($lower === 'name' || $lower === 'where' && count($args) === 2) {
                $out .= "->{$method}(" . implode(', ', $args) . ')';
            } elseif (isset(self::WHERE[$lower]) && count($args) === 1 && Php::string($args[0]) !== null) {
                $out .= "->where({$args[0]}, " . var_export(self::WHERE[$lower], true) . ')';
            } elseif ($lower === 'middleware') {
                $middleware = $this->middleware($args);
                $out .= $middleware === [] ? '' : '->middleware(' . implode(', ', $middleware) . ')';
            } else {
                $this->todos[] = "->{$method}() was dropped from a route";
            }
        }

        return $out;
    }

    /**
     * @param list<string> $args
     * @return list<string> class-string expressions
     */
    private function middleware(array $args): array
    {
        $out = [];

        foreach ($args as $arg) {
            [$ok, $value] = Php::literal($arg);
            $items = $ok ? (is_array($value) ? $value : [$value]) : [];

            if (!$ok) {
                $this->todos[] = "middleware {$arg} is not a literal: add it by hand";
            }

            foreach ($items as $item) {
                if ($item instanceof ClassName) {
                    $out[] = Php::export($item);
                } elseif (is_string($item) && isset(self::MIDDLEWARE[$item])) {
                    $out[] = self::MIDDLEWARE[$item];
                } elseif (is_string($item)) {
                    $this->todos[] = "middleware '{$item}' has no automatic equivalent: add a PSR-15 middleware";
                }
            }
        }

        return array_values(array_unique($out));
    }

    private function path(string $raw): string
    {
        $path = Php::string($raw);

        if ($path === null) {
            $this->todos[] = "route path {$raw} is not a literal string";

            return $raw;
        }

        // Laravel's {post:slug} binds a model by another column; in VeyroPHP the part after ":" is a regex.
        if (preg_match_all('/\{(\w+):(\w+)\}/', $path, $bindings, PREG_SET_ORDER) > 0) {
            foreach ($bindings as [, $parameter, $field]) {
                $this->todos[] = "{{$parameter}:{$field}} looked the model up by {$field}: find it by that column in the handler";
            }

            $path = (string) preg_replace('/\{(\w+):\w+\}/', '{$1}', $path);
        }

        if (preg_match('/\{(\w+)\?\}/', $path) === 1) {
            $this->todos[] = "optional parameter in '{$path}' made required: add a second route without it";
            $path = (string) preg_replace('/\{(\w+)\?\}/', '{$1}', $path);
        }

        $path = '/' . trim($path, '/');

        return var_export($path, true);
    }

    private function prefix(string $raw): string
    {
        $prefix = Php::string($raw);

        if ($prefix === null) {
            $this->todos[] = "group prefix {$raw} is not a literal string";

            return '';
        }

        $prefix = trim($prefix, '/');

        return $prefix === '' ? '' : '/' . $prefix;
    }

    private function handler(string $raw): string
    {
        $string = Php::string($raw);

        if ($string !== null && str_contains($string, '@')) {
            [$class, $method] = explode('@', $string, 2);

            // The class becomes code, so it must be a class name; the method stays a string literal.
            if (Php::className($class) === null || str_starts_with($class, '\\')) {
                $this->todos[] = "handler '{$string}' is not Class@method: convert it by hand";

                return $raw;
            }

            $this->todos[] = "handler '{$string}' assumed in App\\Http\\Controllers";

            return "[\\App\\Http\\Controllers\\{$class}::class, " . Php::export($method) . ']';
        }

        if (preg_match('/^(static\s+)?(function|fn)\b/', $raw) === 1 && preg_match('/\b(view|redirect|request|response|auth|session|back|abort|route|url|config|env)\s*\(/', $raw) === 1) {
            $this->todos[] = 'a route closure uses Laravel helpers: inject services instead';
        }

        return $raw;
    }

    private function todoLine(string $indent, string $note, string $code): string
    {
        $this->todos[] = $note;
        $code = (string) preg_replace('/\s+/', ' ', trim($code));

        return $indent . '// TODO(port): ' . Php::comment($note . ': ' . (strlen($code) > 200 ? substr($code, 0, 197) . '...' : $code));
    }
}
