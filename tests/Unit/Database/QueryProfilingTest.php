<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Unit\Database;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use VeyroPHP\Application\Application;
use VeyroPHP\Config\Config;
use VeyroPHP\Database\Connection\Connection;
use VeyroPHP\Database\Connection\ConnectionConfig;
use VeyroPHP\Database\DatabaseManager;
use VeyroPHP\Database\DatabaseServiceProvider;
use VeyroPHP\Database\Exception\QueryException;
use VeyroPHP\Database\Profiling\ProfileQueriesMiddleware;
use VeyroPHP\Database\Profiling\QueryExecuted;
use VeyroPHP\Database\Profiling\QueryLogger;
use VeyroPHP\Database\Profiling\QueryProfiler;
use VeyroPHP\Http\CallableHandler;
use VeyroPHP\Tests\Fixtures\Logging\ArrayLogger;

#[CoversClass(QueryProfiler::class)]
#[CoversClass(QueryLogger::class)]
#[CoversClass(QueryExecuted::class)]
#[CoversClass(ProfileQueriesMiddleware::class)]
#[CoversClass(Connection::class)]
#[CoversClass(DatabaseManager::class)]
#[CoversClass(DatabaseServiceProvider::class)]
final class QueryProfilingTest extends TestCase
{
    private static function memoryManager(): DatabaseManager
    {
        return new DatabaseManager(['memory' => new ConnectionConfig('memory', 'sqlite', ':memory:')], 'memory');
    }

    private static function event(string $sql, float $ms = 1.0, int $bindings = 0, bool $failed = false): QueryExecuted
    {
        return new QueryExecuted('main', $sql, $bindings, $ms, $failed);
    }

    // QueryProfiler

    public function testCountsTotalsAndSlowQueries(): void
    {
        $profiler = new QueryProfiler(slowThresholdMs: 50.0);

        $profiler->record(self::event('select 1', 10.0));
        $profiler->record(self::event('select 2', 50.0));
        $profiler->record(self::event('select 3', 120.5));

        self::assertSame(3, $profiler->count());
        self::assertEqualsWithDelta(180.5, $profiler->totalTimeMs(), 0.0001);
        self::assertSame(['select 2', 'select 3'], array_map(static fn(QueryExecuted $q): string => $q->sql, $profiler->slowQueries()));
        self::assertCount(3, $profiler->queries());
    }

    public function testRepeatedQueriesAreSortedByFrequency(): void
    {
        $profiler = new QueryProfiler();

        foreach (['a', 'b', 'b', 'c', 'c', 'c', 'b', 'c'] as $sql) {
            $profiler->record(self::event('select ' . $sql));
        }

        self::assertSame(['select c' => 4, 'select b' => 3], $profiler->repeatedQueries(3));
        self::assertSame(['select c' => 4, 'select b' => 3, 'select a' => 1], $profiler->repeatedQueries(1));
    }

    public function testStorageIsCappedButCountsStayExact(): void
    {
        $profiler = new QueryProfiler(slowThresholdMs: 0.0, maxStored: 10);

        for ($i = 0; $i < 25; $i++) {
            $profiler->record(self::event('select ?', 1.0, 1));
        }

        self::assertSame(25, $profiler->count());
        self::assertCount(10, $profiler->queries());
        self::assertCount(10, $profiler->slowQueries());
        self::assertSame(['select ?' => 25], $profiler->repeatedQueries());
    }

    public function testReset(): void
    {
        $profiler = new QueryProfiler(slowThresholdMs: 0.0);
        $profiler->record(self::event('select 1'));
        $profiler->reset();

        self::assertSame(0, $profiler->count());
        self::assertSame(0.0, $profiler->totalTimeMs());
        self::assertSame([], $profiler->queries());
        self::assertSame([], $profiler->slowQueries());
        self::assertSame([], $profiler->repeatedQueries(1));
    }

    // Connection events

    public function testConnectionEmitsEventsWithoutValues(): void
    {
        $db = self::memoryManager()->connection();
        /** @var list<QueryExecuted> $events */
        $events = [];
        $db->listen(static function (QueryExecuted $event) use (&$events): void {
            $events[] = $event;
        });

        $db->statement('create table t (id integer primary key, secret text)');
        $db->table('t')->insert(['secret' => 'hunter2']);
        $db->table('t')->where('secret', 'hunter2')->first();

        self::assertCount(3, $events);
        self::assertSame('insert into "t" ("secret") values (?)', $events[1]->sql);
        self::assertSame(1, $events[1]->bindingCount);
        self::assertSame('memory', $events[1]->connection);
        self::assertGreaterThan(0.0, $events[2]->durationMs);
        self::assertFalse($events[2]->failed);
        self::assertStringNotContainsString('hunter2', serialize($events));
    }

