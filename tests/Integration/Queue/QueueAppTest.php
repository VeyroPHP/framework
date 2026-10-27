<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Integration\Queue;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use VeyroPHP\Application\Application;
use VeyroPHP\Cache\CacheServiceProvider;
use VeyroPHP\Config\Config;
use VeyroPHP\Console\Console;
use VeyroPHP\Console\ConsoleServiceProvider;
use VeyroPHP\Console\Output;
use VeyroPHP\Database\Connection\Connection;
use VeyroPHP\Database\DatabaseServiceProvider;
use VeyroPHP\Database\Schema\Blueprint;
use VeyroPHP\Database\Schema\Schema;
use VeyroPHP\Events\EventServiceProvider;
use VeyroPHP\Orm\Orm;
use VeyroPHP\Orm\OrmServiceProvider;
use VeyroPHP\Queue\Console\FailedCommand;
use VeyroPHP\Queue\Console\FlushCommand;
use VeyroPHP\Queue\Console\ForgetCommand;
use VeyroPHP\Queue\Console\RestartCommand;
use VeyroPHP\Queue\Console\RetryCommand;
use VeyroPHP\Queue\Console\WorkCommand;
use VeyroPHP\Queue\Job;
use VeyroPHP\Queue\ModelMissingException;
use VeyroPHP\Queue\Queue;
use VeyroPHP\Queue\QueueSchema;
use VeyroPHP\Queue\QueueServiceProvider;
use VeyroPHP\Queue\UnrunnableJobException;
use VeyroPHP\Queue\Worker;
use VeyroPHP\Tests\Fixtures\Auth\AuthUser;

final class RenameUserJob implements Job
{
    public static bool $fail = false;

    public int $tries = 2;

    public function __construct(public readonly AuthUser $user, public readonly string $name) {}

    public function handle(Orm $orm): void
    {
        if (self::$fail) {
            throw new \RuntimeException('mail server down');
        }

        $this->user->set('name', $this->name);
        $orm->save($this->user);
    }
}

#[CoversClass(QueueServiceProvider::class)]
#[CoversClass(WorkCommand::class)]
#[CoversClass(FailedCommand::class)]
#[CoversClass(RetryCommand::class)]
#[CoversClass(ForgetCommand::class)]
#[CoversClass(FlushCommand::class)]
#[CoversClass(RestartCommand::class)]
#[CoversClass(ModelMissingException::class)]
#[CoversClass(UnrunnableJobException::class)]
final class QueueAppTest extends TestCase
{
    private Application $app;

    protected function setUp(): void
    {
        RenameUserJob::$fail = false;
        $this->app = new Application(sys_get_temp_dir());
        $this->app->container()->instance(Config::class, new Config([
            'database' => ['default' => 'memory', 'connections' => ['memory' => ['driver' => 'sqlite', 'database' => ':memory:']]],
            'cache' => ['default' => 'array', 'prefix' => 'test', 'stores' => ['array' => ['driver' => 'array']]],
            'queue' => [
                'default' => 'database',
                'connections' => [
                    'database' => ['driver' => 'database', 'queue' => 'default', 'retry_after' => 90],
                    'sync' => ['driver' => 'sync'],
                ],
                'failed' => ['table' => 'failed_jobs'],
            ],
        ]));
        $this->app->register(new DatabaseServiceProvider());
        $this->app->register(new CacheServiceProvider());
        $this->app->register(new OrmServiceProvider());
        $this->app->register(new EventServiceProvider());
        $this->app->register(new QueueServiceProvider());
        $this->app->register(new ConsoleServiceProvider(QueueServiceProvider::COMMANDS));
        $this->app->boot();

        $schema = $this->app->container()->make(Schema::class);
        $schema->create('users', static function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->string('email');
            $t->string('password')->nullable();
            $t->timestamps();
        });
        QueueSchema::up($schema);
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

    private function user(): AuthUser
    {
        return $this->app->container()->make(Orm::class)->create(AuthUser::class, ['name' => 'Asha', 'email' => 'asha@example.com']);
    }

    private function nameOf(int|string|null $id): mixed
    {
        return $this->app->container()->make(Connection::class)->table('users')->where('id', $id)->value('name');
    }

