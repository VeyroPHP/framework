<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Unit\Queue;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;
use VeyroPHP\Cache\ArrayStore;
use VeyroPHP\Cache\Cache;
use VeyroPHP\Container\Container;
use VeyroPHP\Events\EventDispatcher;
use VeyroPHP\Events\EventException;
use VeyroPHP\Mail\Address;
use VeyroPHP\Mail\Mailable;
use VeyroPHP\Mail\Mailer;
use VeyroPHP\Mail\MailException;
use VeyroPHP\Mail\Message;
use VeyroPHP\Mail\SendQueuedMailable;
use VeyroPHP\Mail\Transport\ArrayTransport;
use VeyroPHP\Queue\CallQueuedListener;
use VeyroPHP\Queue\Driver\ArrayDriver;
use VeyroPHP\Queue\Driver\Driver;
use VeyroPHP\Queue\Driver\SyncDriver;
use VeyroPHP\Queue\Events\JobFailed;
use VeyroPHP\Queue\Events\JobProcessed;
use VeyroPHP\Queue\Failed\FailedJob;
use VeyroPHP\Queue\Failed\FailedJobStore;
use VeyroPHP\Queue\Job;
use VeyroPHP\Queue\JobRunner;
use VeyroPHP\Queue\Payload;
use VeyroPHP\Queue\Queue;
use VeyroPHP\Queue\QueueException;
use VeyroPHP\Queue\ShouldQueue;
use VeyroPHP\Queue\Worker;
use VeyroPHP\Queue\WorkerOptions;

enum Priority: string
{
    case High = 'high';
    case Low = 'low';
}

final class Recorder
{
    /** @var list<string> */
    public array $calls = [];
}

final class RecordJob implements Job
{
    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public readonly string $label,
        public readonly array $meta = [],
        public readonly ?Priority $priority = null,
        public readonly float $amount = 1.0,
    ) {}

    public function handle(Recorder $recorder): void
    {
        $recorder->calls[] = $this->label . ':' . json_encode($this->meta) . ':' . ($this->priority->value ?? '-') . ':' . $this->amount;
    }
}

final class FlakyJob implements Job
{
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public static int $failUntilAttempt = 2;

    public static int $runs = 0;

    /** @var list<string> */
    public static array $failedCalls = [];

    public function __construct(public readonly int $id) {}

    public function handle(): void
    {
        self::$runs++;

        if (self::$runs <= self::$failUntilAttempt) {
            throw new RuntimeException('temporary problem ' . self::$runs);
        }
    }

    public function failed(\Throwable $e): void
    {
        self::$failedCalls[] = $e->getMessage();
    }
}

final class NeedsScalarJob implements Job
{
    public function handle(int $count): void {}
}

final class NoHandleJob implements Job {}

final class BadTriesJob implements Job
{
    public int $tries = 0;
}

final class BadBackoffJob implements Job
{
    /** @var array<mixed> */
    public array $backoff = ['soon'];
}

final class ObjectJob implements Job
{
    public function __construct(public readonly object $thing) {}
}

final class NotPromotedJob implements Job
{
    private string $stored;

    public function __construct(string $value)
    {
        $this->stored = strtoupper($value);
    }

    public function stored(): string
    {
        return $this->stored;
    }
}

final class VariadicJob implements Job
{
    /** @var list<int> */
    public readonly array $ids;

    public function __construct(int ...$ids)
    {
        $this->ids = array_values($ids);
    }
}

final class OrderPlaced
{
    public function __construct(public readonly int $orderId) {}
}

final class QueuedOrderListener implements ShouldQueue
{
    public function __construct(private readonly Recorder $recorder) {}

    public function handle(OrderPlaced $event): void
    {
        $this->recorder->calls[] = 'listener:' . $event->orderId;
    }
}

final class InvoiceMail extends Mailable
{
    public function __construct(public readonly string $email, public readonly int $invoice) {}

    public function build(Message $message): void
    {
        $message->to($this->email)->subject('Invoice ' . $this->invoice)->text('See invoice ' . $this->invoice);
    }
}

final class TimedJob implements Job
{
    public function __construct(public readonly int $timeout = 0) {}

    public function handle(): void {}
}

/**
 * A driver whose reservations expire, like the database and Redis drivers.
 */