    public function testFailedQueriesEmitFailedEvent(): void
    {
        $db = self::memoryManager()->connection();
        $profiler = new QueryProfiler();
        $db->listen($profiler->listener());

        try {
            $db->select('select * from missing');
            self::fail('Expected QueryException.');
        } catch (QueryException) {
        }

        self::assertSame(1, $profiler->count());
        self::assertTrue($profiler->queries()[0]->failed);
    }

    public function testSavepointsAreReportedAsQueries(): void
    {
        $db = self::memoryManager()->connection();
        $profiler = new QueryProfiler();
        $db->listen($profiler->listener());

        $db->transaction(static fn(Connection $db) => $db->transaction(static fn() => null));

        self::assertSame(
            ['savepoint "veyrophp_savepoint_1"', 'release savepoint "veyrophp_savepoint_1"'],
            array_map(static fn(QueryExecuted $q): string => $q->sql, $profiler->queries()),
        );
    }

    public function testManagerListenerReachesExistingAndFutureConnections(): void
    {
        $manager = new DatabaseManager([
            'a' => new ConnectionConfig('a', 'sqlite', ':memory:'),
            'b' => new ConnectionConfig('b', 'sqlite', ':memory:'),
        ], 'a');
        $profiler = new QueryProfiler();

        $existing = $manager->connection('a');
        $manager->listen($profiler->listener());
        $later = $manager->connection('b');

        $existing->select('select 1');
        $later->select('select 2');

        self::assertSame(2, $profiler->count());
    }

    public function testNPlusOneShowsUpAsRepeatedQueryAndWhereInFixesIt(): void
    {
        $db = self::memoryManager()->connection();
        $db->statement('create table authors (id integer primary key, name text)');
        $db->statement('create table books (id integer primary key, author_id integer, title text)');
        foreach (range(1, 10) as $i) {
            $db->table('authors')->insert(['id' => $i, 'name' => 'Author ' . $i]);
            $db->table('books')->insert(['author_id' => $i, 'title' => 'Book ' . $i]);
        }

        $profiler = new QueryProfiler();
        $db->listen($profiler->listener());

        // N+1: one query for authors, then one per author.
        foreach ($db->table('authors')->get() as $author) {
            $db->table('books')->where('author_id', $author['id'])->get();
        }

        self::assertSame(11, $profiler->count());
        self::assertSame(['select * from "books" where "author_id" = ?' => 10], $profiler->repeatedQueries(5));

        // Fixed: two queries in total.
        $profiler->reset();
        $authors = $db->table('authors')->get();
        $db->table('books')->whereIn('author_id', array_column($authors, 'id'))->get();

        self::assertSame(2, $profiler->count());
        self::assertSame([], $profiler->repeatedQueries(2));
    }

    // QueryLogger

    public function testLoggerLogsSlowQueriesAsWarningsWithoutValues(): void
    {
        $log = new ArrayLogger();
        $logger = new QueryLogger($log, slowThresholdMs: 100.0);

        $logger(self::event('select * from users where id = ?', 5.0, 1));
        $logger(self::event('select * from orders where total > ?', 250.0, 1));
        $logger(self::event('select broken', 500.0, 0, true));

        self::assertCount(1, $log->records);
        self::assertSame('warning', $log->records[0]['level']);
        self::assertSame('Slow query ({duration_ms} ms): {sql}', $log->records[0]['message']);
        self::assertSame(
            ['sql' => 'select * from orders where total > ?', 'binding_count' => 1, 'duration_ms' => 250.0, 'connection' => 'main'],
            $log->records[0]['context'],
        );
    }

    public function testLoggerCanLogAllQueriesAtDebug(): void
    {
        $log = new ArrayLogger();
        (new QueryLogger($log, 100.0, logAllQueries: true))(self::event('select 1', 1.0));

        self::assertSame('debug', $log->records[0]['level']);
    }

    // Middleware

