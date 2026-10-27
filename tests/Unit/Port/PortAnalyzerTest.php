<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Unit\Port;

use PHPUnit\Framework\TestCase;
use VeyroPHP\Port\CodeIgniterAnalyzer;
use VeyroPHP\Port\Finding;
use VeyroPHP\Port\LaravelAnalyzer;
use VeyroPHP\Port\PortAnalyzer;
use VeyroPHP\Port\PortException;
use VeyroPHP\Port\Report;
use VeyroPHP\Port\SourceTree;
use VeyroPHP\Port\Status;
use VeyroPHP\Port\Tokens;

/**
 * The analyzer reads fake projects in tests/Fixtures/Port. Each fixture
 * contains app/Evil.php, which would create a marker file if it were ever
 * executed, and an .env with a fake secret that must never be read.
 */
final class PortAnalyzerTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../Fixtures/Port';

    private const MARKER = 'veyrophp-port-executed';

    protected function setUp(): void
    {
        @unlink(sys_get_temp_dir() . '/' . self::MARKER);
    }

    private static function find(Report $report, string $area, string $itemStart): Finding
    {
        foreach ($report->findings() as $finding) {
            if ($finding->area === $area && str_starts_with($finding->item, $itemStart)) {
                return $finding;
            }
        }

        self::fail("No finding {$area} / {$itemStart}. Found: " . implode('; ', array_map(static fn(Finding $f): string => "{$f->area} / {$f->item}", $report->findings())));
    }

    private static function assertNeverExecutedNorLeaked(Report $report): void
    {
        self::assertFileDoesNotExist(sys_get_temp_dir() . '/' . self::MARKER, 'Project code must never run.');
        self::assertStringNotContainsString('fixture-secret-never-read', (string) json_encode($report->toArray()));
    }

    public function testLaravelProject(): void
    {
        $report = (new PortAnalyzer())->analyze(self::FIXTURES . '/laravel-app');

        self::assertSame('laravel', $report->framework);
        self::assertSame('v12.1.0', $report->version);
        self::assertNeverExecutedNorLeaked($report);

        // Routes: get/post/middleware are direct; resource is manual; vendor/ and comments are ignored.
        $direct = self::find($report, 'Routes', 'Route definitions');
        self::assertSame(Status::Direct, $direct->status);
        self::assertSame(5, $direct->count); // get x3, post, middleware
        $manual = self::find($report, 'Routes', 'Route::resource');
        self::assertSame(1, $manual->count);
        self::assertSame(['routes/web.php'], $manual->files);
        self::assertSame(1, self::find($report, 'Routes', 'Optional route parameters')->count);

        // Models
        self::assertSame(2, self::find($report, 'Models', 'Eloquent models')->count);
        $relations = self::find($report, 'Models', 'Relations: belongsTo');
        self::assertSame(Status::Direct, $relations->status);
        self::assertSame('Relations: belongsTo, hasMany', $relations->item); // files are read in sorted order
        // Morph relations exist, but need their aliases written by hand.
        self::assertSame(Status::Manual, self::find($report, 'Models', 'Relations: morphMany')->status);
        self::assertSame(['app/Models/User.php'], self::find($report, 'Models', 'Soft deletes')->files);
        self::assertSame(Status::Manual, self::find($report, 'Models', 'Soft deletes')->status);
        self::assertSame(Status::Manual, self::find($report, 'Models', 'Query scopes')->status);
        self::assertSame(Status::Unsupported, self::find($report, 'Models', 'Accessors and mutators')->status);
        self::assertSame(['app/Models/Post.php'], self::find($report, 'Models', '$guarded')->files);

        // Migrations: methods the schema builder lacks are listed by name, from reflection.
        self::assertSame(Status::Manual, self::find($report, 'Database', 'Migrations')->status);
        $missing = self::find($report, 'Database', 'Schema methods without an equivalent');
        self::assertSame('Schema methods without an equivalent: morphs (1), uuid (1)', $missing->item);

        // Views
        self::assertSame(1, self::find($report, 'Views', 'Blade templates')->count);
        self::assertStringContainsString('@extends → $view->layout()', self::find($report, 'Views', 'Blade directives: @extends')->item);
        self::assertSame('Blade directives: @vite → no asset pipeline integration', self::find($report, 'Views', 'Blade directives: @vite')->item);
        self::assertStringContainsString('@csrf', self::find($report, 'Views', 'Blade directives: @csrf')->item);
        self::assertSame(1, self::find($report, 'Views', 'Blade components')->count);

        // Facades and helpers
        self::assertSame(Status::Unsupported, self::find($report, 'Facades', 'Http::')->status);
        self::assertSame(Status::Manual, self::find($report, 'Facades', 'Cache::')->status);
        self::assertSame(['app/Http/Controllers/UserController.php'], self::find($report, 'Facades', 'DB::')->files);
        self::assertSame(2, self::find($report, 'Helpers', 'view()')->count);
        self::assertSame(Status::Direct, self::find($report, 'Helpers', 'collect()')->status);

        // Packages
        self::assertSame(Status::Direct, self::find($report, 'Packages', 'laravel/sanctum')->status);
        self::assertSame(Status::Unsupported, self::find($report, 'Packages', 'spatie/laravel-permission')->status);
        self::assertSame(Status::Unsupported, self::find($report, 'Packages', 'livewire/livewire')->status);
        $illuminate = self::find($report, 'Packages', 'acme/needs-illuminate');
        self::assertSame(Status::Manual, $illuminate->status, 'A package that depends on illuminate/* is not called framework-independent.');
        self::assertSame('depends on illuminate/database: check whether it works without the framework', $illuminate->note);
        self::assertSame(Status::Direct, self::find($report, 'Packages', 'guzzlehttp/guzzle')->status);

        // .env.example only
        self::assertSame('.env keys with the same name: APP_NAME, APP_KEY, DB_HOST', self::find($report, 'Configuration', '.env keys with the same name')->item);
        self::assertSame('.env keys to map by hand: CACHE_STORE', self::find($report, 'Configuration', '.env keys to map by hand')->item);
    }

    public function testCodeIgniter4Project(): void
    {
        $report = (new PortAnalyzer())->analyze(self::FIXTURES . '/ci4-app');

        self::assertSame('codeigniter4', $report->framework);
        self::assertSame('v4.5.1', $report->version);
        self::assertNeverExecutedNorLeaked($report);

        self::assertSame(5, self::find($report, 'Routes', 'Route definitions')->count); // get x3, post, group
        self::assertSame('$routes->resource, $routes->setAutoRoute', self::find($report, 'Routes', '$routes->')->item);
        self::assertSame(Status::Unsupported, self::find($report, 'Routes', 'Auto routing')->status);
        // CORE-15: calls the analyzer does not know are counted, not dropped.
        $other = self::find($report, 'Routes', 'Other $routes-> calls');
        self::assertSame([Status::Manual, 2, 'Other $routes-> calls: addPlaceholder, map'], [$other->status, $other->count, $other->item]);
        self::assertSame(1, self::find($report, 'Models', 'CodeIgniter models')->count);
        self::assertSame(Status::Manual, self::find($report, 'Models', 'Soft deletes')->status);
        self::assertSame(Status::Manual, self::find($report, 'Models', 'Model callbacks')->status);
        self::assertSame(Status::Manual, self::find($report, 'Models', 'Validation rules in models')->status);
        self::assertSame(1, self::find($report, 'Database', 'Migrations (forge)')->count);
        self::assertSame(Status::Direct, self::find($report, 'Views', 'View layouts')->status);
        self::assertSame(Status::Direct, self::find($report, 'Helpers', 'esc()')->status);
        self::assertSame(Status::Manual, self::find($report, 'Helpers', 'site_url()')->status);
        self::assertSame(1, self::find($report, 'Structure', 'Filters')->count);
        self::assertSame(Status::Manual, self::find($report, 'Packages', 'codeigniter4/shield')->status);
    }

    public function testCodeIgniter3Project(): void
    {
        $report = (new PortAnalyzer())->analyze(self::FIXTURES . '/ci3-app', 'codeigniter');

        self::assertSame('codeigniter3', $report->framework);
        self::assertSame('3.1.13', $report->version);
        self::assertSame(['CodeIgniter 3 has no namespaces or Composer structure: every area is a manual rewrite.'], $report->warnings());
        self::assertSame(['direct' => 0, 'manual' => 3, 'unsupported' => 0], $report->summary());
    }

    public function testUnknownOrWrongFramework(): void
    {
        foreach ([
            [self::FIXTURES . '/plain-app', 'auto', 'Could not recognize the framework'],
            [self::FIXTURES . '/ci4-app', 'laravel', 'does not look like a laravel project'],
            [self::FIXTURES . '/laravel-app', 'symfony', 'Unknown framework "symfony"'],
            [self::FIXTURES . '/missing', 'auto', 'is not a directory'],
        ] as [$path, $from, $message]) {
            try {
                (new PortAnalyzer())->analyze($path, $from);
                self::fail("Expected an error for {$path} ({$from}).");
            } catch (PortException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    public function testSourceTreeNeverReadsSecretsOrDependencies(): void
    {
        $tree = new SourceTree(self::FIXTURES . '/laravel-app');

        self::assertNotContains('.env', $tree->files());
        self::assertContains('.env.example', $tree->files());
        self::assertSame([], array_values(array_filter($tree->files(), static fn(string $f): bool => str_starts_with($f, 'vendor/'))));

        $this->expectException(PortException::class);
        $tree->read('.env');
    }

    public function testDetectionUsesComposerAndFolders(): void
    {
        self::assertTrue((new LaravelAnalyzer())->detects(new SourceTree(self::FIXTURES . '/laravel-app')));
        self::assertFalse((new LaravelAnalyzer())->detects(new SourceTree(self::FIXTURES . '/ci4-app')));
        self::assertTrue((new CodeIgniterAnalyzer())->detects(new SourceTree(self::FIXTURES . '/ci3-app')));
    }

    public function testTokensIgnoreCommentsAndCountChains(): void
    {
        $tokens = token_get_all("<?php\n// Route::view('/x');\nRoute::get('/a', 'A');\nRoute::match(['get'], '/m', 'M');\n\$table->string('a')->nullable()->default('x');\n\$table->id();\nenv('A'); \$x->env('B'); Foo::env('C'); function env() {}\n");

        self::assertSame(['Route::get' => 1, 'Route::match' => 1], Tokens::staticCalls($tokens, ['Route']), 'match is a keyword token but a valid method name');
        self::assertSame(['string' => 1, 'nullable' => 1, 'default' => 1, 'id' => 1], Tokens::methodCallsOn($tokens, '$table'));
        self::assertSame(['env' => 1], Tokens::functionCalls($tokens));
    }

    public function testReportNoticeIsHonest(): void
    {
        $data = (new PortAnalyzer())->analyze(self::FIXTURES . '/laravel-app')->toArray();

        self::assertSame(1, $data['schema']);
        self::assertSame(Report::NOTICE, $data['notice']);
        self::assertStringContainsString('nothing was converted', Report::NOTICE);
        self::assertStringContainsString('not when the code parses', Report::NOTICE);
    }
}
