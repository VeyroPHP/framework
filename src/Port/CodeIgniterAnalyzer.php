<?php

declare(strict_types=1);

namespace VeyroPHP\Port;

/**
 * Reads a CodeIgniter 4 project (or recognizes a CodeIgniter 3 one) and maps
 * what it uses to VeyroPHP. Static only: JSON decoding and PHP tokens.
 */
final class CodeIgniterAnalyzer implements Analyzer
{
    use AnalyzesProjects;

    private const GUIDE = 'docs/migrating/from-codeigniter.md';

    private const ROUTE_DIRECT = ['get', 'post', 'put', 'patch', 'delete', 'options', 'match', 'group', 'add'];

    private const HELPERS = [
        'view' => [Status::Manual, 'inject View: $view->render("dir.name", $data)'],
        'esc' => [Status::Direct, '$e() in templates'],
        'site_url' => [Status::Manual, 'Router::path() plus the base path'],
        'base_url' => [Status::Manual, 'the application base path'],
        'route_to' => [Status::Manual, '$router->path("name", [...])'],
        'redirect' => [Status::Manual, 'ResponseFactory redirects'],
        'session' => [Status::Manual, 'inject Session'],
        'helper' => [Status::Manual, 'no helper loading: inject services'],
        'lang' => [Status::Manual, 'the Translator'],
        'old' => [Status::Manual, 'old input from the session'],
        'csrf_field' => [Status::Manual, 'the CSRF field from the session token'],
        'csrf_hash' => [Status::Manual, 'the session CSRF token'],
        'model' => [Status::Manual, 'the Orm service'],
        'service' => [Status::Manual, 'constructor injection'],
        'config' => [Status::Manual, 'inject Config'],
        'env' => [Status::Manual, 'only inside config/*.php ($env)'],
        'log_message' => [Status::Manual, 'inject LoggerInterface'],
        'cache' => [Status::Manual, 'inject the cache'],
    ];

    private const FOLDERS = [
        'app/Controllers' => ['Controllers', Status::Manual, 'plain classes with injected services; PSR-7 request instead of $this->request'],
        'app/Filters' => ['Filters', Status::Manual, 'rewrite as PSR-15 middleware'],
        'app/Entities' => ['Entities', Status::Manual, 'use models with CASTS'],
        'app/Libraries' => ['Libraries', Status::Manual, 'services registered in the container'],
        'app/Helpers' => ['Helper files', Status::Manual, 'no global helpers: move functions into services'],
        'app/Commands' => ['Spark commands', Status::Manual, '#[AsCommand] console commands'],
        'app/Database/Seeds' => ['Seeders', Status::Manual, 'Seeder classes'],
        'app/Language' => ['Language files', Status::Manual, 'lang/ translation files'],
        'tests' => ['Tests', Status::Manual, 'PHPUnit stays; HTTP tests use TestClient'],
    ];

    private const CI3_FOLDERS = [
        'application/controllers' => 'Controllers',
        'application/models' => 'Models',
        'application/views' => 'Views',
        'application/libraries' => 'Libraries',
        'application/helpers' => 'Helpers',
        'application/config' => 'Configuration',
        'application/migrations' => 'Migrations',
    ];

    public function name(): string
    {
        return 'codeigniter';
    }

    public function detects(SourceTree $tree): bool
    {
        return $this->requires($tree, 'codeigniter4/framework')
            || $this->requires($tree, 'codeigniter4/codeigniter4')
            || $tree->exists('spark') && $tree->exists('app/Config/Routes.php')
            || $tree->exists('system/core/CodeIgniter.php') || $tree->under('application/controllers') !== [];
    }

