<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Integration\Orm;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use VeyroPHP\Application\Application;
use VeyroPHP\Config\Config;
use VeyroPHP\Console\Console;
use VeyroPHP\Console\ConsoleServiceProvider;
use VeyroPHP\Console\Output;
use VeyroPHP\Database\Connection\Connection;
use VeyroPHP\Database\Console\SeedCommand;
use VeyroPHP\Database\DatabaseServiceProvider;
use VeyroPHP\Database\Exception\QueryException;
use VeyroPHP\Database\Schema\Blueprint;
use VeyroPHP\Database\Schema\Schema;
use VeyroPHP\Database\Seeding\Seeder;
use VeyroPHP\Database\Seeding\SeederRunner;
use VeyroPHP\Orm\Factory\Factory;
use VeyroPHP\Orm\OrmServiceProvider;
use VeyroPHP\Support\Fake;
use VeyroPHP\Tests\Fixtures\Auth\AuthUser;

/**
 * @extends Factory<AuthUser>
 */
final class TestUserFactory extends Factory
{
    public function model(): string
    {
        return AuthUser::class;
    }

    public function definition(): array
    {
        return [
            'name' => $this->fake->name(),
            'email' => $this->fake->email(),
            // Not in FILLABLE: factories set it anyway.
            'password' => 'hash',
            'role' => 'user',
        ];
    }
}

final class SeedLog
{
    /** @var list<string> */
    public array $lines = [];
}

final class RolesSeeder extends Seeder
{
    public function __construct(private readonly SeedLog $log) {}

    public function run(): void
    {
        $this->log->lines[] = 'roles';
    }
}

final class UsersSeeder extends Seeder
{
    public function __construct(private readonly TestUserFactory $users, private readonly SeedLog $log) {}

    public function run(): void
    {
        $this->call(RolesSeeder::class);
        $this->users->count(3)->create();
        $this->log->lines[] = 'users';
    }
}

final class TestDatabaseSeeder extends Seeder
{
    public function __construct(private readonly SeedLog $log) {}

    public function run(): void
    {
        $this->call(RolesSeeder::class, UsersSeeder::class);
        $this->log->lines[] = 'database';
    }
}

#[CoversClass(Factory::class)]
#[CoversClass(Seeder::class)]
#[CoversClass(SeederRunner::class)]
#[CoversClass(SeedCommand::class)]
final class FactoryAndSeederTest extends TestCase
{
    private Application $app;

    private SeedLog $log;

    protected function setUp(): void
    {
        $this->boot('local');
    }

    private function boot(string $env): void
    {
        $this->app = new Application(sys_get_temp_dir());
        $this->app->container()->instance(Config::class, new Config([
            'app' => ['env' => $env],
            'database' => ['default' => 'memory', 'connections' => ['memory' => ['driver' => 'sqlite', 'database' => ':memory:']]],
        ]));
        $this->app->register(new DatabaseServiceProvider());
        $this->app->register(new OrmServiceProvider());
        $this->app->register(new ConsoleServiceProvider());
        $this->app->boot();
        $this->app->container()->instance(Fake::class, new Fake(seed: 1));
        $this->log = new SeedLog();
        $this->app->container()->instance(SeedLog::class, $this->log);

        $this->app->container()->make(Schema::class)->create('users', static function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->string('email')->unique();
            $t->string('password');
            $t->string('role');
            $t->timestamps();
        });
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private function factory(): TestUserFactory
    {
        return $this->app->container()->make(TestUserFactory::class);
    }

    private function userCount(): int
    {
        return $this->app->container()->make(Connection::class)->table('users')->count();
    }

    public function testMakeDoesNotSaveAndCreateDoes(): void
    {
        $made = $this->factory()->makeOne(['name' => 'Asha']);
        self::assertFalse($made->exists());
        self::assertSame('Asha', $made->get('name'));
        self::assertSame('hash', $made->get('password'), 'attributes outside FILLABLE are set');
        self::assertSame(0, $this->userCount());

        $created = $this->factory()->createOne();
        self::assertTrue($created->exists());
        self::assertNotNull($created->getKey());
        self::assertSame(1, $this->userCount());
    }