    private static function handlerRunning(Connection $db, int $queries): CallableHandler
    {
        return new CallableHandler(static function () use ($db, $queries): ResponseInterface {
            for ($i = 0; $i < $queries; $i++) {
                $db->select('select ? as n', [$i]);
            }

            return new Response(200);
        });
    }

    public function testMiddlewareWarnsAboutRepeatedQueriesAndResetsPerRequest(): void
    {
        $manager = self::memoryManager();
        $profiler = new QueryProfiler();
        $manager->listen($profiler->listener());
        $log = new ArrayLogger();
        $middleware = new ProfileQueriesMiddleware($profiler, $log, repeatThreshold: 5);

        $middleware->process(new ServerRequest('GET', '/books'), self::handlerRunning($manager->connection(), 6));
        $middleware->process(new ServerRequest('GET', '/home'), self::handlerRunning($manager->connection(), 2));

        self::assertCount(1, $log->records);
        self::assertSame('warning', $log->records[0]['level']);
        self::assertSame('/books', $log->records[0]['context']['path']);
        self::assertSame(['select ? as n' => 6], $log->records[0]['context']['repeated']);
        self::assertSame(2, $profiler->count(), 'profiler must be reset for the second request');
    }

    public function testMiddlewareHeadersOnlyWhenEnabled(): void
    {
        $manager = self::memoryManager();
        $profiler = new QueryProfiler();
        $manager->listen($profiler->listener());

        $hidden = (new ProfileQueriesMiddleware($profiler, new ArrayLogger()))
            ->process(new ServerRequest('GET', '/'), self::handlerRunning($manager->connection(), 3));
        $shown = (new ProfileQueriesMiddleware($profiler, new ArrayLogger(), exposeHeaders: true))
            ->process(new ServerRequest('GET', '/'), self::handlerRunning($manager->connection(), 3));

        self::assertFalse($hidden->hasHeader('X-Query-Count'));
        self::assertSame('3', $shown->getHeaderLine('X-Query-Count'));
        self::assertMatchesRegularExpression('/^\d+\.\d{2}$/', $shown->getHeaderLine('X-Query-Time-Ms'));
    }

    public function testMiddlewareReportsEvenWhenHandlerThrows(): void
    {
        $manager = self::memoryManager();
        $profiler = new QueryProfiler();
        $manager->listen($profiler->listener());
        $log = new ArrayLogger();
        $db = $manager->connection();

        try {
            (new ProfileQueriesMiddleware($profiler, $log, 2))->process(
                new ServerRequest('GET', '/fail'),
                new CallableHandler(static function () use ($db): never {
                    $db->select('select 1');
                    $db->select('select 1');
                    throw new RuntimeException('boom');
                }),
            );
            self::fail('Expected exception.');
        } catch (RuntimeException) {
        }

        self::assertCount(1, $log->records);
    }

    // Provider configuration

    public function testProviderWiresProfilingFromConfig(): void
    {
        $app = new Application(sys_get_temp_dir());
        $log = new ArrayLogger();
        $app->container()->instance(LoggerInterface::class, $log);
        $app->container()->instance(Config::class, new Config(['database' => [
            'default' => 'memory',
            'connections' => ['memory' => ['driver' => 'sqlite', 'database' => ':memory:']],
            'profiling' => ['enabled' => true, 'slow_query_ms' => 0, 'n_plus_one_threshold' => 3, 'expose_headers' => true],
        ]]));
        $app->register(new DatabaseServiceProvider());

        $db = $app->container()->make(Connection::class);
        $db->select('select 1');

        $profiler = $app->container()->make(QueryProfiler::class);
        self::assertSame(1, $profiler->count());
        self::assertSame('warning', $log->records[0]['level'], 'threshold 0 makes every query slow');

        $response = $app->container()->make(ProfileQueriesMiddleware::class)
            ->process(new ServerRequest('GET', '/'), self::handlerRunning($db, 3));
        self::assertSame('3', $response->getHeaderLine('X-Query-Count'));
    }

    public function testProfilingIsOffByDefault(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->container()->instance(Config::class, new Config(['database' => [
            'default' => 'memory',
            'connections' => ['memory' => ['driver' => 'sqlite', 'database' => ':memory:']],
        ]]));
        $app->register(new DatabaseServiceProvider());

        $app->container()->make(Connection::class)->select('select 1');

        self::assertSame(0, $app->container()->make(QueryProfiler::class)->count());
        self::assertInstanceOf(Psr17Factory::class, new Psr17Factory());
    }
}
