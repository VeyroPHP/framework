<?php

declare(strict_types=1);

namespace VeyroPHP\Port;

/**
 * Reads a Laravel project and maps what it uses to VeyroPHP. Static only:
 * composer files are decoded as JSON and PHP files are tokenized.
 */
final class LaravelAnalyzer implements Analyzer
{
    use AnalyzesProjects;

    private const GUIDE = 'docs/migrating/from-laravel.md';

    /** Route methods with a VeyroPHP equivalent of the same shape. */
    private const ROUTE_DIRECT = ['get', 'post', 'put', 'patch', 'delete', 'options', 'match', 'group', 'prefix', 'middleware', 'name', 'where'];


    /** Facade => [status, VeyroPHP equivalent]. */
    private const FACADES = [
        'Auth' => [Status::Manual, 'inject Auth (attempt, user, check, logout)'],
        'DB' => [Status::Manual, 'inject Connection: table(), select(), transaction()'],
        'Cache' => [Status::Manual, 'inject the PSR-16 cache or CacheManager'],
        'Log' => [Status::Manual, 'inject Psr\Log\LoggerInterface'],
        'Mail' => [Status::Manual, 'inject Mailer; Mailable classes'],
        'Queue' => [Status::Manual, 'inject QueueManager; Job classes'],
        'Bus' => [Status::Manual, 'Job classes and $queue->push()'],
        'Event' => [Status::Manual, 'inject the PSR-14 EventDispatcher'],
        'Notification' => [Status::Manual, 'inject Notifier'],
        'Storage' => [Status::Manual, 'inject FilesystemManager'],
        'Session' => [Status::Manual, 'inject Session'],
        'Hash' => [Status::Manual, 'inject PasswordHasher'],
        'Crypt' => [Status::Manual, 'inject Encrypter'],
        'Validator' => [Status::Manual, 'inject Validator'],
        'Gate' => [Status::Manual, 'RBAC permissions and RequirePermission middleware'],
        'Schema' => [Status::Manual, 'Schema in migrations'],
        'Config' => [Status::Manual, 'inject Config'],
        'Artisan' => [Status::Manual, 'console commands; run them through Console'],
        'Cookie' => [Status::Manual, 'Cookie helper and PSR-7 responses'],
        'Redis' => [Status::Manual, 'Redis cache store or queue; no general Redis facade'],
        'Lang' => [Status::Manual, 'inject the Translator'],
        'URL' => [Status::Manual, 'Router::path() and UrlSigner'],
        'Redirect' => [Status::Manual, 'ResponseFactory redirects'],
        'Response' => [Status::Manual, 'ResponseFactory'],
        'View' => [Status::Manual, 'inject View'],
        'Password' => [Status::Manual, 'PasswordBroker'],
        'RateLimiter' => [Status::Manual, 'RateLimiter and ThrottleRequests'],
        'Http' => [Status::Unsupported, 'no HTTP client is built in: use Guzzle or any PSR-18 client'],
        'Broadcast' => [Status::Unsupported, 'no broadcasting (websockets)'],
        'Process' => [Status::Unsupported, 'no process facade: use symfony/process'],
        'Pipeline' => [Status::Unsupported, 'no general pipeline helper'],
        'Concurrency' => [Status::Unsupported, 'no concurrency helper'],
    ];

