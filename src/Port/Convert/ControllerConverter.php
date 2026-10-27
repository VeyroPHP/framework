<?php

declare(strict_types=1);

namespace VeyroPHP\Port\Convert;

use VeyroPHP\Orm\ModelQuery;

/**
 * Converts a Laravel controller mechanically: services come through the
 * constructor ($this->view, $this->orm, ...), Request becomes the PSR-7
 * request, and calls with a fixed VeyroPHP counterpart are rewritten:
 * view(), response(), redirect('/path'), abort*(), config(), route(),
 * $request->validate(), Cache::/Log::/Auth:: and model static calls that
 * map onto ModelQuery methods (read by reflection). Everything else, such as
 * Eloquent scopes, withCount() or Carbon, keeps its code and gets a
 * TODO(port) comment on the line above.
 */
final class ControllerConverter
{
    /** property => [class, short name] */
    private const SERVICES = [
        'view' => ['VeyroPHP\View\View', 'View'],
        'response' => ['VeyroPHP\Http\ResponseFactory', 'ResponseFactory'],
        'orm' => ['VeyroPHP\Orm\Orm', 'Orm'],
        'config' => ['VeyroPHP\Config\Config', 'Config'],
        'router' => ['VeyroPHP\Routing\Router', 'Router'],
        'cache' => ['VeyroPHP\Cache\Cache', 'Cache'],
        'logger' => ['Psr\Log\LoggerInterface', 'LoggerInterface'],
        'auth' => ['VeyroPHP\Auth\Auth', 'Auth'],
        'validator' => ['VeyroPHP\Validation\Validator', 'Validator'],
    ];

    /** @var list<string> */
    private array $notes = [];

    /** @var array<string, true> */
    private array $needed = [];

    /** @var list<string> */
    private array $queryMethods = [];

    public function convert(string $source, string $code): ?ConvertedFile
    {
        if (preg_match('/\b(abstract\s+|final\s+)?class\s+(\w+)/', $code, $class) !== 1) {
            return null;
        }

        if (trim($class[1]) === 'abstract' && preg_match('/\bfunction\s/', $code) !== 1) {
            return null; // Laravel's empty base Controller is not needed
        }

        $this->notes = [];
        $this->needed = [];
        $this->queryMethods = array_values(array_map(static fn(\ReflectionMethod $m): string => $m->getName(), array_filter(
            (new \ReflectionClass(ModelQuery::class))->getMethods(\ReflectionMethod::IS_PUBLIC),
            static fn(\ReflectionMethod $m): bool => !$m->isStatic() && !str_starts_with($m->getName(), '__'),
        )));

        preg_match_all('/^\s*use\s+App\\\\Models\\\\(\w+)\s*;/m', $code, $models);
        $models = $models[1];

        $code = $this->classLine($code);
        $code = $this->uses($code);
        $code = $this->request($code);
        $code = $this->facades($code);
        $code = $this->modelCalls($code, $models);
        $code = $this->helpers($code);
        $code = (string) preg_replace_callback('/\$this->(middleware|authorize|validate|dispatch)\s*\(/', fn(array $m): string => $this->mark("\$this->{$m[1]}() comes from Laravel's base controller: use middleware, RBAC or Validator") . $m[0], $code);
        // Laravel types in code (not in comments or use lines, which are handled already).
        $code = implode("\n", array_map(fn(string $line): string => preg_match('#^\s*(\*|/\*|//|use\s)#', $line) === 1
            ? $line
            : (string) preg_replace_callback('/\\\\?Illuminate\\\\[\w\\\\]+/', fn(array $m): string => $this->mark("{$m[0]} is a Laravel type") . $m[0], $line), preg_split('/\R/', $code) ?: []));
        $code = $this->constructor($code);
        // A callback, not a replacement string: "$1" or "\1" in a source path must stay text.
        $marker = "<?php\n\n// " . Php::comment(ConvertedFile::MARKER . " from {$source}. Review every TODO(port).") . "\n\n";
        $code = (string) preg_replace_callback('/^<\?php\s*/', static fn(): string => $marker, $code, 1);
        $content = $this->placeNotes($code);

        return new ConvertedFile('controller', $source, $source, $content, array_values(array_unique($this->notes)));
    }

