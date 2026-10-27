<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Integration\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use VeyroPHP\Application\Application;
use VeyroPHP\Auth\Rbac\Console\RbacSeedCommand;
use VeyroPHP\Auth\Rbac\RbacSchema;
use VeyroPHP\Auth\Rbac\RbacServiceProvider;
use VeyroPHP\Cache\CacheServiceProvider;
use VeyroPHP\Config\Config;
use VeyroPHP\Console\Console;
use VeyroPHP\Console\ConsoleServiceProvider;
use VeyroPHP\Console\Output;
use VeyroPHP\Database\Connection\Connection;
use VeyroPHP\Database\Console\MigrateCommand;
use VeyroPHP\Database\Console\ProductionGuard;
use VeyroPHP\Database\Console\RollbackCommand;
use VeyroPHP\Database\Console\StatusCommand;
use VeyroPHP\Database\DatabaseServiceProvider;
use VeyroPHP\Database\Schema\Schema;
use VeyroPHP\Orm\OrmServiceProvider;

#[CoversClass(ConsoleServiceProvider::class)]
#[CoversClass(MigrateCommand::class)]
#[CoversClass(RollbackCommand::class)]
#[CoversClass(StatusCommand::class)]
#[CoversClass(ProductionGuard::class)]
#[CoversClass(RbacSeedCommand::class)]
final class FrameworkCommandsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'veyrophp-console-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/database/migrations', 0777, true);

        foreach (['2026_01_01_000001_create_posts' => 'posts', '2026_01_01_000002_create_tags' => 'tags'] as $name => $table) {
            file_put_contents($this->root . '/database/migrations/' . $name . '.php', <<<PHP
                <?php
                use VeyroPHP\\Database\\Migration\\Migration;
                use VeyroPHP\\Database\\Schema\\Blueprint;
                use VeyroPHP\\Database\\Schema\\Schema;

                return new class extends Migration {
                    public function up(Schema \$schema): void
                    {
                        \$schema->create('{$table}', static fn(Blueprint \$t) => \$t->id());
                    }

                    public function down(Schema \$schema): void
                    {
                        \$schema->drop('{$table}');
                    }
                };
                PHP);
        }
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->root . '/database/migrations/*.php') ?: []);
        rmdir($this->root . '/database/migrations');
        rmdir($this->root . '/database');
        rmdir($this->root);
    }

    private function app(string $env = 'local'): Application
    {
        $app = new Application($this->root);
        $app->container()->instance(Config::class, new Config([
            'app' => ['env' => $env, 'debug' => false],
            'database' => ['default' => 'memory', 'connections' => ['memory' => ['driver' => 'sqlite', 'database' => ':memory:']]],
            'cache' => ['default' => 'array', 'stores' => ['array' => ['driver' => 'array']]],
            'rbac' => ['seed' => ['roles' => ['admin' => 'Administrator'], 'permissions' => ['posts.edit' => null], 'role_permissions' => ['admin' => ['posts.edit']]]],
        ]));
        $app->register(new DatabaseServiceProvider());
        $app->register(new CacheServiceProvider());
        $app->register(new OrmServiceProvider());
        $app->register(new RbacServiceProvider());
        $app->register(new ConsoleServiceProvider([RbacSeedCommand::class]));
        $app->boot();

        return $app;
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{int, string, string}
     */
    private static function exec(Application $app, array $arguments): array
    {
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        self::assertIsResource($out);
        self::assertIsResource($err);

        $code = $app->container()->make(Console::class)->run($arguments, new Output($out, $err));
        rewind($out);
        rewind($err);

        return [$code, str_replace(PHP_EOL, "\n", (string) stream_get_contents($out)), (string) stream_get_contents($err)];
    }

    public function testBuiltInCommandsAreListed(): void
    {
        $commands = $this->app()->container()->make(Console::class)->commands();

        self::assertContains('rbac:seed', array_keys($commands));
        self::assertContains('migrate', array_keys($commands));

        // "migrate" is shown inside its group, after the group heading.
        [, $out] = self::exec($this->app(), []);
        self::assertMatchesRegularExpression('/\n migrate\n  migrate\s+Run pending migrations\n  migrate:rollback\s+.+\n  migrate:status\s+.+\n package\n  package:discover\s+.+\n port\n  port:analyze\s+.+\n  port:convert\s+.+\n rbac\n  rbac:seed/', $out);
    }

    public function testMigrateStatusRollback(): void
    {
        $app = $this->app();

        [, $out] = self::exec($app, ['migrate:status']);
        self::assertMatchesRegularExpression('/Status\s+Batch\s+Migration\nPending\s+-\s+2026_01_01_000001_create_posts\nPending\s+-\s+2026_01_01_000002_create_tags\n/', $out);

        self::assertSame([0, "Migrated:  2026_01_01_000001_create_posts\nMigrated:  2026_01_01_000002_create_tags\n", ''], self::exec($app, ['migrate']));
        self::assertSame([0, "Nothing to migrate.\n", ''], self::exec($app, ['migrate']));
        self::assertTrue($app->container()->make(Schema::class)->hasTable('tags'));

        [, $out] = self::exec($app, ['migrate:status']);
        self::assertMatchesRegularExpression('/Ran\s+1\s+2026_01_01_000002_create_tags/', $out);

        self::assertSame([0, "Rolled back:  2026_01_01_000002_create_tags\nRolled back:  2026_01_01_000001_create_posts\n", ''], self::exec($app, ['migrate:rollback']));
        self::assertSame([0, "Nothing to roll back.\n", ''], self::exec($app, ['migrate:rollback', '--step=3']));
        self::assertFalse($app->container()->make(Schema::class)->hasTable('posts'));

        self::assertSame(2, self::exec($app, ['migrate:rollback', '--step=0'])[0]);
    }

    public function testProductionNeedsForce(): void
    {
        $app = $this->app('production');

        foreach ([['migrate'], ['migrate:rollback']] as $arguments) {
            [$code, $out, $err] = self::exec($app, $arguments);
            self::assertSame(1, $code);
            self::assertSame('', $out);
            self::assertStringContainsString('Refusing to change the database in production without --force.', $err);
        }
        self::assertFalse($app->container()->make(Schema::class)->hasTable('posts'));

        self::assertSame(0, self::exec($app, ['migrate', '--force'])[0]);
        self::assertTrue($app->container()->make(Schema::class)->hasTable('posts'));

        // Reading status is allowed.
        self::assertSame(0, self::exec($app, ['migrate:status'])[0]);
    }

    public function testRbacSeedIsIdempotent(): void
    {
        $app = $this->app();
        RbacSchema::up($app->container()->make(Schema::class));

        self::assertSame([0, "Roles and permissions from config/rbac.php are in place.\n", ''], self::exec($app, ['rbac:seed']));
        self::assertSame(0, self::exec($app, ['rbac:seed'])[0]);

        $db = $app->container()->make(Connection::class);
        self::assertSame(1, $db->table('roles')->count());
        self::assertSame(1, $db->table('permission_role')->count());
    }

    public function testDatabaseErrorsBecomeExitCode1(): void
    {
        $app = $this->app();

        // RBAC tables do not exist yet.
        [$code, , $err] = self::exec($app, ['rbac:seed']);
        self::assertSame(1, $code);
        self::assertStringStartsWith('Error: ', $err);
    }

    public function testCachePrune(): void
    {
        $app = $this->app();
        $cache = $app->container()->make(\VeyroPHP\Cache\CacheManager::class)->store();
        $cache->set('expired', 'x', 1);
        $cache->set('alive', 'y', 600);
        sleep(2);

        self::assertSame([0, "Removed 1 expired entry from the \"array\" store.\n", ''], self::exec($app, ['cache:prune']));
        self::assertSame('y', $cache->get('alive'));
        self::assertSame([0, "Removed 0 expired entries from the \"array\" store.\n", ''], self::exec($app, ['cache:prune', '--store=array']));
        self::assertSame(1, self::exec($app, ['cache:prune', '--store=missing'])[0]);
    }
}