    /** Global helper => [status, note]. */
    private const HELPERS = [
        'env' => [Status::Manual, 'only inside config/*.php ($env); inject Config elsewhere'],
        'config' => [Status::Manual, 'inject Config'],
        'view' => [Status::Manual, 'inject View: $view->render()'],
        'route' => [Status::Manual, '$router->path()'],
        'url' => [Status::Manual, 'Router::path() plus the base path'],
        'redirect' => [Status::Manual, 'ResponseFactory'],
        'back' => [Status::Manual, 'ResponseFactory redirect to the previous URL'],
        'request' => [Status::Manual, 'the PSR-7 request as a handler argument'],
        'response' => [Status::Manual, 'return a value or use ResponseFactory'],
        'auth' => [Status::Manual, 'inject Auth'],
        'session' => [Status::Manual, 'inject Session'],
        'cache' => [Status::Manual, 'inject the cache'],
        'dispatch' => [Status::Manual, '$queue->push()'],
        'event' => [Status::Manual, '$events->dispatch()'],
        'abort' => [Status::Manual, 'throw HttpException'],
        'old' => [Status::Manual, 'old input from the session'],
        'csrf_token' => [Status::Manual, 'the session CSRF token'],
        '__' => [Status::Manual, 'the Translator'],
        'trans' => [Status::Manual, 'the Translator'],
        'app' => [Status::Manual, 'constructor injection instead of the container'],
        'resolve' => [Status::Manual, 'constructor injection'],
        'bcrypt' => [Status::Manual, 'PasswordHasher'],
        'logger' => [Status::Manual, 'inject LoggerInterface'],
        'now' => [Status::Manual, 'new DateTimeImmutable() or Carbon'],
        'today' => [Status::Manual, 'new DateTimeImmutable("today") or Carbon'],
        'storage_path' => [Status::Manual, '$app->basePath("storage/...")'],
        'base_path' => [Status::Manual, '$app->basePath()'],
        'public_path' => [Status::Manual, '$app->basePath("public/...")'],
        'asset' => [Status::Manual, 'the application base path plus the file'],
        'collect' => [Status::Direct, 'works with composer require illuminate/collections'],
        'dd' => [Status::Direct, 'works with composer require --dev symfony/var-dumper'],
        'dump' => [Status::Direct, 'works with composer require --dev symfony/var-dumper'],
        'mix' => [Status::Unsupported, 'no asset pipeline integration: link the built files'],
        'vite' => [Status::Unsupported, 'no asset pipeline integration: link the built files'],
    ];

    /** Blade directives => [status, VeyroPHP template equivalent]. */
    private const BLADE = [
        'extends' => [Status::Direct, '$view->layout()'],
        'section' => [Status::Direct, '$view->section() / endSection()'],
        'yield' => [Status::Direct, '$view->yield()'],
        'include' => [Status::Direct, '$view->render()'],
        'push' => [Status::Direct, '$view->push() / endPush()'],
        'stack' => [Status::Direct, '$view->stack()'],
        'component' => [Status::Direct, '$view->component() or startComponent()'],
        'slot' => [Status::Direct, '$view->slot() / endSlot()'],
        'foreach' => [Status::Direct, 'foreach (...): / endforeach'],
        'forelse' => [Status::Manual, 'foreach plus an empty check'],
        'if' => [Status::Direct, 'if (...): / endif'],
        'unless' => [Status::Direct, 'if (!...):'],
        'isset' => [Status::Direct, 'if (isset(...)):'],
        'empty' => [Status::Direct, 'if (empty(...)):'],
        'for' => [Status::Direct, 'for (...):'],
        'while' => [Status::Direct, 'while (...):'],
        'php' => [Status::Direct, '<?php ... ?>'],
        'json' => [Status::Direct, 'json_encode() with JSON_HEX_* flags'],
        'csrf' => [Status::Manual, 'the CSRF field from the session token'],
        'method' => [Status::Manual, 'a _method hidden field is not supported: use real methods or JSON'],
        'auth' => [Status::Manual, 'pass the user from Auth to the view'],
        'guest' => [Status::Manual, 'pass the user from Auth to the view'],
        'can' => [Status::Manual, 'check RBAC permissions in the handler and pass a flag'],
        'error' => [Status::Manual, 'validation errors passed to the view'],
        'lang' => [Status::Manual, 'the Translator'],
        'vite' => [Status::Unsupported, 'no asset pipeline integration'],
        'livewire' => [Status::Unsupported, 'no Livewire'],
        'livewirestyles' => [Status::Unsupported, 'no Livewire'],
        'livewirescripts' => [Status::Unsupported, 'no Livewire'],
        'inertia' => [Status::Unsupported, 'no Inertia'],
    ];

