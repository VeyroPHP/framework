<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Integration\Console;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use VeyroPHP\Application\Application;
use VeyroPHP\Cache\ArrayStore;
use VeyroPHP\Cache\Cache;
use VeyroPHP\Config\Config;
use VeyroPHP\Console\Console;
use VeyroPHP\Console\ConsoleServiceProvider;
use VeyroPHP\Console\Output;
use VeyroPHP\Queue\Driver\ArrayDriver;
use VeyroPHP\Queue\Job;
use VeyroPHP\Queue\Payload;
use VeyroPHP\Queue\Queue;
use VeyroPHP\Scheduling\Console\ListCommand;
use VeyroPHP\Scheduling\Console\RunCommand;
use VeyroPHP\Scheduling\Console\WorkCommand;
use VeyroPHP\Scheduling\Event;
use VeyroPHP\Scheduling\Schedule;
use VeyroPHP\Scheduling\ScheduleException;
use VeyroPHP\Scheduling\ScheduleRunner;
use VeyroPHP\Scheduling\SchedulingServiceProvider;

final class ScheduleCounter
{
    /** @var list<string> */
    public array $runs = [];
}

final class NightlyReportJob implements Job
{
    public function __construct(public readonly string $kind) {}

    public function handle(): void {}
}

#[CoversClass(Schedule::class)]
#[CoversClass(Event::class)]
#[CoversClass(ScheduleRunner::class)]
#[CoversClass(SchedulingServiceProvider::class)]
#[CoversClass(RunCommand::class)]
#[CoversClass(ListCommand::class)]
#[CoversClass(WorkCommand::class)]
final class ScheduleTest extends TestCase
{
    private string $root;

    private Application $app;

    private DateTimeImmutable $now;

    private ScheduleCounter $counter;

    private Cache $cache;