    public function testModelIsStoredAsKeyAndLoadedFreshByTheWorker(): void
    {
        $user = $this->user();
        $this->app->container()->make(Queue::class)->push(new RenameUserJob($user, 'Asha Verma'));

        $stored = $this->app->container()->make(Connection::class)->table('jobs')->value('payload');
        self::assertIsString($stored);
        self::assertStringContainsString('"t":"model","c":"VeyroPHP\\\\Tests\\\\Fixtures\\\\Auth\\\\AuthUser","k":' . $user->getKey(), $stored);
        self::assertStringNotContainsString('asha@example.com', $stored, 'only the key is stored, not the row');

        // Changed after queueing: the worker sees the current row.
        $this->app->container()->make(Connection::class)->table('users')->where('id', $user->getKey())->update(['email' => 'new@example.com']);

        [$code, $out] = $this->exec(['queue:work', '--once']);
        self::assertSame([0, "Processed one job.\n"], [$code, $out]);
        self::assertSame('Asha Verma', $this->nameOf($user->getKey()));
    }

    public function testDeletedModelFailsAtOnce(): void
    {
        $user = $this->user();
        $this->app->container()->make(Queue::class)->push(new RenameUserJob($user, 'x'));
        $this->app->container()->make(Orm::class)->delete($user);

        $this->exec(['queue:work', '--once']);

        [$code, $out] = $this->exec(['queue:failed']);
        self::assertSame(0, $code);
        self::assertStringContainsString(RenameUserJob::class, $out);
        self::assertStringContainsString('ModelMissingException', $out);
        self::assertSame(0, $this->app->container()->make(Connection::class)->table('jobs')->count(), 'not retried');
    }

    public function testFailedJobsCanBeListedRetriedAndForgotten(): void
    {
        RenameUserJob::$fail = true;
        $user = $this->user();
        $queue = $this->app->container()->make(Queue::class);
        $queue->push(new RenameUserJob($user, 'first'));
        $queue->push(new RenameUserJob($user, 'second'));

        // Two tries each (no backoff): four runs, then both fail.
        [$code, $out] = $this->exec(['queue:work', '--stop-when-empty', '--sleep=0']);
        self::assertSame(0, $code);
        self::assertStringContainsString('Worker stopped: queue empty (--stop-when-empty).', $out);

        [, $list] = $this->exec(['queue:failed']);
        self::assertSame(2, substr_count($list, RenameUserJob::class));
        self::assertStringContainsString('mail server down', $list);

        [, $detail] = $this->exec(['queue:failed', '--id=1']);
        self::assertStringContainsString("RuntimeException: mail server down\nin ", $detail);

        RenameUserJob::$fail = false;
        [$code, $out] = $this->exec(['queue:retry', '1']);
        self::assertSame(0, $code);
        self::assertStringContainsString('Queued failed job 1', $out);
        $this->exec(['queue:work', '--once']);
        self::assertSame('first', $this->nameOf($user->getKey()), 'the retried job ran with a fresh attempt count');

        self::assertSame(1, $this->exec(['queue:retry', '1'])[0], 'already retried');
        self::assertSame(0, $this->exec(['queue:forget', '2'])[0]);
        self::assertSame(1, $this->exec(['queue:forget', '2'])[0]);
        self::assertStringContainsString('No failed jobs.', $this->exec(['queue:failed'])[1]);
        self::assertStringContainsString('Deleted 0 failed jobs.', $this->exec(['queue:flush'])[1]);
    }

    public function testRestartStopsARunningWorker(): void
    {
        $this->exec(['queue:restart']);
        $before = $this->app->container()->make(\Psr\SimpleCache\CacheInterface::class)->get(Worker::RESTART_KEY);
        self::assertIsString($before);

        $this->exec(['queue:restart']);
        self::assertNotSame($before, $this->app->container()->make(\Psr\SimpleCache\CacheInterface::class)->get(Worker::RESTART_KEY));
    }

    public function testSyncConnectionRunsImmediately(): void
    {
        $user = $this->user();
        $this->app->container()->make(Queue::class)->push(new RenameUserJob($user, 'now'), connection: 'sync');

        self::assertSame('now', $this->nameOf($user->getKey()));
        self::assertSame(0, $this->app->container()->make(Connection::class)->table('jobs')->count());
    }

    public function testInvalidOptionsAndUnknownConnection(): void
    {
        [$code, , $err] = $this->exec(['queue:work', '--max-jobs=lots']);
        self::assertSame(2, $code);
        self::assertStringContainsString('--max-jobs option must be a whole number', $err);

        [$code, , $err] = $this->exec(['queue:work', 'missing', '--once']);
        self::assertSame(1, $code);
        self::assertStringContainsString('No queue connection "missing"', $err);
    }
}