    /** Folder => [item, status, note]. */
    private const FOLDERS = [
        'app/Http/Controllers' => ['Controllers', Status::Manual, 'same idea; replace Request with PSR-7 and Validator, facades with injected services'],
        'app/Http/Middleware' => ['Middleware', Status::Manual, 'rewrite as PSR-15 middleware (process($request, $handler))'],
        'app/Http/Requests' => ['Form requests', Status::Manual, 'validate in the handler with Validator'],
        'app/Http/Resources' => ['API resources', Status::Manual, 'return arrays (toArray of the model, HIDDEN for secret columns)'],
        'app/Jobs' => ['Queued jobs', Status::Manual, 'Job classes with JSON payloads; no serialized models or closures'],
        'app/Events' => ['Events', Status::Direct, 'plain event classes work with the PSR-14 dispatcher'],
        'app/Listeners' => ['Listeners', Status::Manual, 'register with listen() in a service provider'],
        'app/Mail' => ['Mailables', Status::Manual, 'Mailable classes with a Message'],
        'app/Notifications' => ['Notifications', Status::Manual, 'Notification classes: mail and database channels'],
        'app/Console/Commands' => ['Console commands', Status::Manual, '#[AsCommand] classes with definition() and execute()'],
        'app/Policies' => ['Policies', Status::Manual, 'RBAC roles and permissions'],
        'app/Providers' => ['Service providers', Status::Manual, 'same register()/boot() idea; bindings move to the container'],
        'app/Observers' => ['Model observers', Status::Manual, 'listeners for the model events (ModelSaving, ModelSaved, ModelDeleting, ModelDeleted, ModelRestored)'],
        'app/Livewire' => ['Livewire components', Status::Unsupported, 'no Livewire'],
        'database/factories' => ['Factories', Status::Manual, 'Factory classes with the built-in Fake'],
        'database/seeders' => ['Seeders', Status::Manual, 'Seeder classes'],
        'tests' => ['Tests', Status::Manual, 'PHPUnit stays; HTTP tests use TestClient'],
    ];

    /** .env keys that keep their name in the VeyroPHP starter. */
    private const ENV_SAME = ['APP_NAME', 'APP_ENV', 'APP_DEBUG', 'APP_URL', 'APP_KEY', 'APP_PREVIOUS_KEYS', 'LOG_LEVEL', 'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'QUEUE_CONNECTION', 'MAIL_MAILER', 'MAIL_FROM_ADDRESS', 'MAIL_FROM_NAME', 'MAIL_HOST', 'MAIL_PORT', 'MAIL_ENCRYPTION', 'MAIL_USERNAME', 'MAIL_PASSWORD', 'FILESYSTEM_DISK', 'AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'AWS_DEFAULT_REGION', 'AWS_BUCKET', 'AWS_ENDPOINT', 'ANTHROPIC_API_KEY'];

    public function name(): string
    {
        return 'laravel';
    }

    public function detects(SourceTree $tree): bool
    {
        return $this->requires($tree, 'laravel/framework') || $tree->exists('artisan') && $tree->exists('bootstrap/app.php');
    }

    public function analyze(SourceTree $tree): Report
    {
        $report = new Report('laravel', $this->lockedVersion($tree, 'laravel/framework'), $tree->root);

        if ($tree->truncated()) {
            $report->warn('The project has more files than the analyzer reads (20,000); counts are incomplete.');
        }

        $this->packages($tree, $report);
        $this->routes($tree, $report);
        $this->models($tree, $report);
        $this->migrations($tree, $report);
        $this->views($tree, $report);
        $this->facadesAndHelpers($tree, $report);
        $this->folders($tree, $report, self::FOLDERS, self::GUIDE);
        $this->environment($tree, $report);

        return $report;
    }

    private function packages(SourceTree $tree, Report $report): void
    {
        $this->classifyPackages($tree, $report, self::GUIDE, function (string $name): ?array {
            return match (true) {
                $name === 'laravel/framework' => null,
                $name === 'laravel/sanctum' => [Status::Direct, 'built-in API tokens (vyr_ tokens)'],
                in_array($name, ['laravel/ui', 'laravel/breeze', 'laravel/jetstream', 'laravel/fortify'], true) => [Status::Manual, 'built-in authentication, password reset, email verification and RBAC'],
                in_array($name, ['laravel/tinker', 'laravel/sail', 'laravel/pail'], true) => [Status::Unsupported, 'Laravel tooling with no VeyroPHP counterpart'],
                in_array($name, ['laravel/pint', 'nunomaduro/collision', 'fakerphp/faker', 'mockery/mockery', 'phpunit/phpunit', 'pestphp/pest'], true) => [Status::Direct, 'framework-independent development tool'],
                str_starts_with($name, 'laravel/'), str_starts_with($name, 'livewire/'), str_starts_with($name, 'inertiajs/'), str_starts_with($name, 'filament/') => [Status::Unsupported, 'part of the Laravel ecosystem'],
                str_contains($name, 'laravel') => [Status::Unsupported, 'Laravel-specific package (needs Laravel\'s container and providers)'],
                default => [Status::Direct, 'framework-independent Composer package'],
            };
        }, 'illuminate/');
    }

