<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Unit\Database;

use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use VeyroPHP\Database\Connection\Connection;
use VeyroPHP\Database\Dialect\SqliteDialect;
use VeyroPHP\Database\Exception\DatabaseException;
use VeyroPHP\Database\Exception\QueryException;

/**
 * transaction(..., attempts: n) repeats the whole transaction after a deadlock or
 * serialization failure, and nothing else. The failures are simulated here; the
 * PostgreSQL integration test produces a real one.
 */
#[CoversClass(Connection::class)]
#[CoversClass(QueryException::class)]
final class TransactionRetryTest extends TestCase
{
    /** @var list<int> */
    private array $sleeps = [];

    private function connection(): Connection
    {
        $connection = new Connection(
            'test',
            new SqliteDialect(),
            static fn(): PDO => new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]),
            function (int $microseconds): void {
                $this->sleeps[] = $microseconds;
            },
        );
        $connection->statement('create table notes (id integer primary key, body text)');

        return $connection;
    }

    private static function failure(string $sqlState): QueryException
    {
        $pdo = new PDOException('simulated ' . $sqlState);
        $pdo->errorInfo = [$sqlState, 0, 'simulated'];

        return QueryException::failed('test', 'update notes set body = ?', 1, $pdo);
    }

    /**
     * Runs the callback and returns what it threw, or null.
     */
    private static function capture(\Closure $callback): ?\Throwable
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            return $e;
        }

        return null;
    }

    private static function noteCount(Connection $db): mixed
    {
        return $db->selectOne('select count(*) as n from notes')['n'] ?? null;
    }

    public function testDeadlocksAndSerializationFailuresAreRecognised(): void
    {
        self::assertTrue(self::failure('40001')->isDeadlock(), 'MySQL deadlock, PostgreSQL serialization failure');
        self::assertTrue(self::failure('40P01')->isDeadlock(), 'PostgreSQL deadlock');
        self::assertFalse(self::failure('23000')->isDeadlock());
        self::assertFalse(self::failure('HY000')->isDeadlock(), 'lock wait timeouts are not retried');
    }

    public function testTheWholeTransactionIsRetriedAndEarlierAttemptsLeaveNothing(): void
    {
        $db = $this->connection();
        $calls = 0;

        $result = $db->transaction(static function (Connection $db) use (&$calls): string {
            $calls++;
            $db->table('notes')->insert(['body' => 'attempt ' . $calls]);

            if ($calls < 3) {
                throw self::failure('40001');
            }

            return 'done';
        }, attempts: 3);

        self::assertSame('done', $result);
        self::assertSame(3, $calls);
        self::assertEquals(1, self::noteCount($db), 'the failed attempts were rolled back');
        self::assertSame(['attempt 3'], $db->table('notes')->pluck('body'));
        self::assertSame(0, $db->transactionLevel());
        self::assertCount(2, $this->sleeps);
        foreach ($this->sleeps as $sleep) {
            self::assertGreaterThan(0, $sleep);
            self::assertLessThanOrEqual(1_000_000, $sleep, 'waits stay bounded');
        }
    }

    public function testGivingUpRethrowsTheLastFailure(): void
    {
        $db = $this->connection();
        $calls = 0;

        $error = self::capture(static function () use ($db, &$calls): void {
            $db->transaction(static function () use (&$calls): void {
                $calls++;

                throw self::failure('40P01');
            }, attempts: 2);
        });

        self::assertInstanceOf(QueryException::class, $error);
        self::assertTrue($error->isDeadlock());
        self::assertSame(2, $calls);
        self::assertSame(0, $db->transactionLevel());
    }

    public function testOtherErrorsAreNotRetried(): void
    {
        $db = $this->connection();
        $calls = 0;

        $error = self::capture(static function () use ($db, &$calls): void {
            $db->transaction(static function () use (&$calls): void {
                $calls++;

                throw self::failure('23000');
            }, attempts: 5);
        });

        self::assertInstanceOf(QueryException::class, $error);
        self::assertFalse($error->isDeadlock());
        self::assertSame(1, $calls);
        self::assertSame([], $this->sleeps);
    }

    public function testTheDefaultIsOneAttempt(): void
    {
        $db = $this->connection();
        $calls = 0;

        try {
            $db->transaction(static function () use (&$calls): void {
                $calls++;

                throw self::failure('40001');
            });
        } catch (QueryException) {
        }

        self::assertSame(1, $calls, 'unchanged behaviour without attempts');
    }

    public function testANestedTransactionIsNeverRetriedOnItsOwn(): void
    {
        $db = $this->connection();
        $outer = 0;
        $inner = 0;

        $db->transaction(static function (Connection $db) use (&$outer, &$inner): void {
            $outer++;

            try {
                $db->transaction(static function () use (&$inner): void {
                    $inner++;

                    throw self::failure('40001');
                }, attempts: 5);
            } catch (QueryException) {
                // the application handles it; the outer transaction goes on
            }
        });

        self::assertSame(1, $outer);
        self::assertSame(1, $inner, 'a savepoint cannot be retried: the server may have aborted the whole transaction');
    }

    public function testAttemptsMustBePositive(): void
    {
        $this->expectException(DatabaseException::class);
        $this->connection()->transaction(static fn(): null => null, attempts: 0);
    }
}