    private ArrayDriver $queue;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'veyrophp-schedule-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/routes', 0777, true);
        // Monday 2026-10-26 07:30 in Kolkata.
        $this->now = new DateTimeImmutable('2026-10-26 02:00', new DateTimeZone('UTC'));
        $this->counter = new ScheduleCounter();
        $this->cache = new Cache(new ArrayStore(), 'test');
        $this->queue = new ArrayDriver();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/routes/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->root . '/routes');
        rmdir($this->root);
    }

    private function boot(string $scheduleFile, string $env = 'production'): void
    {
        file_put_contents($this->root . '/routes/schedule.php', $scheduleFile);

        $this->app = new Application($this->root);
        $c = $this->app->container();
        $c->instance(Config::class, new Config(['app' => ['env' => $env]]));
        $c->instance(ScheduleCounter::class, $this->counter);
        $c->instance(Cache::class, $this->cache);
        $driver = $this->queue;
        $c->instance(Queue::class, new Queue(static fn(): ArrayDriver => $driver, 'array', new Payload()));
        $this->app->register(new SchedulingServiceProvider());
        $this->app->register(new ConsoleServiceProvider(SchedulingServiceProvider::COMMANDS));
        $this->app->boot();

        $c->instance(ScheduleRunner::class, new ScheduleRunner($c->make(Schedule::class), $env, $this->cache, new NullLogger(), fn(): DateTimeImmutable => $this->now));
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

    // Frequencies

    public function testFrequencyHelpers(): void
    {
        $event = static fn(): Event => new Event(static function (): void {}, 'x');

        self::assertSame('* * * * *', $event()->expression());
        self::assertSame('*/5 * * * *', $event()->everyFiveMinutes()->expression());
        self::assertSame('0,30 * * * *', $event()->everyThirtyMinutes()->expression());
        self::assertSame('17 * * * *', $event()->hourlyAt(17)->expression());
        self::assertSame('0 0 * * *', $event()->daily()->expression());
        self::assertSame('30 7 * * *', $event()->dailyAt('07:30')->expression());
        self::assertSame('0 1,13 * * *', $event()->twiceDaily()->expression());
        self::assertSame('0 8 * * 1', $event()->weeklyOn(1, '08:00')->expression());
        self::assertSame('0 15 4 * *', $event()->monthlyOn(4, '15:00')->expression());
        self::assertSame('0 0 1 1,4,7,10 *', $event()->quarterly()->expression());
        self::assertSame('0 0 1 1 *', $event()->yearly()->expression());
        self::assertSame('0 9 * * 1-5', $event()->dailyAt('09:00')->weekdays()->expression());
        self::assertSame('0 * * * 0,6', $event()->hourly()->weekends()->expression());
        self::assertSame('0 0 * * 0', $event()->cron('@weekly')->expression());
        self::assertSame('0 8 * * 2,4', $event()->at('08:00')->days(2, 4)->expression());

        foreach ([
            static fn() => $event()->dailyAt('25:00'),
            static fn() => $event()->dailyAt('7.30'),
            static fn() => $event()->hourlyAt(60),
            static fn() => $event()->cron('bad'),
            static fn() => $event()->timezone('Mars/Base'),
            static fn() => $event()->days(9),
        ] as $i => $wrong) {
            try {
                $wrong();
                self::fail('expected ScheduleException for case ' . $i);
            } catch (ScheduleException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testTimezoneEnvironmentsAndConditions(): void
    {
        $at = new DateTimeImmutable('2026-10-26 02:00', new DateTimeZone('UTC'));
        $task = static fn(): Event => new Event(static function (): void {}, 'x');

        self::assertTrue($task()->dailyAt('07:30')->timezone('Asia/Kolkata')->isDue($at, 'production'));
        self::assertFalse($task()->dailyAt('07:30')->isDue($at, 'production'), 'server time zone (UTC) is 02:00');
        self::assertFalse($task()->environments('production')->isDue($at, 'local'));
        self::assertTrue($task()->environments('staging', 'production')->isDue($at, 'production'));
        self::assertFalse($task()->when(static fn(): bool => false)->isDue($at, 'production'));
        self::assertFalse($task()->skip(static fn(): bool => true)->isDue($at, 'production'));
        self::assertTrue($task()->when(static fn(): bool => true)->skip(static fn(): bool => false)->isDue($at, 'production'));
    }

    // Running

    public function testRunsDueTasksInOrderAndKeepsGoingAfterAFailure(): void
    {
        $this->boot(<<<'PHP'
            <?php
            use VeyroPHP\Tests\Integration\Console\ScheduleCounter;
            use VeyroPHP\Console\Output;
            /** @var VeyroPHP\Scheduling\Schedule $schedule */
            $schedule->call(static function (ScheduleCounter $c): void { $c->runs[] = 'first'; })->name('first');
            $schedule->call(static function (): void { throw new RuntimeException('disk full'); })->name('broken');
            $schedule->call(static function (ScheduleCounter $c, Output $out): void { $c->runs[] = 'third'; $out->line('hello from third'); })->name('third');
            $schedule->call(static function (ScheduleCounter $c): void { $c->runs[] = 'not due'; })->dailyAt('23:00');
            PHP);

        [$code, $out, $err] = $this->exec(['schedule:run']);

        self::assertSame(1, $code, 'a failed task makes the run fail');
        self::assertSame(['first', 'third'], $this->counter->runs);
        self::assertStringContainsString('Running first', $out);
        self::assertStringContainsString('hello from third', $out);
        self::assertStringContainsString('Failed: broken: disk full', $err);
    }

    public function testCommandsAndJobs(): void
    {
        $this->boot(<<<'PHP'
            <?php
            /** @var VeyroPHP\Scheduling\Schedule $schedule */
            $schedule->command('schedule:list');
            $schedule->job(new VeyroPHP\Tests\Integration\Console\NightlyReportJob('sales'), 'reports');
            PHP);

        [$code, $out] = $this->exec(['schedule:run']);

        self::assertSame(0, $code);
        self::assertStringContainsString('Running php veyro schedule:list', $out);
        self::assertStringContainsString('job ' . NightlyReportJob::class, $out, 'the nested command printed its table');
        self::assertSame(1, $this->queue->size('reports'), 'the job was queued, not run');
        $job = $this->queue->jobs(NightlyReportJob::class)[0]['job'] ?? null;
        self::assertIsArray($job);
        self::assertSame(['kind' => ['t' => 'json', 'v' => 'sales']], $job['data'] ?? null);
    }

    public function testFailingCommandCountsAsFailure(): void
    {
        $this->boot("<?php\n\$schedule->command('no:such-command');\n");

        self::assertSame(1, $this->exec(['schedule:run'])[0]);
    }

    public function testWithoutOverlappingSkipsWhileLocked(): void
    {
        $this->boot(<<<'PHP'
            <?php
            use VeyroPHP\Tests\Integration\Console\ScheduleCounter;
            /** @var VeyroPHP\Scheduling\Schedule $schedule */
            $schedule->call(static function (ScheduleCounter $c): void { $c->runs[] = 'locked task'; })->name('import')->withoutOverlapping(30);
            PHP);
        $event = $this->app->container()->make(Schedule::class)->events()[0];

        // Another schedule:run (an earlier minute) still holds the lock.
        $this->cache->tryLock($event->id(), 60, function (): void {
            [$code, $out] = $this->exec(['schedule:run']);
            self::assertSame(0, $code);
            self::assertStringContainsString('skipped: still running', $out);
        });
        self::assertSame([], $this->counter->runs);

        $this->exec(['schedule:run']);
        self::assertSame(['locked task'], $this->counter->runs, 'runs once the lock is free');
    }

    public function testNothingDueAndNoScheduleFile(): void
    {
        $this->boot("<?php\n\$schedule->call(static fn() => null)->dailyAt('23:00');\n");
        self::assertSame([0, "No scheduled tasks are due.\n"], array_slice($this->exec(['schedule:run']), 0, 2));

        unlink($this->root . '/routes/schedule.php');
        $app = new Application($this->root);
        $app->container()->instance(Config::class, new Config([]));
        $app->register(new SchedulingServiceProvider());
        $app->boot();
        self::assertSame([], $app->container()->make(Schedule::class)->events());
        file_put_contents($this->root . '/routes/schedule.php', '<?php');
    }

    public function testListShowsNextRuns(): void
    {
        $this->boot(<<<'PHP'
            <?php
            /** @var VeyroPHP\Scheduling\Schedule $schedule */
            $schedule->command('cache:prune')->everyThirtyMinutes();
            $schedule->call(static fn() => null)->name('Morning digest')->dailyAt('08:00')->timezone('Asia/Kolkata')->withoutOverlapping();
            PHP);

        [$code, $out] = $this->exec(['schedule:list']);

        self::assertSame(0, $code);
        self::assertStringContainsString('0,30 * * * *', $out);
        self::assertStringContainsString('2026-10-26 02:30', $out);
        self::assertStringContainsString('Asia/Kolkata', $out);
        self::assertStringContainsString('2026-10-26 08:00', $out, 'next run shown in the task time zone');
        self::assertStringContainsString('Morning digest (no overlap)', $out);
    }

    public function testWorkRunsEveryMinute(): void
    {
        $this->boot(<<<'PHP'
            <?php
            use VeyroPHP\Tests\Integration\Console\ScheduleCounter;
            /** @var VeyroPHP\Scheduling\Schedule $schedule */
            $schedule->call(static function (ScheduleCounter $c): void { $c->runs[] = 'tick'; });
            PHP);
        $slept = [];
        $this->now = $this->now->setTime(2, 0, 50);
        $c = $this->app->container();
        $c->instance(WorkCommand::class, new WorkCommand($c->make(ScheduleRunner::class), function (int $seconds) use (&$slept): void {
            $slept[] = $seconds;
            $this->now = $this->now->modify("+{$seconds} seconds");
        }));

        [$code] = $this->exec(['schedule:work', '--minutes=3']);

        self::assertSame(0, $code);
        self::assertSame(['tick', 'tick', 'tick'], $this->counter->runs);
        self::assertSame([10, 60], $slept, 'wakes at the start of each minute');
    }
}