    private function routes(SourceTree $tree, Report $report): void
    {
        $files = $tree->under('routes');
        [$calls, $where] = $this->sum($tree, $files, static fn(array $tokens): array => Tokens::staticCalls($tokens, ['Route']));

        $direct = $manual = 0;
        $manualKinds = $manualFiles = [];

        foreach ($calls as $call => $count) {
            $method = strtolower(substr($call, strlen('Route::')));

            if (in_array($method, self::ROUTE_DIRECT, true)) {
                $direct += $count;
            } else {
                $manual += $count;
                $manualKinds[] = $method;
                $manualFiles = array_merge($manualFiles, $where[$call]);
            }
        }

        if ($direct > 0) {
            $report->add(new Finding('Routes', 'Route definitions (get, post, group, middleware, name, ...)', Status::Direct, $direct, 'Route::get() becomes $router->get(); groups take a prefix, middleware and name prefix', $this->examples($files), self::GUIDE));
        }

        if ($manual > 0) {
            $report->add(new Finding('Routes', 'Route::' . implode(', Route::', array_unique($manualKinds)), Status::Manual, $manual, 'no resource/view/redirect/any helpers: define each route explicitly', $this->examples($manualFiles), self::GUIDE));
        }

        $optional = 0;

        foreach ($files as $file) {
            $optional += preg_match_all('/\{\w+\?\}/', $tree->read($file));
        }

        if ($optional > 0) {
            $report->add(new Finding('Routes', 'Optional route parameters {name?}', Status::Manual, $optional, 'not supported: define a second route without the parameter', $this->examples($files), self::GUIDE));
        }
    }