final class ReservingDriver implements Driver, \VeyroPHP\Queue\Driver\ReservesJobs
{
    public int $pushed = 0;

    public int $calls = 0;

    public function __construct(private readonly int $retryAfter) {}

    public function retryAfter(): int
    {
        return $this->retryAfter;
    }

    public function push(string $queue, string $payload, int $delaySeconds = 0): string
    {
        return (string) ++$this->pushed;
    }

    public function pop(string $queue): ?\VeyroPHP\Queue\Driver\ReservedJob
    {
        $this->calls++;

        return null;
    }

    public function delete(\VeyroPHP\Queue\Driver\ReservedJob $job): void {}

    public function release(\VeyroPHP\Queue\Driver\ReservedJob $job, int $delaySeconds): void {}

    public function size(string $queue): int
    {
        $this->calls++;

        return $this->pushed;
    }

    public function clear(string $queue): int
    {
        return 0;
    }
}

final class MemoryFailedStore implements FailedJobStore
{
    /** @var array<int, FailedJob> */
    public array $jobs = [];

    public function record(string $connection, string $queue, string $payload, \Throwable $error): string
    {
        $id = count($this->jobs) + 1;
        $this->jobs[$id] = new FailedJob((string) $id, $connection, $queue, $payload, $error::class . ': ' . $error->getMessage(), 'now');

        return (string) $id;
    }

    public function all(int $limit = 100): array
    {
        return array_values(array_reverse($this->jobs));
    }

    public function find(string $id): ?FailedJob
    {
        return $this->jobs[(int) $id] ?? null;
    }

    public function forget(string $id): bool
    {
        $found = isset($this->jobs[(int) $id]);
        unset($this->jobs[(int) $id]);

        return $found;
    }

    public function flush(): int
    {
        $count = count($this->jobs);
        $this->jobs = [];

        return $count;
    }
}

/** A failed-jobs store that can be taken offline. */
final class OfflineFailedStore implements FailedJobStore
{
    public bool $offline = true;

    public int $attempts = 0;

    public function __construct(public readonly MemoryFailedStore $inner = new MemoryFailedStore()) {}

    public function record(string $connection, string $queue, string $payload, \Throwable $error): string
    {
        $this->attempts++;

        return $this->offline ? throw new RuntimeException('failed_jobs table unavailable') : $this->inner->record($connection, $queue, $payload, $error);
    }

    public function all(int $limit = 100): array
    {
        return $this->inner->all($limit);
    }

    public function find(string $id): ?FailedJob
    {
        return $this->inner->find($id);
    }

    public function forget(string $id): bool
    {
        return $this->inner->forget($id);
    }

    public function flush(): int
    {
        return $this->inner->flush();
    }
}

final class MemoryLog extends AbstractLogger
{
    /** @var list<array{string, string, array<mixed>}> */
    public array $records = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [is_string($level) ? $level : '?', (string) $message, $context];
    }
}

#[CoversClass(Queue::class)]
#[CoversClass(Payload::class)]
#[CoversClass(JobRunner::class)]
#[CoversClass(Worker::class)]
#[CoversClass(WorkerOptions::class)]
#[CoversClass(ArrayDriver::class)]
#[CoversClass(SyncDriver::class)]
#[CoversClass(CallQueuedListener::class)]
#[CoversClass(SendQueuedMailable::class)]
#[CoversClass(JobProcessed::class)]
#[CoversClass(JobFailed::class)]
#[CoversClass(QueueException::class)]
final class QueueTest extends TestCase
{
    private int $now = 1_793_000_000;

    private Container $container;

    private Recorder $recorder;

    private ArrayDriver $driver;

    private Queue $queue;

    private MemoryFailedStore $failed;

    private MemoryLog $log;

    private EventDispatcher $events;

    private Cache $cache;

    private int $resets = 0;

    /** @var list<int> */
    private array $slept = [];