    public function analyze(SourceTree $tree): Report
    {
        $ci4 = $this->requires($tree, 'codeigniter4/framework') || $this->requires($tree, 'codeigniter4/codeigniter4') || $tree->exists('app/Config/Routes.php');

        if (!$ci4) {
            return $this->codeIgniter3($tree);
        }

        $version = $this->lockedVersion($tree, 'codeigniter4/framework') ?? $this->lockedVersion($tree, 'codeigniter4/codeigniter4');
        $report = new Report('codeigniter4', $version, $tree->root);

        if ($tree->truncated()) {
            $report->warn('The project has more files than the analyzer reads (20,000); counts are incomplete.');
        }

        $this->classifyPackages($tree, $report, self::GUIDE, static fn(string $name): ?array => match (true) {
            in_array($name, ['codeigniter4/framework', 'codeigniter4/codeigniter4'], true) => null,
            $name === 'codeigniter4/shield' => [Status::Manual, 'built-in authentication, API tokens and RBAC'],
            $name === 'codeigniter4/tasks' => [Status::Manual, 'routes/schedule.php'],
            in_array($name, ['phpunit/phpunit', 'fakerphp/faker', 'mikey179/vfsstream', 'mockery/mockery'], true) => [Status::Direct, 'framework-independent development tool'],
            str_starts_with($name, 'codeigniter4/') => [Status::Unsupported, 'part of the CodeIgniter ecosystem'],
            default => [Status::Direct, 'framework-independent Composer package'],
        }, 'codeigniter4/');

        $this->routes($tree, $report);
        $this->models($tree, $report);
        $this->migrations($tree, $report);
        $this->views($tree, $report);
        $this->helpers($tree, $report);
        $this->folders($tree, $report, self::FOLDERS, self::GUIDE);

        return $report;
    }

    private function routes(SourceTree $tree, Report $report): void
    {
        $files = array_values(array_filter([...$tree->under('app/Config'), ...$tree->under('app/Routes')], static fn(string $f): bool => str_contains($f, 'Routes')));
        [$calls, $where] = $this->sum($tree, $files, static fn(array $tokens): array => Tokens::methodCallsOn($tokens, '$routes'));

        $direct = $manual = $other = 0;
        $manualKinds = $manualFiles = $otherKinds = $otherFiles = [];

        foreach ($calls as $method => $count) {
            if (in_array(strtolower($method), self::ROUTE_DIRECT, true)) {
                $direct += $count;
            } elseif (in_array(strtolower($method), ['resource', 'presenter', 'cli', 'view', 'addredirect', 'environment', 'set404override', 'setautoroute', 'setdefaultcontroller', 'setdefaultmethod', 'setdefaultnamespace', 'settranslateuridashes'], true)) {
                $manual += $count;
                $manualKinds[] = $method;
                $manualFiles = array_merge($manualFiles, $where[$method]);
            } else {
                // Unknown to this analyzer: counted, so the report never hides a route call (CORE-15).
                $other += $count;
                $otherKinds[] = $method;
                $otherFiles = array_merge($otherFiles, $where[$method]);
            }
        }

        if ($other > 0) {
            $report->add(new Finding('Routes', 'Other $routes-> calls: ' . implode(', ', $otherKinds), Status::Manual, $other, 'not recognised by the analyzer: check what each call does and write the matching VeyroPHP routes', $this->examples($otherFiles), self::GUIDE));
        }

        if ($direct > 0) {
            $report->add(new Finding('Routes', 'Route definitions (get, post, group, ...)', Status::Direct, $direct, '$routes->get("users/(:num)", "Users::show/$1") becomes $router->get("/users/{id:\d+}", [Users::class, "show"])', $this->examples($files), self::GUIDE));
        }

        if ($manual > 0) {
            $report->add(new Finding('Routes', '$routes->' . implode(', $routes->', array_unique($manualKinds)), Status::Manual, $manual, 'no resource/presenter routes or auto-routing: define each route explicitly', $this->examples($manualFiles), self::GUIDE));
        }

        $autoRoute = false;

        foreach ([...$files, 'app/Config/Feature.php'] as $file) {
            $autoRoute = $autoRoute || preg_match('/setAutoRoute\(\s*true\s*\)|\$autoRoute\s*=\s*true/', $tree->read($file)) === 1;
        }

        if ($autoRoute) {
            $report->add(new Finding('Routes', 'Auto routing', Status::Unsupported, 1, 'every URL must be a defined route in VeyroPHP: list the controller methods that are reachable today', $this->examples($files), self::GUIDE));
        }
    }

