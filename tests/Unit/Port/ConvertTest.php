<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Unit\Port;

use PHPUnit\Framework\TestCase;
use VeyroPHP\Port\Convert\ConvertedFile;
use VeyroPHP\Port\Convert\LaravelConverter;
use VeyroPHP\Port\Convert\MigrationConverter;
use VeyroPHP\Port\Convert\ModelConverter;
use VeyroPHP\Port\Convert\Php;
use VeyroPHP\Port\Convert\ProjectWriter;
use VeyroPHP\Port\Convert\RouteConverter;
use VeyroPHP\Port\Convert\Words;
use VeyroPHP\Port\PortException;
use VeyroPHP\Port\SourceTree;

final class ConvertTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/../../Fixtures/Port/laravel-app';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/veyrophp-convert-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/source', 0777, true);
        mkdir($this->dir . '/target/database/migrations', 0777, true);
        mkdir($this->dir . '/target/routes', 0777, true);
        file_put_contents($this->dir . '/target/veyro', "<?php\n");
        file_put_contents($this->dir . '/target/composer.json', '{"require": {"veyrophp/core": "0.1.x-dev"}}');
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($files as $file) {
            \assert($file instanceof \SplFileInfo);
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($this->dir);
    }

    private static function migration(string $up, string $down = ''): string
    {
        return "<?php\n\nuse Illuminate\\Database\\Migrations\\Migration;\nuse Illuminate\\Database\\Schema\\Blueprint;\nuse Illuminate\\Support\\Facades\\DB;\nuse Illuminate\\Support\\Facades\\Schema;\n\nreturn new class extends Migration\n{\n    public function up(): void\n    {\n{$up}\n    }\n"
            . ($down === '' ? '' : "\n    public function down(): void\n    {\n{$down}\n    }\n") . "};\n";
    }

    // Migrations

    public function testMigrationHeaderSignaturesAndFacade(): void
    {
        $file = (new MigrationConverter())->convert('database/migrations/a.php', self::migration(
            "        Schema::create('posts', function (Blueprint \$table) {\n            \$table->id();\n            \$table->string('title');\n        });",
            "        Schema::dropIfExists('posts');",
        ));

        self::assertTrue($file->parses());
        self::assertStringContainsString("use VeyroPHP\\Database\\Migration\\Migration;\nuse VeyroPHP\\Database\\Schema\\Blueprint;", $file->content);
        self::assertStringContainsString('public function up(Schema $schema): void', $file->content);
        self::assertStringContainsString('public function down(Schema $schema): void', $file->content);
        self::assertStringContainsString("\$schema->create('posts', static function (Blueprint \$table): void {", $file->content);
        self::assertStringContainsString("\$schema->dropIfExists('posts');", $file->content);
        self::assertStringContainsString(ConvertedFile::MARKER, $file->content);
    }

    public function testMissingDownIsGeneratedFromCreatedTables(): void
    {
        $file = (new MigrationConverter())->convert('m.php', self::migration("        Schema::create('a', fn (Blueprint \$t) => \$t->id());\n        Schema::create('b', fn (Blueprint \$t) => \$t->id());"));

        self::assertStringContainsString("    public function down(Schema \$schema): void\n    {\n        \$schema->dropIfExists('b');\n        \$schema->dropIfExists('a');\n    }", $file->content);
        self::assertContains('down() was missing: generated from the tables up() creates', $file->todos);
    }

    public function testUnsupportedColumnsAndModifiers(): void
    {
        $file = (new MigrationConverter())->convert('m.php', self::migration(implode("\n", [
            "        Schema::table('users', function (Blueprint \$table) {",
            "            \$table->uuid('token');",
            "            \$table->string('code')->nullable()->comment('Postal (old) code')->unique();",
            "            \$table->foreignId('team_id')->constrained()->cascadeOnDelete();",
            "            \$table->foreignId('category_id')->constrained();",
            '        });',
        ])));

        self::assertTrue($file->parses());
        self::assertStringContainsString("// TODO(port): uuid() has no VeyroPHP equivalent; choose a supported column type: \$table->uuid('token');", $file->content);
        self::assertStringContainsString("            // TODO(port): removed comment(): no VeyroPHP equivalent\n            \$table->string('code')->nullable()->unique();", $file->content, 'comment() with parentheses inside its string is removed whole.');
        self::assertStringContainsString("\$table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();", $file->content);
        self::assertStringContainsString("\$table->foreignId('category_id')->constrained('categories');", $file->content);
    }

    public function testDataChangesUseTheMigrationConnection(): void
    {
        $file = (new MigrationConverter())->convert('m.php', self::migration(implode("\n", [
            "        DB::table('posts')->whereNull('listed_at')->update(['listed_at' => DB::raw('created_at')]);",
            "        foreach (DB::table('posts')->select(['id'])->orderBy('id')->cursor() as \$row) {}",
            '        DB::transaction(fn () => null);',
        ])));

        self::assertStringContainsString("\$schema->connection()->table('posts')->whereNull('listed_at')->update(['listed_at' => new \\VeyroPHP\\Database\\Query\\Expression('created_at')]);", $file->content);
        self::assertStringContainsString("\$schema->connection()->table('posts')->select(['id'])->lazyById()", $file->content);
        self::assertStringContainsString('// TODO(port): DB::transaction() has no automatic equivalent', $file->content);
        self::assertStringContainsString('// TODO(port): Illuminate\Support\Facades\DB is a Laravel class', $file->content);
    }

    // Models

    public function testModelSkeleton(): void
    {
        $code = <<<'PHP'
<?php

namespace App\Models;

use App\Enums\Status;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Other\Tag;

class BlogPost extends Model
{
    use HasFactory;

    protected $fillable = ['title', 'body'];
    protected $hidden = ['secret'];
    public $timestamps = false;

    protected function casts(): array
    {
        return ['published_at' => 'datetime', 'flags' => 'array', 'price' => 'decimal:2', 'status' => Status::class, 'password' => 'hashed'];
    }

    public function author() { return $this->belongsTo(User::class); }
    public function comments() { return $this->hasMany(Comment::class); }
    public function tags() { return $this->belongsToMany(Tag::class)->withTimestamps(); }
    public function cover() { return $this->hasOne(Image::class, 'post_id'); }
    public function scopePublished($q) { return $q; }
}
PHP;
        $file = (new ModelConverter())->convert('app/Models/BlogPost.php', $code);
        self::assertNotNull($file);
        self::assertTrue($file->parses());
        self::assertSame('app/Models/BlogPost.php', $file->target);

        foreach ([
            "protected const TABLE = 'blog_posts';",
            "protected const FILLABLE = ['title', 'body'];",
            "protected const HIDDEN = ['secret'];",
            "protected const CASTS = ['published_at' => 'datetime', 'flags' => 'json', 'price' => 'string'];",
            'protected const TIMESTAMPS = false;',
            "'author' => Relation::belongsTo(User::class, 'author_id'),",
            "'comments' => Relation::hasMany(Comment::class, 'blog_post_id'),",
            "'tags' => Relation::belongsToMany(Tag::class, 'blog_post_tag', 'blog_post_id', 'tag_id'),",
            "'cover' => Relation::hasOne(Image::class, 'post_id'),",
            'use Other\Tag;',
        ] as $expected) {
            self::assertStringContainsString($expected, $file->content);
        }

        $todos = implode("\n", $file->todos);
        self::assertStringContainsString('cast of "status" to Status (enum or custom cast) is not supported', $todos);
        self::assertStringContainsString('"password" was cast as hashed', $todos);
        self::assertStringContainsString('relation tags(): chained ->withTimestamps() was dropped', $todos);
        self::assertStringContainsString('trait HasFactory dropped', $todos);
        self::assertStringContainsString('not converted (port by hand into a service or the model): scopePublished()', $todos);
        self::assertStringNotContainsString('use App\Enums\Status;', $file->content);
    }

    public function testFixtureModels(): void
    {
        $user = (new ModelConverter())->convert('app/Models/User.php', (string) file_get_contents(self::FIXTURE . '/app/Models/User.php'));
        self::assertNotNull($user);
        self::assertStringContainsString("'posts' => Relation::hasMany(Post::class, 'user_id'),", $user->content);
        self::assertStringContainsString('relation images() uses morphMany(): write Relation::morphMany() by hand', implode("\n", $user->todos));
        self::assertStringContainsString("trait SoftDeletes dropped: add protected const SOFT_DELETES = 'deleted_at'", implode("\n", $user->todos));
        self::assertNull((new ModelConverter())->convert('x.php', '<?php function helper() {}'));
    }

    // Routes

    public function testRoutes(): void
    {
        $code = <<<'PHP'
<?php

use App\Http\Controllers\PhotoController;
use App\Support\Menu;
use Illuminate\Support\Facades\Route;

Route::get('users/', 'UserController@index');
Route::match(['get', 'post'], '/search', [PhotoController::class, 'search'])->whereNumber('page');
Route::view('/about', 'pages/about', ['x' => 1]);
Route::permanentRedirect('/old', '/new');
Route::apiResource('photos', PhotoController::class)->except(['destroy']);
Route::get('/posts/{post:slug}', [PhotoController::class, 'show'])->can('view');
Route::prefix('admin')->name('admin.')->middleware(['auth', 'verified', 'throttle:6,1'])->group(function () {
    Route::get('/', [PhotoController::class, 'dashboard'])->name('home');
    Route::group(['prefix' => 'reports', 'as' => 'reports.'], function () {
        Route::post('export', [PhotoController::class, 'export'])->name('export');
    });
});
Route::fallback(fn () => 'missing');
PHP;
        $file = (new RouteConverter())->convert('routes/web.php', $code);
        self::assertTrue($file->parses(), $file->content);
        self::assertSame('routes/ported/web.php', $file->target);

        foreach ([
            "\$router->get('/users', [\\App\\Http\\Controllers\\UserController::class, 'index']);",
            "\$router->map(['GET', 'POST'], '/search', [PhotoController::class, 'search'])->where('page', '\\\\d+');",
            "\$router->get('/about', static fn (\\VeyroPHP\\View\\View \$view): string => \$view->render('pages.about', ['x' => 1]));",
            "\$router->get('/old', static fn (\\VeyroPHP\\Http\\ResponseFactory \$response) => \$response->redirect('/new', 301));",
            "\$router->get('/photos', [PhotoController::class, 'index'])->name('photos.index');",
            "\$router->map(['PUT', 'PATCH'], '/photos/{photo}', [PhotoController::class, 'update'])->name('photos.update');",
            "\$router->get('/posts/{post}', [PhotoController::class, 'show']);",
            "\$router->group('/admin', static function (Router \$router): void {",
            "    \$router->group('/reports', static function (Router \$router): void {",
            "        \$router->post('/export', [PhotoController::class, 'export'])->name('export');",
            "    }, namePrefix: 'reports.');",
            "}, middleware: [\\VeyroPHP\\Auth\\Authenticate::class, \\VeyroPHP\\Auth\\Verification\\EnsureEmailIsVerified::class], namePrefix: 'admin.');",
            "// TODO(port): Route::fallback() has no VeyroPHP equivalent: Route::fallback(fn () => 'missing');",
            'use VeyroPHP\Routing\Router;',
        ] as $expected) {
            self::assertStringContainsString($expected, $file->content);
        }

        self::assertStringNotContainsString("'photos.destroy'", $file->content);
        self::assertStringNotContainsString("'photos.create'", $file->content, 'apiResource has no create/edit');
        $todos = implode("\n", $file->todos);
        self::assertStringContainsString("handler 'UserController@index' assumed in App\\Http\\Controllers", $todos);
        self::assertStringContainsString('{post:slug} looked the model up by slug', $todos);
        self::assertStringContainsString('->can() was dropped from a route', $todos);
        self::assertStringContainsString("middleware 'throttle:6,1' has no automatic equivalent", $todos);
        self::assertStringContainsString('uses App\Support\Menu: port that class too', $todos);
        self::assertStringContainsString('// TODO(port): uses App\Support\Menu', $file->content, 'File-level notes are in the header.');
    }

    /**
     * CORE-3: models keep their place in the source tree, so two models with one short
     * name do not overwrite each other, and each file still matches its namespace.
     */
    public function testModelsKeepTheirPathAndNamespace(): void
    {
        $models = [
            'app/Models/User.php' => 'App\Models',
            'app/Models/Admin/User.php' => 'App\Models\Admin',
            'app/User.php' => 'App',
        ];

        foreach ($models as $path => $namespace) {
            @mkdir(dirname($this->dir . '/source/' . $path), 0777, true);
            file_put_contents($this->dir . '/source/' . $path, "<?php\nnamespace {$namespace};\nuse Illuminate\\Database\\Eloquent\\Model;\nclass User extends Model\n{\n    protected \$fillable = ['name'];\n}\n");
        }

        [$files] = (new LaravelConverter())->convert(new SourceTree($this->dir . '/source'), ['models']);
        $targets = [];

        foreach ($files as $file) {
            $targets[$file->target] = $file->content;
        }

        ksort($targets);
        self::assertSame(['app/Models/Admin/User.php', 'app/Models/User.php', 'app/User.php'], array_keys($targets));

        foreach ($models as $path => $namespace) {
            self::assertStringContainsString("namespace {$namespace};", $targets[$path]);
        }
    }

    /**
     * CORE-1: each "}" is judged by what follows it, not by what follows the file's first "}".
     */
    public function testStatementsAfterATopLevelBlockAreConverted(): void
    {
        $code = "<?php\nuse Illuminate\\Support\\Facades\\Route;\n"
            . "Route::group(['prefix' => 'a'], function () {\n    Route::get('/x', 'A@x');\n});\n"
            . "if (app()->isLocal()) {\n    Route::get('/debug', 'D@show');\n}\n"
            . "Route::get('/after', 'B@after');\n"
            . "foreach (['p', 'q'] as \$page) {\n    Route::view(\$page, \$page);\n}\n"
            . "Route::post('/last', 'B@last');\n";

        $content = (new RouteConverter())->convert('routes/web.php', $code)->content;

        self::assertStringContainsString("\$router->get('/after', [\\App\\Http\\Controllers\\B::class, 'after'])", $content);
        self::assertStringContainsString("\$router->post('/last', [\\App\\Http\\Controllers\\B::class, 'last'])", $content);
        self::assertTrue((new \VeyroPHP\Port\Convert\ConvertedFile('route', 'x', 'x', $content, []))->parses());
    }

    public function testApiRoutesAreWrappedInApiPrefix(): void
    {
        $file = (new RouteConverter())->convert('routes/api.php', "<?php\nRoute::get('/user', fn () => []);\n");

        self::assertTrue($file->parses());
        self::assertStringContainsString("\$router->group('/api', static function (Router \$router): void {\n    \$router->get('/user', fn () => []);", $file->content);
    }

    public function testMultiLineNotesStayComments(): void
    {
        $file = (new RouteConverter())->convert('routes/web.php', "<?php\nRoute::withoutMiddleware([\n    A::class,\n    B::class,\n])->group(function () {\n    Route::get('/', fn () => 'x');\n});\n");

        self::assertTrue($file->parses(), $file->content);
        self::assertStringContainsString('// TODO(port): group ->withoutMiddleware([ A::class, B::class, ]) was dropped', $file->content);
    }

    // Helpers and writer

    public function testLiteralsAndWords(): void
    {
        self::assertSame(['1', '[2, 3]', 'fn () => f(4, 5)'], Php::splitArgs('1, [2, 3], fn () => f(4, 5)'));
        self::assertSame([false, null], Php::literal('$x'));
        self::assertNull(Php::string('"a $b"'), 'Interpolated strings are not literals.');
        self::assertSame("['a' => 1, 'b' => [true, null]]", Php::export(Php::literal("['a' => 1, 'b' => [true, null]]")[1]));
        self::assertSame(['categories', 'boxes', 'users', 'category', 'box'], [Words::plural('category'), Words::plural('box'), Words::plural('user'), Words::singular('categories'), Words::singular('boxes')]);
        self::assertSame('blog_post', Words::snake('BlogPost'));
    }

    public function testWriterRefusesUnsafeTargets(): void
    {
        foreach ([
            [$this->dir . '/missing', $this->dir . '/source', 'is not a directory'],
            [$this->dir . '/source', $this->dir . '/source', 'is not a VeyroPHP application'],
            [$this->dir . '/target', $this->dir, 'may not be inside the source project'],
        ] as [$target, $source, $message]) {
            try {
                new ProjectWriter($target, $source);
                self::fail("Expected: {$message}");
            } catch (PortException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }

        $writer = new ProjectWriter($this->dir . '/target', $this->dir . '/source');
        $this->expectException(PortException::class);
        $writer->write(new ConvertedFile('x', 'a', '../escape.php', '<?php', []), false);
    }

    public function testWriterNeverReplacesOtherFiles(): void
    {
        $writer = new ProjectWriter($this->dir . '/target', $this->dir . '/source');
        $own = $this->dir . '/target/routes/web.php';
        file_put_contents($own, "<?php // the developer's file\n");
        $converted = new ConvertedFile('routes', 'routes/web.php', 'routes/web.php', '<?php // ' . ConvertedFile::MARKER . "\n", []);

        self::assertSame('exists', $writer->write($converted, false));
        self::assertSame('exists', $writer->write($converted, true), '--force never replaces files the converter did not write.');
        self::assertSame("<?php // the developer's file\n", file_get_contents($own));

        $new = new ConvertedFile('routes', 'routes/web.php', 'routes/ported/web.php', '<?php // ' . ConvertedFile::MARKER . " v1\n", []);
        self::assertSame('written', $writer->write($new, false));
        self::assertSame('exists', $writer->write($new, false));
        self::assertSame('replaced', $writer->write(new ConvertedFile('routes', 'routes/web.php', 'routes/ported/web.php', '<?php // ' . ConvertedFile::MARKER . " v2\n", []), true));
        self::assertStringContainsString('v2', (string) file_get_contents($this->dir . '/target/routes/ported/web.php'));
    }

    public function testWriterFindsTableAndRouteConflicts(): void
    {
        file_put_contents($this->dir . '/target/database/migrations/2026_01_01_000000_create_users_table.php', "<?php \$schema->create('users', fn () => null);");
        file_put_contents($this->dir . '/target/routes/web.php', "<?php\n\$router->get('/', fn () => 'x')->name('home');\n\$router->group('/admin', static function (Router \$router): void {\n    \$router->get('/', fn () => 'a');\n});\n");
        $writer = new ProjectWriter($this->dir . '/target', $this->dir . '/source');

        $migration = new ConvertedFile('migration', 'm.php', 'database/migrations/0001_create_users_table.php', "<?php \$schema->create('users', fn () => null); \$schema->create('posts', fn () => null);", []);
        self::assertSame(['users' => ['database/migrations/0001_create_users_table.php', 'database/migrations/2026_01_01_000000_create_users_table.php']], $writer->tableConflicts([$migration]));

        $routes = (new RouteConverter())->convert('routes/web.php', "<?php\nRoute::get('/', fn () => 'y')->name('home');\nRoute::prefix('admin')->group(function () { Route::get('/', fn () => 'b'); Route::get('/new', fn () => 'c'); });\n");
        self::assertSame([
            'GET / is defined in routes/ported/web.php and in routes/web.php',
            'GET /admin is defined in routes/ported/web.php and in routes/web.php',
            'route name "home" is used in routes/ported/web.php and in routes/web.php',
        ], $writer->routeConflicts([$routes]));
    }
}
