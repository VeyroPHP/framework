<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Unit\Testing;

use DateTimeImmutable;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use VeyroPHP\Cache\ArrayStore;
use VeyroPHP\Cache\Cache;
use VeyroPHP\Container\Container;
use VeyroPHP\Database\Connection\Connection;
use VeyroPHP\Database\Dialect\SqliteDialect;
use VeyroPHP\Events\EventDispatcher;
use VeyroPHP\Security\RateLimiter;
use VeyroPHP\Testing\EventFake;
use VeyroPHP\Testing\RollsBackTransactions;
use VeyroPHP\Testing\TestClock;

final class OrderPlaced
{
    public function __construct(public readonly int $id) {}
}

final class OrderShipped {}

#[CoversClass(TestClock::class)]
#[CoversClass(EventFake::class)]
final class TestingHelpersTest extends TestCase
{
    public function testClockStartsWhereToldAndMovesOnlyWhenTold(): void
    {
        $clock = new TestClock('2026-10-27 10:00:00', 'Asia/Kolkata');

        self::assertSame('2026-10-27T10:00:00+05:30', $clock->now()->format(DATE_ATOM));
        self::assertSame($clock->timestamp(), $clock->timestamp(), 'frozen');

        $clock->travel(90);
        self::assertSame('10:01:30', $clock->now()->format('H:i:s'));
        $clock->travel('+1 day');
        self::assertSame('2026-10-28', $clock->now()->format('Y-m-d'));
        $clock->set('2027-01-01 00:00:00');
        self::assertSame('2027-01-01', $clock->now()->format('Y-m-d'));
    }

    public function testClockClosuresDriveFrameworkClasses(): void
    {
        $clock = new TestClock('2026-10-27 10:00:00');
        $limiter = new RateLimiter(new Cache(new ArrayStore($clock->timestamps()), 'app', null, 10, 50, $clock->timestamps()), $clock->timestamps());

        self::assertTrue($limiter->attempt('k', 1, 60));
        self::assertFalse($limiter->attempt('k', 1, 60));
        $clock->travel(61);
        self::assertTrue($limiter->attempt('k', 1, 60), 'the window passed on the test clock');

        $dates = $clock->dates();
        self::assertInstanceOf(DateTimeImmutable::class, $dates());
        self::assertSame($clock->timestamp(), $dates()->getTimestamp());
    }

    public function testInvalidTimesAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TestClock('not a time');
    }

    public function testEventFakeRecordsWithoutRunningListeners(): void
    {
        $container = new Container();
        $real = new EventDispatcher($container);
        $ran = false;
        $real->listen(OrderPlaced::class, static function () use (&$ran): void {
            $ran = true;
        });
        $container->instance(EventDispatcherInterface::class, $real);

        $fake = EventFake::install($container);
        $container->make(EventDispatcherInterface::class)->dispatch(new OrderPlaced(7));
        $container->make(EventDispatcherInterface::class)->dispatch(new OrderPlaced(8));

        self::assertFalse($ran);
        self::assertCount(2, $fake->dispatched(OrderPlaced::class));
        $fake->assertDispatched(OrderPlaced::class);
        $fake->assertDispatched(OrderPlaced::class, static fn(OrderPlaced $e): bool => $e->id === 8);
        $fake->assertDispatchedTimes(OrderPlaced::class, 2);
        $fake->assertNotDispatched(OrderShipped::class);
    }

    public function testChosenEventsStillReachTheirListeners(): void
    {
        $container = new Container();
        $real = new EventDispatcher($container);
        $ran = [];
        $real->listen(OrderPlaced::class, static function () use (&$ran): void {
            $ran[] = 'placed';
        });
        $real->listen(OrderShipped::class, static function () use (&$ran): void {
            $ran[] = 'shipped';
        });
        $container->instance(EventDispatcherInterface::class, $real);

        $fake = EventFake::install($container, passThrough: [OrderShipped::class]);
        $fake->dispatch(new OrderPlaced(1));
        $fake->dispatch(new OrderShipped());

        self::assertSame(['shipped'], $ran);
        $fake->assertDispatched(OrderShipped::class);
    }

    public function testFailedAssertionsFailTheTest(): void
    {
        $fake = EventFake::install(new Container());

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('OrderPlaced');
        $fake->assertDispatched(OrderPlaced::class);
    }

    public function testRollsBackTransactionsUndoesWritesOfATest(): void
    {
        $connection = new Connection('test', new SqliteDialect(), static fn(): \PDO => new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]));
        $connection->statement('CREATE TABLE notes (id INTEGER PRIMARY KEY, body TEXT)');

        $test = new class ($connection) {
            use RollsBackTransactions;

            public function __construct(private readonly Connection $connection) {}

            protected function connectionsToRollBack(): array
            {
                return [$this->connection];
            }

            public function run(): void
            {
                $this->beginTestTransactions();
                $this->connection->table('notes')->insert(['body' => 'written by the test']);
                // Code under test may use transactions itself (they become savepoints).
                $this->connection->transaction(fn() => $this->connection->table('notes')->insert(['body' => 'nested']));
                $this->rollBackTestTransactions();
            }
        };

        $test->run();

        self::assertEquals(0, $connection->selectOne('SELECT COUNT(*) AS n FROM notes')['n'] ?? null);
        self::assertSame(0, $connection->transactionLevel());
    }
}