    protected function setUp(): void
    {
        FlakyJob::$runs = 0;
        FlakyJob::$failUntilAttempt = 2;
        FlakyJob::$failedCalls = [];

        $this->container = new Container();
        $this->recorder = new Recorder();
        $this->container->instance(Recorder::class, $this->recorder);
        $this->driver = new ArrayDriver(fn(): int => $this->now);
        $payload = new Payload();
        $this->container->instance(Payload::class, $payload);

        $drivers = ['array' => $this->driver];
        $this->queue = new Queue(
            static fn(string $name): Driver => $drivers[$name] ?? throw new QueueException('No queue connection "' . $name . '"'),
            'array',
            $payload,
            ['array' => 'default'],
        );
        $this->container->instance(Queue::class, $this->queue);

        $this->failed = new MemoryFailedStore();
        $this->log = new MemoryLog();
        $this->events = new EventDispatcher($this->container, fn(): Queue => $this->queue);
        $this->cache = new Cache(new ArrayStore(), 'test');
    }

    private function worker(): Worker
    {
        return new Worker(
            $this->queue,
            new JobRunner($this->container, $this->container->make(Payload::class)),
            $this->failed,
            $this->log,
            $this->events,
            $this->cache,
            function (): void {
                $this->resets++;
            },
            function (int $seconds): void {
                $this->slept[] = $seconds;
                $this->now += $seconds;
            },
            fn(): int => $this->now,
        );
    }

    private function work(): bool
    {
        return $this->worker()->runNextJob('array', ['default']);
    }

    // Queueing

    public function testJobRoundTripWithJsonValuesAndEnums(): void
    {
        $this->queue->push(new RecordJob('a', ['ids' => [1, 2], 'note' => 'नमस्ते'], Priority::High, 2.0));

        $queued = $this->driver->jobs(RecordJob::class);
        self::assertCount(1, $queued);
        self::assertSame(3, $queued[0]['tries'], 'default tries');
        self::assertSame([], $queued[0]['backoff']);

        self::assertTrue($this->work());
        self::assertSame(['a:' . json_encode(['ids' => [1, 2], 'note' => 'नमस्ते']) . ':high:2'], $this->recorder->calls);
        self::assertSame(0, $this->driver->size('default'), 'deleted after success');
        self::assertFalse($this->work(), 'nothing left');
        self::assertSame(1, $this->resets, 'scoped services reset after the job');
    }