    private function classLine(string $code): string
    {
        $code = (string) preg_replace('/(class\s+\w+)\s+extends\s+\\\\?(?:App\\\\Http\\\\Controllers\\\\)?Controller\b/', '$1', $code);

        return (string) preg_replace('/^(\s*)class\s+/m', '$1final class ', $code, 1);
    }

    private function uses(string $code): string
    {
        $code = (string) preg_replace('/^use\s+Illuminate\\\\Http\\\\Request\s*;/m', 'use Psr\Http\Message\ServerRequestInterface;', $code);
        $code = (string) preg_replace('/^use\s+Illuminate\\\\Support\\\\Facades\\\\\w+\s*;\R/m', '', $code);

        return (string) preg_replace_callback('/^use\s+(Illuminate\\\\[\w\\\\]+)\s*;/m', fn(array $m): string => '// ' . $this->mark("{$m[1]} is a Laravel class") . $m[0], $code);
    }

    private function request(string $code): string
    {
        $code = (string) preg_replace('/([(,]\s*)\\\\?(?:Illuminate\\\\Http\\\\)?Request(\s+\$\w+)/', '$1ServerRequestInterface$2', $code);

        $code = (string) preg_replace_callback('/\$request->validate\(/', function (): string {
            $this->needed['validator'] = true;

            return '$this->validator->validate($request->getParsedBody() ?? [], ';
        }, $code);
        $code = (string) preg_replace_callback('/\$request->user\(\)/', function (): string {
            $this->needed['auth'] = true;

            return '$this->auth->user()';
        }, $code);

        return (string) preg_replace_callback('/\$request->(input|get|query|all|only|except|has|filled|boolean|integer|string|file|hasFile|ip|route|session|merge|post|json|cookie|header|bearerToken|wantsJson|ajax|isMethod|fullUrl|url|path)\(/', fn(array $m): string => $this->mark("\$request->{$m[1]}() is Laravel's Request: use getParsedBody(), getQueryParams(), getAttribute() or the PSR-7 methods") . $m[0], $code);
    }

    private function facades(string $code): string
    {
        $code = (string) preg_replace_callback('/\bCache::(remember|rememberForever|get|put|forget|has|add|pull|forever)\s*\(/', function (array $m): string {
            $this->needed['cache'] = true;

            return $this->mark('cached values must be scalars or arrays unless their classes are allowed in cache.allowed_classes') . "\$this->cache->{$m[1]}(";
        }, $code);
        $code = (string) preg_replace_callback('/\bLog::(emergency|alert|critical|error|warning|notice|info|debug|log)\s*\(/', function (array $m): string {
            $this->needed['logger'] = true;

            return "\$this->logger->{$m[1]}(";
        }, $code);
        $code = (string) preg_replace_callback('/\bAuth::(user|check|id)\(\)/', function (array $m): string {
            $this->needed['auth'] = true;

            return "\$this->auth->{$m[1]}()";
        }, $code);
        $code = (string) preg_replace_callback('/\bAuth::guest\(\)/', function (): string {
            $this->needed['auth'] = true;

            return '!$this->auth->check()';
        }, $code);

        return (string) preg_replace_callback('/\b(Auth|DB|Mail|Storage|Event|Queue|Http|Gate|Session|Validator|Hash|Notification|Redirect|Response|View|URL|Str|Arr|Cache|Log|Bus|Crypt|Cookie)::(\w+)/', fn(array $m): string => $this->mark("{$m[1]}::{$m[2]}() has no automatic conversion: inject the VeyroPHP service") . $m[0], $code);
    }

    /**
     * @param list<string> $models
     */
    private function modelCalls(string $code, array $models): string
    {
        if ($models === []) {
            return $code;
        }

        $pattern = '/(?<![\w\\\\$>])(' . implode('|', array_map('preg_quote', $models)) . ')::(\w+)\s*\(/';

        $code = (string) preg_replace_callback($pattern, function (array $m): string {
            [, $model, $method] = $m;

            if (in_array($method, ['find', 'findOrFail', 'create'], true)) {
                $this->needed['orm'] = true;

                return "\$this->orm->{$method}({$model}::class, ";
            }

            if ($method === 'all' || $method === 'query' || in_array($method, $this->queryMethods, true)) {
                $this->needed['orm'] = true;
                $tail = match ($method) {
                    'all' => '->get(',
                    'query' => '->toBase(',
                    default => "->{$method}(",
                };

                return "\$this->orm->query({$model}::class){$tail}";
            }

            return $this->mark("{$model}::{$method}() (scope or Eloquent feature) has no VeyroPHP equivalent") . $m[0];
        }, $code);

        // query() means "start a query": drop the placeholder call.
        $code = str_replace('->toBase()', '', $code);

        // Methods chained on a converted query that ModelQuery does not have.
        return $this->checkChains($code);
    }