    private function models(SourceTree $tree, Report $report): void
    {
        $files = array_values(array_filter($tree->under('app'), fn(string $f): bool => preg_match(Patterns::ELOQUENT_MODEL, $tree->read($f)) === 1));

        if ($files === []) {
            return;
        }

        $report->add(new Finding('Models', 'Eloquent models', Status::Manual, count($files), 'Model with TABLE/FILLABLE/HIDDEN/CASTS constants; queries through the Orm service', $this->examples($files), 'docs/database/orm.md'));

        $supported = Capabilities::relationTypes();
        $supportedLower = array_map('strtolower', $supported);
        $relations = $morphRelations = $unsupportedRelations = [];
        $features = ['SoftDeletes' => [], 'scopes' => [], 'accessors' => [], 'guarded' => [], 'events' => []];

        foreach ($files as $file) {
            $code = $tree->read($file);
            preg_match_all('/\$this->(\w+)\(/', $code, $m);

            foreach ($m[1] as $call) {
                $type = strtolower($call);

                if (preg_match('/^(belongsto|hasone|hasmany|belongstomany|hasmanythrough|hasonethrough|morphto|morphone|morphmany|morphtomany|morphedbymany)$/D', $type) === 1) {
                    $index = array_search($type, $supportedLower, true);

                    // Morph relations exist, but Laravel infers the type column and stores class
                    // names; VeyroPHP names the columns and maps aliases explicitly.
                    if ($index !== false && str_starts_with($type, 'morph')) {
                        $morphRelations[$supported[$index]] = ($morphRelations[$supported[$index]] ?? 0) + 1;
                    } elseif ($index !== false) {
                        $relations[$supported[$index]] = ($relations[$supported[$index]] ?? 0) + 1;
                    } else {
                        $unsupportedRelations[$call] = ($unsupportedRelations[$call] ?? 0) + 1;
                    }
                }
            }

            if (preg_match('/\buse\s+[^;]*\bSoftDeletes\b/', $code) === 1) {
                $features['SoftDeletes'][] = $file;
            }
            if (preg_match('/function\s+scope[A-Z]\w*\s*\(/', $code) === 1 || str_contains($code, '#[Scope]')) {
                $features['scopes'][] = $file;
            }
            if (preg_match('/function\s+(get|set)\w+Attribute\s*\(|Attribute::make\(/', $code) === 1) {
                $features['accessors'][] = $file;
            }
            if (preg_match('/\$guarded\s*=/', $code) === 1) {
                $features['guarded'][] = $file;
            }
            if (preg_match('/function\s+booted?\s*\(|\$dispatchesEvents\s*=/', $code) === 1) {
                $features['events'][] = $file;
            }
        }

        if ($relations !== []) {
            $report->add(new Finding('Models', 'Relations: ' . implode(', ', array_keys($relations)), Status::Direct, array_sum($relations), 'declared in relations() with Relation::' . implode('/', array_keys($relations)) . '(); loaded with with() or load()', [], 'docs/database/orm.md'));
        }

        if ($morphRelations !== []) {
            $report->add(new Finding('Models', 'Relations: ' . implode(', ', array_keys($morphRelations)), Status::Manual, array_sum($morphRelations), 'Relation::morphTo(name, [alias => Model::class]) and morphMany/morphOne(Model::class, name, alias): name the columns and list the type aliases; stored class names can be the aliases', [], 'docs/database/orm.md'));
        }

        if ($unsupportedRelations !== []) {
            $report->add(new Finding('Models', 'Relations: ' . implode(', ', array_keys($unsupportedRelations)), Status::Unsupported, array_sum($unsupportedRelations), 'only ' . implode(', ', $supported) . ' exist: model these with explicit queries or pivot models', [], 'docs/database/orm.md'));
        }

        $notes = [
            'SoftDeletes' => ['Soft deletes', Status::Manual, "protected const SOFT_DELETES = 'deleted_at'; withTrashed(), onlyTrashed(), restore(), forceDelete()"],
            'scopes' => ['Query scopes', Status::Manual, 'scope classes implementing Scope: apply() per query, globalScopes() on the model'],
            'accessors' => ['Accessors and mutators', Status::Unsupported, 'no attribute accessors: use CASTS or explicit methods'],
            'guarded' => ['$guarded', Status::Manual, 'only an allow-list exists: list the fields in FILLABLE'],
            'events' => ['Model events (booted, $dispatchesEvents)', Status::Manual, 'listen to ModelSaving, ModelSaved, ModelDeleting, ModelDeleted and ModelRestored with the event dispatcher'],
        ];

        foreach ($features as $key => $where) {
            if ($where !== []) {
                [$item, $status, $note] = $notes[$key];
                $report->add(new Finding('Models', $item, $status, count($where), $note, $this->examples($where), 'docs/database/orm.md'));
            }
        }
    }

    private function migrations(SourceTree $tree, Report $report): void
    {
        $files = $tree->under('database/migrations');

        if ($files === []) {
            return;
        }

        [$calls, $where] = $this->sum($tree, $files, static fn(array $tokens): array => Tokens::methodCallsOn($tokens, '$table'));
        $supported = Capabilities::schemaMethods();
        $missing = [];

        foreach ($calls as $method => $count) {
            if (!in_array(strtolower($method), $supported, true)) {
                $missing[$method] = $count;
            }
        }

        $report->add(new Finding('Database', 'Migrations', $missing === [] ? Status::Direct : Status::Manual, count($files), 'same Blueprint method names; change the class header to VeyroPHP\'s Migration and Schema', $this->examples($files), 'docs/database/migrations.md'));

        if ($missing !== []) {
            ksort($missing);
            $files = [];

            foreach (array_keys($missing) as $method) {
                $files = array_merge($files, $where[$method]);
            }

            $report->add(new Finding('Database', 'Schema methods without an equivalent: ' . implode(', ', array_map(static fn(string $m, int $c): string => "{$m} ({$c})", array_keys($missing), $missing)), Status::Unsupported, array_sum($missing), 'use a supported column type or a raw statement in the migration', $this->examples(array_values(array_unique($files))), 'docs/database/migrations.md'));
        }
    }