    public function testQueueNamesDelayAndConnections(): void
    {
        $this->queue->push(new RecordJob('emails'), 'emails');
        $this->queue->later(60, new RecordJob('later'));

        self::assertSame(1, $this->driver->size('emails'));
        self::assertFalse($this->work(), 'the delayed job is not due');
        self::assertTrue($this->worker()->runNextJob('array', ['emails', 'default']));

        $this->now += 60;
        self::assertTrue($this->work());
        self::assertSame(['emails:[]:-:1', 'later:[]:-:1'], $this->recorder->calls);

        foreach ([static fn(Queue $q) => $q->push(new RecordJob('x'), 'bad name'), static fn(Queue $q) => $q->push(new RecordJob('x'), connection: 'missing')] as $call) {
            try {
                $call($this->queue);
                self::fail('expected QueueException');
            } catch (QueueException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testJobsThatCannotBeQueuedAreRejectedWhenPushed(): void
    {
        foreach ([
            [new ObjectJob(new \stdClass()), 'a stdClass cannot be queued'],
            [new RecordJob('x', ['f' => static fn() => 1]), 'a Closure cannot be queued'],
            [new RecordJob('x', ['n' => NAN]), 'INF and NAN'],
            [new NotPromotedJob('x'), 'needs a property of the same name'],
            [new VariadicJob(1, 2), 'variadic'],
            [new BadTriesJob(), '$tries must be an integer of at least 1'],
            [new BadBackoffJob(), '$backoff must be seconds'],
            [new RecordJob(str_repeat('x', Queue::MAX_PAYLOAD_BYTES)), 'too large to queue'],
        ] as [$job, $message]) {
            try {
                $this->queue->push($job);
                self::fail('expected QueueException: ' . $message);
            } catch (QueueException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }

        self::assertSame(0, $this->driver->size('default'), 'nothing was queued');
    }

    /**
     * VQ-045: a job whose timeout is not below the connection's retry_after can be handed
     * to a second worker while it still runs, so it is refused when pushed. Jobs without
     * a timeout (long-running by design) and drivers without reservations are not affected.
     */
    public function testJobTimeoutMustBeBelowRetryAfter(): void
    {
        $driver = new ReservingDriver(90);
        $queue = new Queue(static fn(): Driver => $driver, 'database', new Payload());

        foreach ([90, 120] as $timeout) {
            try {
                $queue->push(new TimedJob($timeout));
                self::fail('timeout ' . $timeout . ' should be refused');
            } catch (QueueException $e) {
                self::assertStringContainsString('$timeout (' . $timeout . ' s) must be less than retry_after (90 s) of connection "database"', $e->getMessage());
            }
        }

        $queue->push(new TimedJob(89));
        $queue->push(new TimedJob(0));
        $queue->later(30, new TimedJob(60));
        self::assertSame(3, $driver->pushed, 'below retry_after, and no timeout at all, are accepted');

        // The array driver has no reservations that expire: no check.
        $this->queue->push(new TimedJob(10_000));
        self::assertSame(1, $this->driver->size('default'));
    }

    public function testDatabaseAndRedisDriversReportTheirRetryAfter(): void
    {
        $db = (new \VeyroPHP\Database\DatabaseManager(['m' => new \VeyroPHP\Database\Connection\ConnectionConfig('m', 'sqlite', ':memory:')], 'm'))->connection();

        self::assertSame(120, (new \VeyroPHP\Queue\Driver\DatabaseDriver($db, 'jobs', 120))->retryAfter());
        self::assertTrue((new \ReflectionClass(\VeyroPHP\Queue\Driver\RedisDriver::class))->implementsInterface(\VeyroPHP\Queue\Driver\ReservesJobs::class));
        self::assertFalse((new \ReflectionClass(ArrayDriver::class))->implementsInterface(\VeyroPHP\Queue\Driver\ReservesJobs::class));
    }

    // Retries and failures

    public function testRetriesWithBackoffThenSucceeds(): void
    {
        $this->queue->push(new FlakyJob(7));

        self::assertTrue($this->work());
        self::assertFalse($this->work(), 'released for 10 seconds');
        $this->now += 10;
        self::assertTrue($this->work());
        self::assertFalse($this->work(), 'released for 60 seconds');
        $this->now += 60;
        self::assertTrue($this->work());

        self::assertSame(3, FlakyJob::$runs);
        self::assertSame(0, $this->driver->size('default'));
        self::assertSame([], $this->failed->jobs);
        self::assertSame(['warning', 'warning', 'info'], array_column($this->log->records, 0));
        self::assertSame(10, $this->log->records[0][2]['retry_in']);
        self::assertSame(60, $this->log->records[1][2]['retry_in']);
    }

    public function testFailsAfterTheLastTry(): void
    {
        FlakyJob::$failUntilAttempt = 99;
        $failedEvents = [];
        $this->events->listen(JobFailed::class, static function (JobFailed $e) use (&$failedEvents): void {
            $failedEvents[] = $e;
        });
        $this->queue->push(new FlakyJob(7));

        for ($i = 0; $i < 3; $i++) {
            self::assertTrue($this->work());
            $this->now += 100;
        }

        self::assertSame(3, FlakyJob::$runs);
        self::assertSame(0, $this->driver->size('default'), 'removed from the queue');
        self::assertCount(1, $this->failed->jobs);
        $failed = $this->failed->jobs[1];
        self::assertSame(FlakyJob::class, $failed->jobClass());
        self::assertSame('RuntimeException: temporary problem 3', $failed->exception);
        self::assertSame(['temporary problem 3'], FlakyJob::$failedCalls, 'failed() called once');
        self::assertCount(1, $failedEvents);
        self::assertSame(FlakyJob::class, $failedEvents[0]->jobClass);
        self::assertSame('error', $this->log->records[2][0]);
    }

    /**
     * SVC-1: a JobFailed listener that throws (an alerting service that is down) is
     * reported; it neither stops the worker nor records the failure twice.
     */
    public function testAThrowingJobFailedListenerDoesNotStopTheWorker(): void
    {
        FlakyJob::$failUntilAttempt = 99;
        $calls = 0;
        $this->events->listen(JobFailed::class, static function () use (&$calls): void {
            $calls++;

            throw new RuntimeException('alerting service down');
        });
        $this->queue->push(new FlakyJob(7));
        $this->queue->push(new RecordJob('after the failure'));

        for ($i = 0; $i < 4; $i++) {
            self::assertTrue($this->work(), 'run ' . ($i + 1));
            $this->now += 100;
        }

        self::assertCount(1, $this->failed->jobs, 'recorded once');
        self::assertSame(1, $calls, 'JobFailed dispatched once');
        self::assertSame(['temporary problem 3'], FlakyJob::$failedCalls, 'failed() called once');
        self::assertCount(1, $this->recorder->calls, 'the worker went on with the next job');
        self::assertSame(0, $this->driver->size('default'));
        $listenerErrors = array_filter($this->log->records, static fn(array $r): bool => str_contains($r[1], 'listener') && str_contains((string) json_encode($r[2]), 'alerting service down'));
        self::assertCount(1, $listenerErrors, 'the listener error is logged');
    }

    /**
     * SVC-1: in the "attempted more often than its tries" branch a throwing listener
     * used to make the worker fail the job a second time (two rows, two events).
     */
    public function testAnExhaustedJobWithAThrowingListenerIsFailedOnce(): void
    {
        $calls = 0;
        $this->events->listen(JobFailed::class, static function () use (&$calls): void {
            $calls++;

            throw new RuntimeException('alerting service down');
        });
        $this->queue->push(new FlakyJob(7));
        for ($i = 0; $i < 3; $i++) {
            $reserved = $this->driver->pop('default');
            self::assertNotNull($reserved);
            $this->driver->release($reserved, 0);
        }

        self::assertTrue($this->work());

        self::assertCount(1, $this->failed->jobs);
        self::assertSame(1, $calls);
        self::assertSame(0, FlakyJob::$runs);
        self::assertSame(0, $this->driver->size('default'));
    }

    /**
     * SVC-1: when the failed-jobs store cannot record the failure, the job is not
     * deleted (it would be lost). It stays in the queue; failed() and JobFailed wait
     * until the failure is recorded, which happens when the store is back.
     */
    public function testAJobIsKeptWhenTheFailedStoreIsDownAndRecordedOnceItIsBack(): void
    {
        $store = new OfflineFailedStore();
        $events = 0;
        $this->events->listen(JobFailed::class, static function () use (&$events): void {
            $events++;
        });
        $worker = new Worker($this->queue, new JobRunner($this->container, $this->container->make(Payload::class)), $store, $this->log, $this->events, $this->cache);
        FlakyJob::$failUntilAttempt = 99;
        $this->queue->push(new FlakyJob(7));

        for ($i = 0; $i < 3; $i++) {
            self::assertTrue($worker->runNextJob('array', ['default']));
            $this->now += 1000;
        }

        self::assertSame(1, $store->attempts);
        self::assertSame([], $store->inner->jobs);
        self::assertSame(1, $this->driver->size('default'), 'the job is still in the queue');
        self::assertSame([], FlakyJob::$failedCalls, 'failed() waits until the failure is recorded');
        self::assertSame(0, $events);
        self::assertNotEmpty(array_filter($this->log->records, static fn(array $r): bool => $r[0] === 'error' && str_contains($r[1], 'could not be recorded')));

        // The store is back: the job comes round again, is not run a fourth time, and fails once.
        $store->offline = false;
        self::assertTrue($worker->runNextJob('array', ['default']));

        self::assertSame(3, FlakyJob::$runs, 'not run again');
        self::assertCount(1, $store->inner->jobs);
        self::assertSame(1, $events);
        self::assertCount(1, FlakyJob::$failedCalls);
        self::assertSame(0, $this->driver->size('default'));
    }

    public function testALoggerThatFailsWhileFailingAJobDoesNotStopTheWorker(): void
    {
        $log = new class extends AbstractLogger {
            public function log($level, string|Stringable $message, array $context = []): void
            {
                throw new RuntimeException('disk full');
            }
        };
        $worker = new Worker($this->queue, new JobRunner($this->container, $this->container->make(Payload::class)), $this->failed, $log, $this->events, $this->cache);
        FlakyJob::$failUntilAttempt = 99;
        $this->queue->push(new FlakyJob(1));

        for ($i = 0; $i < 3; $i++) {
            self::assertTrue($worker->runNextJob('array', ['default']));
            $this->now += 100;
        }

        self::assertCount(1, $this->failed->jobs);
        self::assertSame(0, $this->driver->size('default'));
    }

    public function testJobAttemptedMoreThanItsTriesFailsWithoutRunning(): void
    {
        // A worker crashed (or hit the timeout) on every attempt: the job never finished.
        $this->queue->push(new FlakyJob(7));
        for ($i = 0; $i < 3; $i++) {
            $reserved = $this->driver->pop('default');
            self::assertNotNull($reserved);
            $this->driver->release($reserved, 0);
        }

        self::assertTrue($this->work());

        self::assertSame(0, FlakyJob::$runs, 'not run a fourth time');
        self::assertStringContainsString('attempted 4 times (tries: 3)', $this->failed->jobs[1]->exception);
    }

    public function testBrokenJobsFailClearly(): void
    {
        $this->driver->push('default', 'not json');
        $this->queue->push(new NoHandleJob());
        $this->queue->push(new NeedsScalarJob());
        $this->driver->push('default', (string) json_encode(['uuid' => 'x', 'job' => ['class' => 'App\\Jobs\\Deleted', 'data' => []], 'tries' => 1, 'backoff' => [], 'timeout' => 0]));

        for ($i = 0; $i < 4; $i++) {
            self::assertTrue($this->work());
        }

        $errors = array_map(static fn(FailedJob $j): string => $j->exception, array_values($this->failed->jobs));
        self::assertCount(4, $errors);
        self::assertStringContainsString('Invalid queued job payload', $errors[0]);
        self::assertStringContainsString('needs a handle() method', $errors[1]);
        self::assertStringContainsString('$count cannot be injected', $errors[2]);
        self::assertStringContainsString('App\\Jobs\\Deleted does not exist', $errors[3]);
    }

    public function testProcessedEventAndLogWithoutPayload(): void
    {
        $processed = [];
        $this->events->listen(JobProcessed::class, static function (JobProcessed $e) use (&$processed): void {
            $processed[] = $e->jobClass . '#' . $e->attempts;
        });
        $this->queue->push(new RecordJob('secret-value'));

        $this->work();

        self::assertSame([RecordJob::class . '#1'], $processed);
        self::assertStringNotContainsString('secret-value', (string) json_encode($this->log->records));
    }

    /**
     * VQ-043: once handle() returned, the job's work is done. A failure in what the
     * worker does afterwards (an event listener, the log) must not mark it failed,
     * call failed(), record it in failed_jobs or run it again.
     */
    public function testBookkeepingErrorsAfterASuccessfulJobDoNotFailOrRepeatIt(): void
    {
        $this->events->listen(JobProcessed::class, static function (): void {
            throw new RuntimeException('metrics listener down');
        });
        $this->queue->push(new RecordJob('charge order 42'));

        self::assertTrue($this->work());
        self::assertFalse($this->work(), 'not queued again');

        self::assertCount(1, $this->recorder->calls, 'the work ran exactly once');
        self::assertSame(0, $this->driver->size('default'), 'removed from the queue');
        self::assertSame([], $this->failed->jobs, 'not recorded as failed');
        $errors = array_values(array_filter($this->log->records, static fn(array $r): bool => $r[0] === 'error'));
        self::assertCount(1, $errors);
        self::assertStringContainsString('after the job completed', $errors[0][1]);
        self::assertSame(RecordJob::class, $errors[0][2]['job'] ?? null);
        self::assertStringContainsString('metrics listener down', is_string($errors[0][2]['error'] ?? null) ? $errors[0][2]['error'] : '');
    }

    public function testALogFailureAfterASuccessfulJobDoesNotFailIt(): void
    {
        $log = new class extends AbstractLogger {
            public function log($level, string|Stringable $message, array $context = []): void
            {
                throw new RuntimeException('disk full');
            }
        };
        $worker = new Worker($this->queue, new JobRunner($this->container, $this->container->make(Payload::class)), $this->failed, $log, $this->events, $this->cache);
        FlakyJob::$failUntilAttempt = 0;
        $this->queue->push(new FlakyJob(1));

        self::assertTrue($worker->runNextJob('array', ['default']));

        self::assertSame(1, FlakyJob::$runs);
        self::assertSame(0, $this->driver->size('default'));
        self::assertSame([], $this->failed->jobs);
        self::assertSame([], FlakyJob::$failedCalls, 'failed() is not called for a job that succeeded');
    }

    public function testAJobThatCannotBeRemovedAfterSuccessIsReportedNotFailed(): void
    {
        $inner = $this->driver;
        $driver = new class ($inner) implements Driver {
            public function __construct(private readonly ArrayDriver $inner) {}

            public function push(string $queue, string $payload, int $delaySeconds = 0): string
            {
                return $this->inner->push($queue, $payload, $delaySeconds);
            }

            public function pop(string $queue): ?\VeyroPHP\Queue\Driver\ReservedJob
            {
                return $this->inner->pop($queue);
            }

            public function delete(\VeyroPHP\Queue\Driver\ReservedJob $job): void
            {
                throw new RuntimeException('database gone');
            }

            public function release(\VeyroPHP\Queue\Driver\ReservedJob $job, int $delaySeconds): void
            {
                $this->inner->release($job, $delaySeconds);
            }

            public function size(string $queue): int
            {
                return $this->inner->size($queue);
            }

            public function clear(string $queue): int
            {
                return $this->inner->clear($queue);
            }
        };
        $queue = new Queue(static fn(): Driver => $driver, 'array', $this->container->make(Payload::class), ['array' => 'default']);
        $worker = new Worker($queue, new JobRunner($this->container, $this->container->make(Payload::class)), $this->failed, $this->log, $this->events, $this->cache);
        FlakyJob::$failUntilAttempt = 0;
        $queue->push(new FlakyJob(1));

        self::assertTrue($worker->runNextJob('array', ['default']));

        // The work is done but the queue still holds the reservation: the documented crash
        // window. It may run again after retry_after; it is not failed or released now.
        self::assertSame(1, FlakyJob::$runs);
        self::assertSame([], $this->failed->jobs);
        self::assertSame([], FlakyJob::$failedCalls);
        $errors = array_values(array_filter($this->log->records, static fn(array $r): bool => $r[0] === 'error'));
        self::assertCount(1, $errors);
        self::assertStringContainsString('could not be removed from the queue', $errors[0][1]);
    }

    // Worker loop

    /**
     * VQ-045 (partly): without pcntl (Windows) job timeouts cannot be enforced, so a hung
     * job keeps its worker busy and its reservation expires after retry_after. The worker
     * must say so when it starts instead of failing silently.
     */
    public function testWorkerWarnsAtStartWhenTimeoutsCannotBeEnforced(): void
    {
        $this->worker()->daemon('array', ['default'], new WorkerOptions(stopWhenEmpty: true));

        $warnings = array_values(array_filter($this->log->records, static fn(array $r): bool => $r[0] === 'warning' && str_contains($r[1], 'timeouts are not enforced')));

        if (function_exists('pcntl_alarm') && function_exists('pcntl_signal')) {
            self::assertSame([], $warnings);
        } else {
            self::assertCount(1, $warnings);
            self::assertStringContainsString('retry_after', $warnings[0][1]);
        }
    }

    public function testDaemonStopConditions(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->queue->push(new RecordJob((string) $i));
        }

        self::assertSame('processed 2 jobs (--max-jobs)', $this->worker()->daemon('array', ['default'], new WorkerOptions(maxJobs: 2)));
        self::assertSame('queue empty (--stop-when-empty)', $this->worker()->daemon('array', ['default'], new WorkerOptions(stopWhenEmpty: true)));
        self::assertCount(5, $this->recorder->calls);

        $worker = $this->worker();
        $this->cache->set(Worker::RESTART_KEY, 'before');
        $this->queue->push(new RecordJob('during'));
        // The restart signal changes while the worker runs (simulated through the sleep hook).
        $this->events->listen(JobProcessed::class, function (): void {
            $this->cache->set(Worker::RESTART_KEY, 'after');
        });
        self::assertSame('restart requested (queue:restart)', $worker->daemon('array', ['default'], new WorkerOptions()));

        self::assertSame('ran 5 seconds (--max-time)', $this->worker()->daemon('array', ['default'], new WorkerOptions(sleep: 1, maxTime: 5)));
        self::assertNotSame([], $this->slept, 'slept while the queue was empty');
    }

    // Sync driver

    public function testSyncDriverRunsAtOnceAndThrowsToTheCaller(): void
    {
        $runner = new JobRunner($this->container, new Payload());
        $sync = new SyncDriver(static function (string $payload) use ($runner): void {
            $runner->handle($runner->instantiate(JobRunner::decode($payload)['job']));
        });
        $queue = new Queue(static fn(): SyncDriver => $sync, 'sync', new Payload());

        $queue->push(new RecordJob('now'));
        self::assertSame(['now:[]:-:1'], $this->recorder->calls);

        $this->expectException(RuntimeException::class);
        $queue->push(new FlakyJob(1));
    }

    // Queued listeners and mail

    public function testShouldQueueListenerRunsInTheWorker(): void
    {
        $this->events->listen(OrderPlaced::class, QueuedOrderListener::class);

        $this->events->dispatch(new OrderPlaced(42));
        self::assertSame([], $this->recorder->calls, 'not run during the request');
        self::assertCount(1, $this->driver->jobs(CallQueuedListener::class));

        $this->work();
        self::assertSame(['listener:42'], $this->recorder->calls);
    }

    public function testAQueuedListenerWithoutHandleOrInvokeFailsWithAQueueException(): void
    {
        $job = new CallQueuedListener(\stdClass::class, (new Payload())->pack(new OrderPlaced(1)));

        $this->expectException(QueueException::class);
        $this->expectExceptionMessage('Listener stdClass needs a public handle() or __invoke() method.');
        $job->handle($this->container, new Payload());
    }

    public function testShouldQueueListenerWithoutQueueFails(): void
    {
        $events = new EventDispatcher($this->container);
        $events->listen(OrderPlaced::class, QueuedOrderListener::class);

        $this->expectException(EventException::class);
        $events->dispatch(new OrderPlaced(1));
    }

    public function testQueuedMailIsSentByTheWorker(): void
    {
        $transport = new ArrayTransport();
        $mailer = new Mailer(static fn(): ArrayTransport => $transport, 'array', new Address('shop@example.com'), queue: fn(): Queue => $this->queue);
        $this->container->instance(Mailer::class, $mailer);

        $id = $mailer->queue(new InvoiceMail('asha@example.com', 42));

        self::assertSame([], $transport->messages(), 'not sent during the request');
        $queued = $this->driver->jobs(SendQueuedMailable::class);
        self::assertSame($id, '1');
        self::assertCount(1, $queued);
        self::assertSame(3, $queued[0]['tries']);
        self::assertSame([30, 120, 600], $queued[0]['backoff']);

        $this->work();
        self::assertSame('Invoice 42', $transport->last()?->message->getSubject());
        self::assertSame(['asha@example.com'], $transport->last()->recipients);
    }

    /**
     * VQ-044: a queued mail that was delivered is not delivered again when a
     * MessageSent listener throws (SendQueuedMailable has three tries).
     */
    public function testQueuedMailIsDeliveredOnceWhenAListenerFailsAfterDelivery(): void
    {
        $transport = new ArrayTransport();
        $events = new EventDispatcher($this->container);
        $events->listen(\VeyroPHP\Mail\Events\MessageSent::class, static function (): void {
            throw new RuntimeException('listener down');
        });
        $mailer = new Mailer(static fn(): ArrayTransport => $transport, 'array', new Address('shop@example.com'), events: $events, queue: fn(): Queue => $this->queue);
        $this->container->instance(Mailer::class, $mailer);
        $mailer->queue(new InvoiceMail('asha@example.com', 42));

        for ($i = 0; $i < 3; $i++) {
            $this->work();
            $this->now += 1_000; // past every backoff
        }

        self::assertCount(1, $transport->messages(), 'delivered exactly once');
        self::assertSame([], $this->failed->jobs);
        self::assertSame(0, $this->driver->size('default'));
    }

    public function testQueueingMailWithoutQueueOrWithBadDataFails(): void
    {
        $mailer = new Mailer(static fn(): ArrayTransport => new ArrayTransport(), 'array', new Address('shop@example.com'));

        try {
            $mailer->queue(new InvoiceMail('a@example.com', 1));
            self::fail('expected MailException');
        } catch (MailException $e) {
            self::assertStringContainsString('QueueServiceProvider', $e->getMessage());
        }

        $queued = new Mailer(static fn(): ArrayTransport => new ArrayTransport(), 'array', queue: fn(): Queue => $this->queue);
        $this->expectException(QueueException::class);
        $queued->queue(new class extends Mailable {
            public function build(Message $message): void {}
        });
    }
}