    private function checkChains(string $code): string
    {
        $offset = 0;
        $result = '';

        while (($pos = strpos($code, '$this->orm->query(', $offset)) !== false) {
            $start = $pos + strlen('$this->orm->query(');
            $close = Php::matchingParen($code, $start - 1);

            if ($close === null) {
                break;
            }

            $unsupported = [];
            $i = $close + 1;

            while (preg_match('/\G\s*->\s*(\w+)\s*\(/', $code, $c, 0, $i) === 1) {
                $open = $i + strlen($c[0]) - 1;
                $end = Php::matchingParen($code, $open);

                if ($end === null) {
                    break;
                }

                $args = trim(substr($code, $open + 1, $end - $open - 1));

                if (!in_array($c[1], $this->queryMethods, true)) {
                    $unsupported[] = $c[1] . '()';
                } elseif ($c[1] === 'get' && $args !== '') {
                    $unsupported[] = 'get() with columns (use select())';
                }

                $i = $end + 1;
            }

            $result .= substr($code, $offset, $pos - $offset);

            if ($unsupported !== []) {
                $result .= $this->mark('chained ' . implode(', ', array_unique($unsupported)) . ' not available on ModelQuery: rewrite with where()/orderBy()/with() or a query service');
            }

            $result .= '$this->orm->query(';
            $offset = $start;
        }

        return $result . substr($code, $offset);
    }

    private function helpers(string $code): string
    {
        $call = '/(?<![\w$>:\\\\])';

        $code = (string) preg_replace_callback($call . 'view\s*\(/', function (): string {
            $this->needed['view'] = true;

            return '$this->view->render(';
        }, $code);
        $code = $this->stripRenderChain($code);

        $code = (string) preg_replace_callback($call . 'response\(\)->json\(/', function (): string {
            $this->needed['response'] = true;

            return '$this->response->json(';
        }, $code);
        $code = (string) preg_replace_callback($call . 'response\((?!\))/', function (): string {
            $this->needed['response'] = true;

            return '$this->response->html(';
        }, $code);
        $code = (string) preg_replace_callback($call . 'redirect\(\s*([\'"][^\'"]*[\'"])\s*\)/', function (array $m): string {
            $this->needed['response'] = true;

            return "\$this->response->redirect({$m[1]})";
        }, $code);
        $code = (string) preg_replace_callback($call . 'config\s*\(/', function (): string {
            $this->needed['config'] = true;

            return $this->mark('config key must exist in the VeyroPHP config/*.php files') . '$this->config->get(';
        }, $code);
        $code = (string) preg_replace_callback($call . 'route\s*\(/', function (): string {
            $this->needed['router'] = true;

            return $this->mark('route() gave an absolute URL; Router::path() gives a path (no base path, no host) and takes parameters as an array') . '$this->router->path(';
        }, $code);
        $code = (string) preg_replace_callback($call . 'auth\(\)->(user|check|id)\(\)/', function (array $m): string {
            $this->needed['auth'] = true;

            return "\$this->auth->{$m[1]}()";
        }, $code);
        $code = (string) preg_replace($call . 'now\(\)(?!\s*->)/', 'new \DateTimeImmutable()', $code);
        $code = (string) preg_replace($call . 'today\(\)(?!\s*->)/', "new \\DateTimeImmutable('today')", $code);
        $code = $this->aborts($code);

        return (string) preg_replace_callback($call . '(now|today|url|back|redirect|session|request|response|auth|blank|filled|optional|tap|data_get|str|e|cache|old|asset|app|abort_if|abort_unless|abort|dispatch|event|logger|bcrypt|trans|__)\s*\(/', fn(array $m): string => $this->mark("{$m[1]}() is a Laravel helper: inject the VeyroPHP service or use plain PHP") . $m[0], $code);
    }

    /**
     * view(...)->render() is already a string after conversion.
     */
    private function stripRenderChain(string $code): string
    {
        $offset = 0;

        while (($pos = strpos($code, '$this->view->render(', $offset)) !== false) {
            $close = Php::matchingParen($code, $pos + strlen('$this->view->render(') - 1);

            if ($close === null) {
                break;
            }

            if (preg_match('/\G\s*->\s*render\(\s*\)/', $code, $m, 0, $close + 1) === 1) {
                $code = substr($code, 0, $close + 1) . substr($code, $close + 1 + strlen($m[0]));
            }

            $offset = $close;
        }

        return $code;
    }