    private function views(SourceTree $tree, Report $report): void
    {
        $files = $tree->under('resources/views', '.blade.php');

        if ($files === []) {
            return;
        }

        $report->add(new Finding('Views', 'Blade templates', Status::Manual, count($files), 'rewrite as plain PHP templates: {{ $x }} becomes <?= $e($x) ?>', $this->examples($files), 'docs/getting-started/views.md'));

        $directives = $where = [];
        $components = [];

        foreach ($files as $file) {
            $code = $tree->read($file);
            preg_match_all('/(?<![\w@])@(\w+)/', $code, $m);

            foreach ($m[1] as $directive) {
                $key = strtolower($directive);

                if (str_starts_with($key, 'end') && isset(self::BLADE[substr($key, 3)])) {
                    continue;
                }

                if (isset(self::BLADE[$key])) {
                    $directives[$key] = ($directives[$key] ?? 0) + 1;
                    $where[$key][$file] = true;
                }
            }

            if (preg_match('/<x-[\w.-]+/', $code) === 1) {
                $components[] = $file;
            }
        }

        foreach ([Status::Direct, Status::Manual, Status::Unsupported] as $status) {
            $group = array_filter($directives, static fn(string $d): bool => self::BLADE[$d][0] === $status, ARRAY_FILTER_USE_KEY);

            if ($group !== []) {
                $files = [];

                foreach (array_keys($group) as $d) {
                    $files = array_merge($files, array_keys($where[$d] ?? []));
                }

                $report->add(new Finding('Views', 'Blade directives: ' . implode(', ', array_map(static fn(string $d): string => "@{$d} → " . self::BLADE[$d][1], array_keys($group))), $status, array_sum($group), $status === Status::Unsupported ? 'no equivalent in VeyroPHP' : 'template syntax changes; the concept exists', $this->examples(array_values(array_unique($files))), 'docs/getting-started/views.md'));
            }
        }

        if ($components !== []) {
            $report->add(new Finding('Views', 'Blade components <x-...>', Status::Manual, count($components), '$view->component("name", [...])', $this->examples($components), 'docs/getting-started/views.md'));
        }
    }

    private function facadesAndHelpers(SourceTree $tree, Report $report): void
    {
        $files = array_merge($tree->under('app'), $tree->under('routes'), $tree->under('database'), $tree->under('config'));
        [$static, $staticFiles] = $this->sum($tree, $files, static fn(array $tokens): array => Tokens::staticCalls($tokens, array_keys(self::FACADES)));
        $facades = $facadeFiles = [];

        foreach ($static as $call => $count) {
            $facade = (string) strstr($call, '::', true);
            $facades[$facade] = ($facades[$facade] ?? 0) + $count;
            $facadeFiles[$facade] = array_merge($facadeFiles[$facade] ?? [], $staticFiles[$call]);
        }

        ksort($facades);

        foreach ($facades as $facade => $count) {
            [$status, $note] = self::FACADES[$facade];
            $report->add(new Finding('Facades', "{$facade}::", $status, $count, $note, $this->examples($facadeFiles[$facade]), self::GUIDE));
        }

        [$functions, $where] = $this->sum($tree, array_merge($tree->under('app'), $tree->under('routes'), $tree->under('resources/views')), static fn(array $tokens): array => Tokens::functionCalls($tokens));
        $helpers = array_intersect_key($functions, self::HELPERS);
        ksort($helpers);

        foreach ($helpers as $helper => $count) {
            [$status, $note] = self::HELPERS[$helper];
            $report->add(new Finding('Helpers', "{$helper}()", $status, $count, $note, $this->examples($where[$helper] ?? []), self::GUIDE));
        }
    }

    private function environment(SourceTree $tree, Report $report): void
    {
        if (!$tree->exists('.env.example')) {
            return;
        }

        preg_match_all('/^\s*([A-Z][A-Z0-9_]*)\s*=/m', $tree->read('.env.example'), $m);
        $keys = array_values(array_unique($m[1]));
        $same = array_values(array_intersect($keys, self::ENV_SAME));
        $other = array_values(array_diff($keys, self::ENV_SAME));

        if ($same !== []) {
            $report->add(new Finding('Configuration', '.env keys with the same name: ' . implode(', ', $same), Status::Direct, count($same), 'copy the values into the new application\'s .env (read from .env.example only; .env is never read)', ['.env.example'], 'docs/getting-started/configuration.md'));
        }

        if ($other !== []) {
            $report->add(new Finding('Configuration', '.env keys to map by hand: ' . implode(', ', $other), Status::Manual, count($other), 'add them to config/*.php where the feature exists', ['.env.example'], 'docs/getting-started/configuration.md'));
        }
    }
}
