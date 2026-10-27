<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Integration\Port;

use PHPUnit\Framework\TestCase;
use VeyroPHP\Database\Connection\ConnectionConfig;
use VeyroPHP\Database\DatabaseManager;
use VeyroPHP\Database\Migration\Migration;
use VeyroPHP\Database\Schema\Schema;
use VeyroPHP\Testing\TestApplication;

/**
 * port:convert on the Laravel fixture into a temporary target application.
 * The source must stay byte-for-byte unchanged, and the converted migration
 * must actually run (up and down) on SQLite, not only parse.
 */
final class PortConvertCommandTest extends TestCase
{
    private const SOURCE = __DIR__ . '/../../Fixtures/Port/laravel-app';

    private TestApplication $test;

    private string $target;

    protected function setUp(): void
    {
        $this->test = TestApplication::boot();
        $this->target = sys_get_temp_dir() . '/veyrophp-port-target-' . bin2hex(random_bytes(4));
        mkdir($this->target . '/database/migrations', 0777, true);
        mkdir($this->target . '/routes', 0777, true);
        file_put_contents($this->target . '/veyro', "<?php\n");
        file_put_contents($this->target . '/composer.json', '{"require": {"veyrophp/core": "0.1.x-dev"}}');
        // The target's own migration and route that the converted ones collide with.
        file_put_contents($this->target . '/database/migrations/2026_10_26_000001_create_users_table.php', "<?php\n\$schema->create('users', fn () => null);\n");
        file_put_contents($this->target . '/routes/web.php', "<?php\n\$router->get('/', fn () => 'welcome')->name('home');\n");
    }

    protected function tearDown(): void
    {
        $this->test->cleanUp();
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->target, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($files as $file) {
            \assert($file instanceof \SplFileInfo);
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($this->target);
    }

    /**
     * @return array<string, string> relative path => sha256 of every file in the source fixture
     */
    private static function sourceHashes(): array
    {
        $hashes = [];
        $root = (string) realpath(self::SOURCE);

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            \assert($file instanceof \SplFileInfo);
            $hashes[substr($file->getPathname(), strlen($root) + 1)] = (string) hash_file('sha256', $file->getPathname());
        }

        ksort($hashes);

        return $hashes;
    }

    /**
     * @return list<string>
     */
    private function targetFiles(): array
    {
        $files = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->target, \FilesystemIterator::SKIP_DOTS)) as $file) {
            \assert($file instanceof \SplFileInfo);
            $files[] = str_replace('\\', '/', substr($file->getPathname(), strlen($this->target) + 1));
        }

        sort($files);

        return $files;
    }

    public function testDryRunWritesNothing(): void
    {
        $before = $this->targetFiles();
        [$code, $out, $err] = $this->test->command(['port:convert', self::SOURCE, $this->target, '--dry-run']);

        self::assertSame(0, $code, $err);
        self::assertSame($before, $this->targetFiles());
        self::assertStringContainsString('would be written', $out);
        self::assertStringContainsString('Nothing was written (--dry-run).', $out);
        self::assertStringContainsString('Table "users" is created by database/migrations/2024_01_01_000000_create_users_table.php and by database/migrations/2026_10_26_000001_create_users_table.php', $err);
        self::assertStringContainsString('Route conflict (approximate check): GET / is defined in routes/ported/web.php and in routes/web.php.', $err);
    }

    public function testConvertsWithoutTouchingTheSourceAndTheMigrationRuns(): void
    {
        $hashes = self::sourceHashes();
        [$code, $out] = $this->test->command(['port:convert', self::SOURCE, $this->target, '--format=json']);

        self::assertSame(0, $code);
        self::assertSame($hashes, self::sourceHashes(), 'The source project must not change.');

        $report = json_decode($out, true);
        self::assertIsArray($report);
        self::assertIsArray($report['files']);
        self::assertSame(['database/migrations/2024_01_01_000000_create_users_table.php', 'app/Models/Post.php', 'app/Models/User.php', 'routes/ported/web.php', 'resources/views/welcome.php', 'app/Http/Controllers/UserController.php'], array_column($report['files'], 'target'));
        self::assertSame(array_fill(0, 6, 'written'), array_column($report['files'], 'status'));
        self::assertSame(array_fill(0, 6, true), array_column($report['files'], 'parses'));

        foreach (['app/Models/Post.php', 'app/Models/User.php', 'routes/ported/web.php'] as $file) {
            self::assertFileExists($this->target . '/' . $file);
        }

        // The converted migration really runs, up and down.
        $file = sys_get_temp_dir() . '/veyrophp-port-' . bin2hex(random_bytes(4)) . '.sqlite';
        $db = (new DatabaseManager(['sqlite' => new ConnectionConfig('sqlite', 'sqlite', $file)], 'sqlite'))->connection();
        $schema = new Schema($db);

        try {
            $migration = require $this->target . '/database/migrations/2024_01_01_000000_create_users_table.php';
            self::assertInstanceOf(Migration::class, $migration);
            $migration->up($schema);
            self::assertTrue($schema->hasColumn('users', 'status'));
            self::assertTrue($schema->hasColumn('users', 'deleted_at'));
            self::assertFalse($schema->hasColumn('users', 'token'), 'uuid() was commented out with a TODO');
            $migration->down($schema);
            self::assertFalse($schema->hasTable('users'));
        } finally {
            $db->disconnect();
            @unlink($file);
        }

        // A second run leaves everything as it is; --force replaces only converted files.
        [$code, , $err] = $this->test->command(['port:convert', self::SOURCE, $this->target]);
        self::assertSame(0, $code);
        self::assertStringContainsString('app/Models/User.php already exists and was left unchanged.', $err);

        [$code, $out] = $this->test->command(['port:convert', self::SOURCE, $this->target, '--force', '--only=models']);
        self::assertSame(0, $code);
        self::assertSame(2, substr_count($out, 'replaced'));
        self::assertSame("<?php\n\$schema->create('users', fn () => null);\n", file_get_contents($this->target . '/database/migrations/2026_10_26_000001_create_users_table.php'), 'The target\'s own files are never replaced.');
    }

    public function testRefusals(): void
    {
        foreach ([
            [[self::SOURCE . '/../ci4-app', $this->target], 'does not look like a Laravel project'],
            [[self::SOURCE, self::SOURCE . '/app'], 'is not a VeyroPHP application'],
            [[self::SOURCE, $this->target, '--only=docs'], '--only takes migrations, models, routes, views, controllers, public'],
        ] as [$arguments, $message]) {
            [$code, , $err] = $this->test->command(['port:convert', ...$arguments]);
            self::assertSame(2, $code, $message);
            self::assertStringContainsString($message, $err);
        }
    }
}