    /**
     * abort(404), abort_if($c, 404), abort_unless($c, 404) as statements.
     */
    private function aborts(string $code): string
    {
        $offset = 0;

        while (preg_match('/(?<![\w$>:\\\\])(abort|abort_if|abort_unless)\s*\(/', $code, $m, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $start = $m[0][1];
            $open = $start + strlen($m[0][0]) - 1;
            $close = Php::matchingParen($code, $open);

            if ($close === null || !preg_match('/\G\s*;/', $code, $semi, 0, $close + 1)) {
                $offset = $open + 1;

                continue;
            }

            $args = Php::splitArgs(substr($code, $open + 1, $close - $open - 1));
            $replacement = match ($m[1][0]) {
                'abort' => self::throwHttp($args),
                'abort_if' => 'if (' . ($args[0] ?? 'true') . ') { ' . self::throwHttp(array_slice($args, 1)) . ' }',
                default => 'if (!(' . ($args[0] ?? 'false') . ')) { ' . self::throwHttp(array_slice($args, 1)) . ' }',
            };

            $code = substr($code, 0, $start) . $replacement . substr($code, $close + 1 + strlen($semi[0]));
            $offset = $start + strlen($replacement);
        }

        return $code;
    }

    /**
     * @param list<string> $args status code and optional message, as source code
     */
    private static function throwHttp(array $args): string
    {
        return 'throw new \VeyroPHP\Http\Exception\HttpException(' . implode(', ', array_map('trim', $args)) . ');';
    }

    private function constructor(string $code): string
    {
        if ($this->needed === []) {
            return $code;
        }

        $params = [];
        $uses = [];

        foreach (array_keys(self::SERVICES) as $property) {
            if (isset($this->needed[$property])) {
                [$class, $short] = self::SERVICES[$property];
                $params[] = "private readonly {$short} \${$property},";
                $uses[] = "use {$class};";
            }
        }

        if (preg_match('/function\s+__construct\s*\(/', $code, $m, PREG_OFFSET_CAPTURE) === 1) {
            $at = $m[0][1] + strlen($m[0][0]);
            $close = Php::matchingParen($code, $at - 1);
            $existing = $close === null ? '' : trim(substr($code, $at, $close - $at));
            $code = substr($code, 0, $at) . "\n        " . implode("\n        ", $params) . ($existing === '' ? "\n    " : "\n        ") . substr($code, $at);
        } else {
            $code = (string) preg_replace('/(final class\s+\w+[^{]*\{)/', "$1\n    public function __construct(\n        " . implode("\n        ", $params) . "\n    ) {}\n", $code, 1);
        }

        // Add use statements after the namespace line (or the last use statement).
        $existing = [];
        preg_match_all('/^use\s+([\w\\\\]+)\s*;/m', $code, $u);

        foreach ($u[1] as $use) {
            $existing['use ' . $use . ';'] = true;
        }

        $uses = array_values(array_filter($uses, static fn(string $use): bool => !isset($existing[$use])));

        if ($uses !== []) {
            $code = (string) preg_replace('/^(namespace\s+[\w\\\\]+\s*;\R)/m', "$1\n" . implode("\n", $uses) . "\n", $code, 1);
        }

        return $code;
    }

    private function mark(string $note): string
    {
        $this->notes[] = $note;

        return "\x04" . (count($this->notes) - 1) . "\x04";
    }

    /**
     * Turns markers into "// TODO(port): ..." lines above the line they are on.
     */
    private function placeNotes(string $code): string
    {
        $out = [];

        foreach (preg_split('/\R/', $code) ?: [] as $line) {
            if (preg_match_all("/\x04(\\d+)\x04/", $line, $m) > 0) {
                $indent = (string) preg_replace('/\S.*$/D', '', $line);
                $line = (string) preg_replace("/\x04\\d+\x04/", '', $line);

                foreach (array_unique($m[1]) as $index) {
                    $out[] = $indent . '// TODO(port): ' . Php::comment($this->notes[(int) $index]);
                }
            }

            $out[] = $line;
        }

        return implode("\n", $out);
    }
}