    private function models(SourceTree $tree, Report $report): void
    {
        $files = array_values(array_filter($tree->under('app/Models'), fn(string $f): bool => preg_match('/\bextends\s+(\\\\?CodeIgniter\\\\)?Model\b/', $tree->read($f)) === 1));

        if ($files === []) {
            return;
        }

        $report->add(new Finding('Models', 'CodeIgniter models', Status::Manual, count($files), 'Model with TABLE/FILLABLE/TIMESTAMPS constants; queries through the Orm service; relations are built in', $this->examples($files), 'docs/database/orm.md'));

        $soft = $callbacks = $rules = [];

        foreach ($files as $file) {
            $code = $tree->read($file);

            if (preg_match('/\$useSoftDeletes\s*=\s*true/', $code) === 1) {
                $soft[] = $file;
            }
            if (preg_match('/\$(before|after)(Insert|Update|Find|Delete)\s*=\s*\[\s*[\'"]/', $code) === 1) {
                $callbacks[] = $file;
            }
            if (preg_match('/\$validationRules\s*=\s*\[\s*[\'"]/', $code) === 1) {
                $rules[] = $file;
            }
        }

        if ($soft !== []) {
            $report->add(new Finding('Models', 'Soft deletes ($useSoftDeletes)', Status::Manual, count($soft), "protected const SOFT_DELETES = 'deleted_at'; withTrashed(), onlyTrashed(), restore(), forceDelete()", $this->examples($soft), 'docs/database/orm.md'));
        }
        if ($callbacks !== []) {
            $report->add(new Finding('Models', 'Model callbacks ($beforeInsert, ...)', Status::Manual, count($callbacks), 'insert, update and delete callbacks become listeners for ModelSaving, ModelSaved, ModelDeleting and ModelDeleted; find callbacks have no equivalent', $this->examples($callbacks), 'docs/database/orm.md'));
        }
        if ($rules !== []) {
            $report->add(new Finding('Models', 'Validation rules in models', Status::Manual, count($rules), 'validate in the handler with Validator', $this->examples($rules), self::GUIDE));
        }
    }

    private function migrations(SourceTree $tree, Report $report): void
    {
        $files = $tree->under('app/Database/Migrations');

        if ($files !== []) {
            $report->add(new Finding('Database', 'Migrations (forge)', Status::Manual, count($files), 'rewrite with the schema builder: $table->string("name") instead of addField([...])', $this->examples($files), 'docs/database/migrations.md'));
        }
    }

    private function views(SourceTree $tree, Report $report): void
    {
        $files = $tree->under('app/Views');

        if ($files === []) {
            return;
        }

        $layouts = array_values(array_filter($files, fn(string $f): bool => preg_match('/\$this->(extend|section|renderSection|include)\(/', $tree->read($f)) === 1));
        $report->add(new Finding('Views', 'PHP views', Status::Manual, count($files), 'already plain PHP: rename esc() to $e(), view paths use dots', $this->examples($files), 'docs/getting-started/views.md'));

        if ($layouts !== []) {
            $report->add(new Finding('Views', 'View layouts ($this->extend, section, renderSection, include)', Status::Direct, count($layouts), '$view->layout(), section(), yield(), render()', $this->examples($layouts), 'docs/getting-started/views.md'));
        }
    }

    private function helpers(SourceTree $tree, Report $report): void
    {
        [$functions, $where] = $this->sum($tree, $tree->under('app'), static fn(array $tokens): array => Tokens::functionCalls($tokens));
        $helpers = array_intersect_key($functions, self::HELPERS);
        ksort($helpers);

        foreach ($helpers as $helper => $count) {
            [$status, $note] = self::HELPERS[$helper];
            $report->add(new Finding('Helpers', "{$helper}()", $status, $count, $note, $this->examples($where[$helper] ?? []), self::GUIDE));
        }
    }

    private function codeIgniter3(SourceTree $tree): Report
    {
        $version = null;

        if (preg_match("/define\\(\\s*'CI_VERSION'\\s*,\\s*'([^']+)'/", $tree->read('system/core/CodeIgniter.php'), $m) === 1) {
            $version = $m[1];
        }

        $report = new Report('codeigniter3', $version, $tree->root);
        $report->warn('CodeIgniter 3 has no namespaces or Composer structure: every area is a manual rewrite.');

        foreach (self::CI3_FOLDERS as $folder => $item) {
            $files = $tree->under($folder);

            if ($files !== []) {
                $report->add(new Finding('Structure', $item, Status::Manual, count($files), 'rewrite for VeyroPHP (see the CodeIgniter guide)', $this->examples($files), self::GUIDE));
            }
        }

        return $report;
    }
}