    public function testCountStatesSequenceAndCallbacks(): void
    {
        $made = [];
        $created = [];
        $factory = $this->factory()->count(4)
            ->state(['role' => 'editor'])
            ->state(static fn(array $attributes, Fake $fake): array => ['name' => strtoupper(self::text($attributes['name'] ?? ''))])
            ->sequence(['role' => 'a'], ['role' => 'b'])
            ->afterMaking(static function (AuthUser $u) use (&$made): void {
                $made[] = $u->get('role');
            })
            ->afterCreating(static function (AuthUser $u) use (&$created): void {
                $created[] = $u->getKey();
            });

        $users = $factory->create(['password' => 'override']);

        self::assertCount(4, $users);
        self::assertSame(['a', 'b', 'a', 'b'], array_map(static fn(AuthUser $u): mixed => $u->get('role'), $users), 'sequence after state');
        self::assertSame(strtoupper(self::text($users[0]->get('name'))), $users[0]->get('name'));
        self::assertSame(['override'], array_values(array_unique(array_map(static fn(AuthUser $u): string => self::text($u->get('password')), $users))));
        self::assertSame(['a', 'b', 'a', 'b'], $made);
        self::assertCount(4, array_filter($created));
        self::assertCount(4, array_unique(array_map(static fn(AuthUser $u): string => self::text($u->get('email')), $users)));
    }

    public function testFactoriesAreImmutable(): void
    {
        $base = $this->factory();
        $base->count(5)->state(['role' => 'admin']);

        self::assertCount(1, $base->make());
        self::assertSame('user', $base->makeOne()->get('role'));
        self::assertSame([], $base->count(0)->make());
    }

    public function testCreateIsOneTransaction(): void
    {
        $this->factory()->createOne(['email' => 'taken@example.com']);

        try {
            $this->factory()->count(3)->sequence(['email' => 'new@example.com'], ['email' => 'taken@example.com'])->create();
            self::fail('expected a unique constraint error');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        self::assertSame(1, $this->userCount(), 'the first new user was rolled back too');
    }

    public function testSeedersRunOnceEachWithInjection(): void
    {
        $ran = $this->app->container()->make(SeederRunner::class)->run(TestDatabaseSeeder::class);

        self::assertSame([TestDatabaseSeeder::class, RolesSeeder::class, UsersSeeder::class], $ran);
        self::assertSame(['roles', 'users', 'database'], $this->log->lines, 'RolesSeeder ran once although two seeders call it');
        self::assertSame(3, $this->userCount());
    }

    public function testCallOutsideTheRunnerFails(): void
    {
        $this->expectException(\LogicException::class);
        (new UsersSeeder($this->factory(), new SeedLog()))->run();
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{int, string, string}
     */
    private function exec(array $arguments): array
    {
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        self::assertIsResource($out);
        self::assertIsResource($err);

        $code = $this->app->container()->make(Console::class)->run($arguments, new Output($out, $err));
        rewind($out);
        rewind($err);

        return [$code, str_replace(PHP_EOL, "\n", (string) stream_get_contents($out)), (string) stream_get_contents($err)];
    }

    public function testSeedCommand(): void
    {
        [$code, , $err] = $this->exec(['db:seed']);
        self::assertSame(2, $code, 'the default Database\Seeders\DatabaseSeeder does not exist here');
        self::assertStringContainsString('make:seeder', $err);

        self::assertSame(2, $this->exec(['db:seed', '--class=' . SeedLog::class])[0], 'not a seeder');

        [$code, $out] = $this->exec(['db:seed', '--class=' . TestDatabaseSeeder::class]);
        self::assertSame(0, $code);
        self::assertStringContainsString('Seeding: ' . UsersSeeder::class, $out);
        self::assertSame(3, $this->userCount());
    }

    public function testSeedCommandNeedsForceInProduction(): void
    {
        $this->boot('production');

        [$code] = $this->exec(['db:seed', '--class=' . TestDatabaseSeeder::class]);
        self::assertSame(1, $code);
        self::assertSame(0, $this->userCount());

        self::assertSame(0, $this->exec(['db:seed', '--class=' . TestDatabaseSeeder::class, '--force'])[0]);
        self::assertSame(3, $this->userCount());
    }
}
