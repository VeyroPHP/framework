<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Integration\Queue;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use VeyroPHP\Database\Connection\Connection;
use VeyroPHP\Database\Connection\ConnectionConfig;
use VeyroPHP\Database\DatabaseManager;
use VeyroPHP\Database\Schema\Schema;
use VeyroPHP\Queue\Driver\DatabaseDriver;
use VeyroPHP\Queue\Failed\DatabaseFailedJobStore;
use VeyroPHP\Queue\QueueSchema;

/**
 * The database queue driver and failed-job store on a real database. Subclasses
 * pick the database; MySQL and PostgreSQL also run a multi-process test.
 */
abstract class DatabaseQueueTestCase extends TestCase
{
    protected Connection $db;

    protected int $now = 1_793_000_000;

    private const JOBS = 'test_queue_jobs';

    private const FAILED = 'test_queue_failed';

    /**
     * @return array<string, mixed> connection settings, or skip the test
     */
    abstract protected static function settings(): array;

    protected function setUp(): void
    {
        $settings = static::settings();
        $this->db = (new DatabaseManager(['test' => ConnectionConfig::fromArray('test', $settings)], 'test'))->connection();
        $schema = new Schema($this->db);
        QueueSchema::down($schema, self::JOBS, self::FAILED);
        QueueSchema::up($schema, self::JOBS, self::FAILED);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            QueueSchema::down(new Schema($this->db), self::JOBS, self::FAILED);
        }
    }

    protected function driver(int $retryAfter = 90): DatabaseDriver
    {
        return new DatabaseDriver($this->db, self::JOBS, $retryAfter, fn(): int => $this->now);
    }

    public function testPushPopDeleteInOrder(): void
    {
        $driver = $this->driver();
        $first = $driver->push('default', '{"n":1}');
        $driver->push('default', '{"n":2}');
        $driver->push('other', '{"n":3}');

        self::assertSame(2, $driver->size('default'));

        $job = $driver->pop('default');
        self::assertNotNull($job);
        self::assertSame([$first, 'default', '{"n":1}', 1], [$job->id, $job->queue, $job->payload, $job->attempts]);

        $second = $driver->pop('default');
        self::assertSame('{"n":2}', $second?->payload, 'the first job is reserved, so the next one comes');
        self::assertNull($driver->pop('default'), 'both reserved');

        $driver->delete($job);
        self::assertSame(1, $driver->size('default'));
        self::assertSame('{"n":3}', $driver->pop('other')?->payload, 'queues are separate');
    }

    public function testDelayedJobsWaitUntilDue(): void
    {
        $driver = $this->driver();
        $driver->push('default', 'later', 60);

        self::assertNull($driver->pop('default'));
        $this->now += 59;
        self::assertNull($driver->pop('default'));
        $this->now += 1;
        self::assertSame('later', $driver->pop('default')?->payload);
    }

    public function testReleaseKeepsAttemptsAndHonoursDelay(): void
    {
        $driver = $this->driver();
        $driver->push('default', 'job');

        $first = $driver->pop('default');
        self::assertNotNull($first);
        $driver->release($first, 30);

        self::assertNull($driver->pop('default'));
        $this->now += 30;
        $second = $driver->pop('default');
        self::assertSame(2, $second?->attempts);
    }

    public function testAbandonedReservationIsTakenOverAndTheOldWorkerCannotDeleteIt(): void
    {
        $driver = $this->driver(retryAfter: 90);
        $driver->push('default', 'job');

        $crashed = $driver->pop('default');
        self::assertNotNull($crashed);

        $this->now += 89;
        self::assertNull($driver->pop('default'), 'still reserved');

        $this->now += 1;
        $takeover = $driver->pop('default');
        self::assertNotNull($takeover);
        self::assertSame(2, $takeover->attempts);

        // The first worker comes back to life and tries to finish its old reservation.
        $driver->delete($crashed);
        $driver->release($crashed, 0);
        self::assertSame(1, $driver->size('default'), 'the old reservation no longer owns the job');
        self::assertNull($driver->pop('default'), 'and could not release it either');

        $driver->delete($takeover);
        self::assertSame(0, $driver->size('default'));
    }

    public function testClear(): void
    {
        $driver = $this->driver();
        $driver->push('a', '1');
        $driver->push('a', '2');
        $driver->push('b', '3');

        self::assertSame(2, $driver->clear('a'));
        self::assertSame(0, $driver->size('a'));
        self::assertSame(1, $driver->size('b'));
    }

    public function testFailedJobStore(): void
    {
        $store = new DatabaseFailedJobStore($this->db, self::FAILED);
        $payload = '{"job":{"class":"App\\\\Jobs\\\\SendInvoice","data":[]}}';

        $id = $store->record('database', 'emails', $payload, new RuntimeException('SMTP down'));
        $store->record('database', 'default', '{}', new RuntimeException(str_repeat('x', 40_000)));

        $all = $store->all();
        self::assertCount(2, $all);
        self::assertSame($id, $all[1]->id, 'newest first');

        $job = $store->find($id);
        self::assertNotNull($job);
        self::assertSame(['database', 'emails', $payload, 'App\\Jobs\\SendInvoice'], [$job->connection, $job->queue, $job->payload, $job->jobClass()]);
        self::assertStringStartsWith("RuntimeException: SMTP down\nin ", $job->exception);
        self::assertLessThanOrEqual(30_010, strlen($all[0]->exception), 'long errors are cut');

        self::assertTrue($store->forget($id));
        self::assertFalse($store->forget($id));
        self::assertNull($store->find($id));
        self::assertSame(1, $store->flush());
        self::assertSame([], $store->all());
    }

    /**
     * SVC-1: a job whose failure was recorded but not yet removed from the queue (the
     * process died, or delete() failed) comes back and is failed again. Recording the
     * same payload again returns the existing entry instead of a duplicate.
     */
    public function testRecordingTheSameFailedJobTwiceKeepsOneEntry(): void
    {
        $store = new DatabaseFailedJobStore($this->db, self::FAILED);
        $payload = '{"uuid":"0f3c","job":{"class":"App\\\\Jobs\\\\SendInvoice","data":[]},"tries":3}';

        $first = $store->record('database', 'emails', $payload, new RuntimeException('SMTP down'));
        $second = $store->record('database', 'emails', $payload, new RuntimeException('attempted 4 times'));

        self::assertSame($first, $second);
        self::assertCount(1, $store->all());

        // Another job (another uuid) or another queue is a separate entry.
        self::assertNotSame($first, $store->record('database', 'emails', str_replace('0f3c', '9a1b', $payload), new RuntimeException('x')));
        self::assertNotSame($first, $store->record('database', 'other', $payload, new RuntimeException('x')));
        self::assertCount(3, $store->all());
    }

    /**
     * The PHP command for worker processes, with the PDO driver this process has
     * loaded (test scripts may load it with -d instead of php.ini).
     *
     * @return list<string>
     */
    private static function php(): array
    {
        $name = static::settings()['driver'] ?? null;
        $driver = 'pdo_' . (is_string($name) ? $name : '');

        if (!extension_loaded($driver)) {
            return [PHP_BINARY];
        }

        $probe = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg("echo extension_loaded('{$driver}') ? 'yes' : 'no';"));

        return trim((string) $probe) === 'yes' ? [PHP_BINARY] : [PHP_BINARY, '-d', 'extension=' . $driver];
    }

    /**
     * Several processes take jobs at the same time; each job must be taken exactly once.
     */
    protected function assertConcurrentWorkersTakeEachJobOnce(int $workers = 4, int $jobs = 200): void
    {
        $driver = new DatabaseDriver($this->db, self::JOBS);

        for ($i = 1; $i <= $jobs; $i++) {
            $driver->push('default', (string) $i);
        }

        $go = sys_get_temp_dir() . '/veyrophp-queue-go-' . bin2hex(random_bytes(4));
        $processes = [];

        for ($w = 0; $w < $workers; $w++) {
            $process = proc_open(
                [...self::php(), dirname(__DIR__, 2) . '/Fixtures/Queue/claim-worker.php', json_encode(static::settings(), JSON_THROW_ON_ERROR), self::JOBS, 'default', $go],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );
            self::assertIsResource($process);
            $processes[] = [$process, $pipes];
        }

        touch($go);
        $taken = [];
        $perWorker = [];

        try {
            foreach ($processes as [$process, $pipes]) {
                $output = (string) stream_get_contents($pipes[1]);
                $errors = (string) stream_get_contents($pipes[2]);
                self::assertSame(0, proc_close($process), 'worker failed: ' . $errors);
                $lines = array_values(array_filter(explode("\n", trim($output)), static fn(string $l): bool => $l !== ''));
                $perWorker[] = count($lines);
                array_push($taken, ...$lines);
            }
        } finally {
            unlink($go);
        }

        sort($taken, SORT_NUMERIC);
        self::assertSame(array_map('strval', range(1, $jobs)), $taken, 'every job exactly once');
        self::assertSame(0, $driver->size('default'));
        self::assertGreaterThan(1, count(array_filter($perWorker)), 'more than one worker took jobs (otherwise the test proves nothing)');
    }
}
