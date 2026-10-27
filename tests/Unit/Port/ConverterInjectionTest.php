<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Unit\Port;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VeyroPHP\Port\Convert\BladeConverter;
use VeyroPHP\Port\Convert\ControllerConverter;
use VeyroPHP\Port\Convert\MigrationConverter;
use VeyroPHP\Port\Convert\ModelConverter;
use VeyroPHP\Port\Convert\Php;
use VeyroPHP\Port\Convert\RouteConverter;
use VeyroPHP\Port\Patterns;

/**
 * CORE-2: strings from an untrusted source project must stay data in the converted
 * project. Generated code is only parsed here, never run: each file must parse, must
 * not leave PHP mode, and the payload's function name must never appear as code
 * (only inside string literals or comments).
 */
#[CoversClass(ModelConverter::class)]
#[CoversClass(RouteConverter::class)]
#[CoversClass(MigrationConverter::class)]
#[CoversClass(ControllerConverter::class)]
#[CoversClass(BladeConverter::class)]
#[CoversClass(Php::class)]
final class ConverterInjectionTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function payloads(): iterable
    {
        yield 'quote' => ["x', veyro_pwned(), 'y"];
        yield 'backslash before quote' => ['x\\\', veyro_pwned(), \''];
        yield 'newline' => ["x\nveyro_pwned();\n//"];
        yield 'carriage return' => ["x\rveyro_pwned();\r"];
        yield 'close tag' => ['?><?php veyro_pwned(); ?>'];
        yield 'end of block comment' => ['*/ veyro_pwned(); /*'];
        yield 'interpolation' => ['${veyro_pwned()} {$x}'];
        yield 'unicode' => ['जयपुर_ü ✓ veyro_pwned()'];
        yield 'regex backreference' => ['$0 \\1 ${1} $2' . "\n" . 'veyro_pwned();'];
    }

    public function testMultiLineLiteralsAreCommentedOutWhole(): void
    {
        $code = "<?php\n\nuse Illuminate\\Database\\Migrations\\Migration;\nuse Illuminate\\Support\\Facades\\Schema;\n\nreturn new class extends Migration\n{\n    public function up(): void\n    {\n"
            . "        Schema::create('places', function (Blueprint \$table) {\n"
            . "            \$table->geometry(\"a\nveyro_pwned();\n\");\n"
            . "            \$table->point(<<<EOT\n            x\n            veyro_pwned();\n            EOT);\n"
            . "        });\n    }\n};\n";

        $file = (new MigrationConverter())->convert('database/migrations/2026_01_01_000000_create_places.php', $code);

        self::assertInert($file->content, 'migration');
    }

    public function testLinesNeverSplitAStringLiteral(): void
    {
        self::assertSame(
            ['<?php', "\$a = 'x\ny';", "\$b = \"p\nq\";", "\$c = <<<EOT\n  h\n  EOT;", ''],
            Php::lines("<?php\n\$a = 'x\ny';\n\$b = \"p\nq\";\n\$c = <<<EOT\n  h\n  EOT;\n"),
        );
    }

    public function testCreatedTablesAreReadTheSameWayEverywhere(): void
    {
        $code = "\$schema->create('users', ...);\nSchema::create(\"posts\", ...);\n\$this->db->create('logs');\n\$other->create( 'tags' );";

        self::assertSame(['users', 'logs', 'tags'], Patterns::createdTables($code));
        self::assertSame(['users'], Patterns::createdTables($code, '$schema'));
        self::assertSame(1, preg_match(Patterns::ELOQUENT_MODEL, 'class Post extends \Illuminate\Database\Eloquent\Model {'));
        self::assertSame(1, preg_match(Patterns::ELOQUENT_MODEL, 'class User extends Authenticatable'));
        self::assertSame(0, preg_match(Patterns::ELOQUENT_MODEL, 'class Post extends BaseModel'));
    }

    public function testMatchingParenSkipsNestingAndQuotedParens(): void
    {
        $call = 'f(a(b), ")", \'\\\')\', c)';
        $text = $call . ' + g(';

        self::assertSame(strlen($call) - 1, Php::matchingParen($text, 1));
        self::assertSame(5, Php::matchingParen($text, 3));
        self::assertNull(Php::matchingParen($text, strlen($text) - 1), 'not closed');
    }

    private static function assertInert(string $php, string $what): void
    {
        try {
            $tokens = token_get_all($php, TOKEN_PARSE);
        } catch (\ParseError $e) {
            self::fail($what . ' does not parse: ' . $e->getMessage() . "\n" . $php);
        }

        foreach ($tokens as $index => $token) {
            if (!is_array($token)) {
                continue;
            }

            self::assertFalse($index > 0 && $token[0] === T_INLINE_HTML, $what . ' leaves PHP mode at line ' . $token[2] . ":\n" . $php);
            self::assertFalse($token[0] === T_STRING && $token[1] === 'veyro_pwned', $what . ' turned the payload into code at line ' . $token[2] . ":\n" . $php);
        }
    }

    /**
     * The payload appears as the exact string literal var_export() would write.
     */
    private static function assertLiteral(string $php, string $payload, string $what): void
    {
        self::assertStringContainsString(var_export($payload, true), $php, $what . ' keeps the value as an exact literal');
    }

    #[DataProvider('payloads')]
    public function testModelRelationKeysStayLiterals(string $payload): void
    {
        $p = var_export($payload, true);
        $code = <<<PHP
            <?php
            namespace App\\Models;
            use Illuminate\\Database\\Eloquent\\Model;
            class Post extends Model
            {
                protected \$casts = [{$p} => 'encrypted'];
                public function author() { return \$this->belongsTo(User::class, {$p}, {$p}); }
                public function comments() { return \$this->hasMany(Comment::class, {$p}); }
                public function tags() { return \$this->belongsToMany(Tag::class, {$p}, {$p}, {$p}); }
            }
            PHP;

        $file = (new ModelConverter())->convert('app/Models/Post.php', $code);

        self::assertNotNull($file);
        self::assertInert($file->content, 'model');

        // Either the relations were converted with the key as an exact literal, or the
        // converter declined them and left them to the developer: both keep it data.
        if (str_contains($file->content, 'Relation::')) {
            self::assertLiteral($file->content, $payload, 'relation key');
        } else {
            self::assertStringContainsString('not converted', $file->content);
        }
    }

    #[DataProvider('payloads')]
    public function testRouteHandlersResourcesAndUnconvertedLinesStayInert(string $payload): void
    {
        $p = var_export($payload, true);
        $handler = var_export('PhotoController@' . $payload, true);
        $badClass = var_export($payload . '@index', true);
        $code = <<<PHP
            <?php
            use Illuminate\\Support\\Facades\\Route;
            Route::get('/a', {$handler});
            Route::get('/b', {$badClass});
            Route::resource({$p}, PhotoController::class);
            Route::get({$p}, fn () => 1);
            \$x = {$p};
            PHP;

        $file = (new RouteConverter())->convert('routes/web.php', $code);

        self::assertInert($file->content, 'routes');
    }

    #[DataProvider('payloads')]
    public function testMigrationNotesStayComments(string $payload): void
    {
        $p = var_export($payload, true);
        $code = "<?php\n\nuse Illuminate\\Database\\Migrations\\Migration;\nuse Illuminate\\Database\\Schema\\Blueprint;\nuse Illuminate\\Support\\Facades\\Schema;\n\nreturn new class extends Migration\n{\n    public function up(): void\n    {\n"
            . "        Schema::create('places', function (Blueprint \$table) {\n            \$table->id();\n            \$table->geometry({$p});\n        });\n    }\n};\n";

        $file = (new MigrationConverter())->convert('database/migrations/2026_01_01_000000_create_places.php', $code);

        self::assertInert($file->content, 'migration');
    }

    #[DataProvider('payloads')]
    public function testSourcePathsInHeadersStayComments(string $payload): void
    {
        $route = (new RouteConverter())->convert('routes/' . $payload . '.php', "<?php\nuse Illuminate\\Support\\Facades\\Route;\nRoute::get('/', fn () => 1);\n");
        self::assertInert($route->content, 'route header');

        $model = (new ModelConverter())->convert('app/Models/' . $payload . '.php', "<?php\nnamespace App\\Models;\nuse Illuminate\\Database\\Eloquent\\Model;\nclass Post extends Model {}\n");
        self::assertNotNull($model);
        self::assertInert($model->content, 'model header');

        $migration = (new MigrationConverter())->convert('database/migrations/' . $payload . '.php', "<?php\n\nuse Illuminate\\Database\\Migrations\\Migration;\n\nreturn new class extends Migration\n{\n    public function up(): void\n    {\n    }\n};\n");
        self::assertInert($migration->content, 'migration header');

        $controller = (new ControllerConverter())->convert('app/Http/Controllers/' . $payload . '.php', "<?php\nnamespace App\\Http\\Controllers;\nclass PhotoController extends Controller\n{\n    public function index() { return 1; }\n}\n");
        self::assertNotNull($controller);
        self::assertInert($controller->content, 'controller header');

        // Views are templates (HTML around PHP); only the header must not let the path out.
        $view = (new BladeConverter())->convert('resources/views/' . $payload . '.blade.php', '<p>Hi</p>');
        self::assertStringStartsWith('<?php /* ', $view->content);
        $header = strstr($view->content, "\n", true);
        self::assertIsString($header);
        self::assertSame(1, substr_count($header, '?>'), 'the header comment closes only where it should');
        self::assertStringNotContainsString('*/ veyro_pwned', $header);
    }

    public function testCommentHelper(): void
    {
        self::assertSame('a b ? > c * / d', Php::comment("a\r\nb ?> c */ d"));
        self::assertSame('जयपुर ✓', Php::comment('जयपुर ✓'));
    }

    public function testLegitimateValuesAreConvertedAsBefore(): void
    {
        $model = (new ModelConverter())->convert('app/Models/Post.php', "<?php\nnamespace App\\Models;\nuse Illuminate\\Database\\Eloquent\\Model;\nclass Post extends Model\n{\n    public function author() { return \$this->belongsTo(User::class, 'writer_id'); }\n    public function tags() { return \$this->belongsToMany(Tag::class, 'post_tag', 'post_id', 'tag_id'); }\n}\n");
        self::assertNotNull($model);
        self::assertStringContainsString("'author' => Relation::belongsTo(User::class, 'writer_id'),", $model->content);
        self::assertStringContainsString("'tags' => Relation::belongsToMany(Tag::class, 'post_tag', 'post_id', 'tag_id'),", $model->content);

        $routes = (new RouteConverter())->convert('routes/web.php', "<?php\nuse Illuminate\\Support\\Facades\\Route;\nRoute::get('/photos', 'PhotoController@index');\nRoute::resource('photos', PhotoController::class);\n");
        self::assertStringContainsString("\$router->get('/photos', [\\App\\Http\\Controllers\\PhotoController::class, 'index'])", $routes->content);
        self::assertStringContainsString("\$router->get('/photos', [PhotoController::class, 'index'])->name('photos.index')", $routes->content);
        self::assertStringContainsString("\$router->get('/photos/{photo}/edit', [PhotoController::class, 'edit'])->name('photos.edit')", $routes->content);
    }
}
