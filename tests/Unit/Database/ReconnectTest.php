<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Unit\Database;

use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VeyroPHP\Database\Connection\Connection;
use VeyroPHP\Database\Connection\ConnectionConfig;
use VeyroPHP\Database\Connection\ConnectionFactory;
use VeyroPHP\Database\Connection\LostConnection;
use VeyroPHP\Database\Dialect\SqliteDialect;
use VeyroPHP\Database\Exception\ConnectionException;
use VeyroPHP\Database\Exception\DatabaseException;
use VeyroPHP\Database\Exception\QueryException;
use VeyroPHP\Database\Exception\TransactionException;
use VeyroPHP\Tests\Fixtures\Database\FlakyPdo;

#[CoversClass(Connection::class)]
#[CoversClass(LostConnection::class)]
#[CoversClass(ConnectionFactory::class)]
#[CoversClass(ConnectionConfig::class)]
#[CoversClass(QueryException::class)]
final class ReconnectTest extends TestCase
{
    private string $file;

    private int $connects = 0;

    private Connection $db;

    protected function setUp(): void
    {
        FlakyPdo::reset();
        $this->file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'veyrophp-reconnect-' . bin2hex(random_bytes(4)) . '.sqlite';
        $this->db = new Connection('flaky', new SqliteDialect(), function (): PDO {
            $this->connects++;

            return new FlakyPdo($this->file);
        });
        $this->db->statement('create table items (id integer primary key autoincrement, name text not null)');
        $this->connects = 1;
    }

    protected function tearDown(): void
    {
        FlakyPdo::reset();
        if ($this->db->transactionLevel() === 0) {
            $this->db->disconnect();
        }
        @unlink($this->file);
    }

    private function rows(): int
    {
        return $this->db->table('items')->count();
    }

    public function testReadIsRetriedOnANewConnectionWhenPrepareFails(): void
    {
        FlakyPdo::$failPrepares = 1;

        self::assertSame([['one' => 1]], $this->db->select('select 1 as one'));
        self::assertSame(2, $this->connects);
    }

    public function testReadIsRetriedWhenExecuteFails(): void
    {
        FlakyPdo::$failExecutes = 1;

        self::assertSame(['one' => 1], $this->db->selectOne('select 1 as one'));
        self::assertSame(2, $this->connects);
    }

    public function testWriteIsRetriedWhenItNeverReachedTheServer(): void
    {
        // Typical for an idle connection closed by the server: the first round trip fails.
        FlakyPdo::$failPrepares = 1;

        self::assertSame(1, $this->db->affectingStatement('insert into items (name) values (?)', ['a']));
        self::assertSame(1, $this->rows());
    }

    public function testWriteThatMayHaveRunIsNotRepeated(): void
    {
        FlakyPdo::$failExecutes = 1;

        $error = null;
        try {
            $this->db->affectingStatement('insert into items (name) values (?)', ['a']);
        } catch (QueryException $e) {
            $error = $e;
        }

        self::assertInstanceOf(QueryException::class, $error);
        self::assertTrue($error->isLostConnection());
        self::assertFalse($this->db->isConnected(), 'the broken connection is dropped');
        // The next statement opens a new connection and works.
        self::assertSame(0, $this->rows());
        self::assertSame(2, $this->connects);
    }

    public function testRetriesOnlyOnce(): void
    {
        FlakyPdo::$failPrepares = 2;

        $this->expectException(QueryException::class);
        $this->db->select('select 1');
    }

    public function testOrdinaryQueryErrorsAreNotRetried(): void
    {
        try {
            $this->db->select('select * from missing_table');
            self::fail('Expected QueryException');
        } catch (QueryException $e) {
            self::assertFalse($e->isLostConnection());
        }

        self::assertSame(1, $this->connects);
        self::assertTrue($this->db->isConnected());
    }

    public function testNothingIsRetriedInsideATransaction(): void
    {
        $error = null;
        try {
            $this->db->transaction(function (Connection $db): void {
                $db->affectingStatement('insert into items (name) values (?)', ['inside']);
                FlakyPdo::$failPrepares = 1;
                $db->select('select 1');
            });
        } catch (QueryException $e) {
            $error = $e;
        }

        // The original error comes through (not a rollback error), and the level is reset.
        self::assertInstanceOf(QueryException::class, $error);
        self::assertTrue($error->isLostConnection());
        self::assertSame(0, $this->db->transactionLevel());

        // The uncommitted insert is gone: a real server discards it when the connection closes.
        // (SQLite keeps the file transaction open on the old handle, so check on the new one.)
        self::assertSame(0, $this->rows());
    }

    /**
     * VQ-041: after the connection is lost inside a transaction, the transaction is
     * gone. Work the application does next must not run in autocommit mode on a new
     * connection while the transaction() blocks are still open.
     */
    public function testWorkAfterALostConnectionInsideATransactionIsRefused(): void
    {
        $lost = null;
        $refused = [];

        try {
            $this->db->transaction(function (Connection $db) use (&$lost, &$refused): void {
                $db->affectingStatement('insert into items (name) values (?)', ['debit']);

                try {
                    $db->transaction(static function (Connection $db): void {
                        FlakyPdo::$failExecutes = 1;
                        $db->affectingStatement('insert into items (name) values (?)', ['inner']);
                    });
                } catch (QueryException $e) {
                    $lost = $e; // the application decides to carry on
                }

                foreach ([
                    static fn() => $db->affectingStatement('insert into items (name) values (?)', ['credit']),
                    static fn() => $db->select('select 1'),
                    static fn() => $db->transaction(static fn() => null),
                ] as $call) {
                    try {
                        $call();
                    } catch (TransactionException $e) {
                        $refused[] = $e->getMessage();
                    }
                }

                throw new \RuntimeException('business rule failed');
            });
        } catch (\RuntimeException $e) {
            self::assertSame('business rule failed', $e->getMessage());
        }

        self::assertInstanceOf(QueryException::class, $lost);
        self::assertTrue($lost->isLostConnection());
        self::assertCount(3, $refused, 'statements and new transactions are refused until the transaction is unwound');
        self::assertStringContainsString('lost inside a transaction', $refused[0]);
        self::assertSame(0, $this->db->transactionLevel());
        self::assertSame(0, $this->rows(), 'nothing was committed');

        // Once unwound, the connection works again.
        $this->db->affectingStatement('insert into items (name) values (?)', ['later']);
        self::assertSame(1, $this->rows());
    }

    public function testATransactionWhoseCallbackSwallowsALostConnectionDoesNotCommit(): void
    {
        $error = null;

        try {
            $this->db->transaction(static function (Connection $db): string {
                try {
                    FlakyPdo::$failExecutes = 1;
                    $db->affectingStatement('insert into items (name) values (?)', ['a']);
                } catch (QueryException) {
                    // swallowed
                }

                return 'done';
            });
        } catch (TransactionException $e) {
            $error = $e;
        }

        self::assertInstanceOf(TransactionException::class, $error, 'the commit is refused, not reported as success');
        self::assertSame(0, $this->db->transactionLevel());
        self::assertSame(0, $this->rows());
    }

    /**
     * VQ-042: a COMMIT that fails while the driver keeps the transaction open (SQLite
     * with a deferred foreign key violation) must not leave the transaction open.
     */
    public function testAFailedCommitDoesNotLeaveTheTransactionOpen(): void
    {
        $this->db->statement('pragma foreign_keys = on');
        $this->db->statement('create table parents (id integer primary key)');
        $this->db->statement('create table children (id integer primary key, parent_id integer not null references parents (id) deferrable initially deferred)');

        $error = null;
        try {
            $this->db->transaction(static function (Connection $db): void {
                $db->affectingStatement('insert into children (parent_id) values (?)', [99]);
            });
        } catch (QueryException $e) {
            $error = $e;
        }

        self::assertInstanceOf(QueryException::class, $error);
        self::assertStringContainsString('FOREIGN KEY', $error->getMessage());
        self::assertSame(0, $this->db->transactionLevel());
        self::assertFalse($this->db->pdo()->inTransaction(), 'the driver transaction is closed');
        self::assertSame(0, $this->db->table('children')->count());

        // The next transaction is a real one and its work is committed.
        $this->db->transaction(static function (Connection $db): void {
            $db->affectingStatement('insert into parents (id) values (?)', [1]);
            $db->affectingStatement('insert into children (parent_id) values (?)', [1]);
        });
        $this->db->disconnect();
        self::assertSame(1, $this->db->table('children')->count(), 'visible on a new connection');
    }

    public function testBeginTransactionReconnectsWhenTheConnectionIsGone(): void
    {
        FlakyPdo::$failBegins = 1;

        $this->db->transaction(static function (Connection $db): void {
            $db->affectingStatement('insert into items (name) values (?)', ['a']);
        });

        self::assertSame(1, $this->rows());
        self::assertSame(2, $this->connects);
    }

    /**
     * @return iterable<string, array{PDOException, bool}>
     */
    public static function errors(): iterable
    {
        $make = static function (string $message, ?array $info = null): PDOException {
            $e = new PDOException($message);
            $e->errorInfo = $info;

            return $e;
        };

        yield 'mysql gone away' => [$make('General error: 2006 MySQL server has gone away', ['HY000', 2006, 'x']), true];
        yield 'mysql lost connection' => [$make('Lost connection to MySQL server during query', ['HY000', 2013, 'x']), true];
        yield 'communication link failure' => [$make('Communication link failure', ['08S01', 1047, 'x']), true];
        yield 'pg terminated' => [$make('terminating connection due to administrator command', ['57P01', 7, 'x']), true];
        yield 'pg closed' => [$make('server closed the connection unexpectedly', ['HY000', 7, 'x']), true];
        yield 'syntax error' => [$make('You have an error in your SQL syntax', ['42000', 1064, 'x']), false];
        yield 'duplicate key' => [$make("Duplicate entry 'a' for key 'email'", ['23000', 1062, 'x']), false];
        yield 'deadlock' => [$make('Deadlock found when trying to get lock', ['40001', 1213, 'x']), false];
    }

    #[DataProvider('errors')]
    public function testRecognizesLostConnections(PDOException $error, bool $lost): void
    {
        self::assertSame($lost, LostConnection::causedBy($error));
    }

    public function testTransientConnectErrors(): void
    {
        self::assertTrue(LostConnection::isTransientConnectError(new PDOException('SQLSTATE[HY000] [2002] No connection could be made because the target machine actively refused it')));
        self::assertTrue(LostConnection::isTransientConnectError(new PDOException('SQLSTATE[HY000] [1040] Too many connections')));
        self::assertTrue(LostConnection::isTransientConnectError(new PDOException('SQLSTATE[08006] [7] could not connect to server: Connection refused')));
        self::assertFalse(LostConnection::isTransientConnectError(new PDOException("SQLSTATE[HY000] [1045] Access denied for user 'root'@'localhost'")));
        self::assertFalse(LostConnection::isTransientConnectError(new PDOException("SQLSTATE[HY000] [1049] Unknown database 'shop'")));
    }

    public function testFactoryRetriesTransientConnectErrorsWithBackoff(): void
    {
        $sleeps = [];
        $attempts = 0;
        $factory = new ConnectionFactory(
            static function (int $ms) use (&$sleeps): void {
                $sleeps[] = $ms;
            },
            static function (ConnectionConfig $config, array $options) use (&$attempts): PDO {
                if (++$attempts < 3) {
                    throw new PDOException('SQLSTATE[HY000] [2002] Connection refused');
                }

                return new PDO('sqlite::memory:', null, null, $options);
            },
        );

        $factory->create(new ConnectionConfig('m', 'sqlite', ':memory:'));

        self::assertSame(3, $attempts);
        self::assertSame([100, 200], $sleeps);
    }

    public function testFactoryGivesUpAfterTheConfiguredRetries(): void
    {
        $attempts = 0;
        $factory = new ConnectionFactory(static function (): void {}, static function () use (&$attempts): PDO {
            $attempts++;

            throw new PDOException('SQLSTATE[HY000] [2002] Connection refused');
        });

        try {
            $factory->create(new ConnectionConfig('m', 'sqlite', ':memory:', connectRetries: 1));
            self::fail('Expected ConnectionException');
        } catch (ConnectionException) {
            self::assertSame(2, $attempts);
        }
    }

    public function testFactoryDoesNotRetryWrongCredentials(): void
    {
        $attempts = 0;
        $factory = new ConnectionFactory(static function (): void {}, static function () use (&$attempts): PDO {
            $attempts++;

            throw new PDOException("SQLSTATE[HY000] [1045] Access denied for user 'app'");
        });

        try {
            $factory->create(new ConnectionConfig('m', 'sqlite', ':memory:'));
            self::fail('Expected ConnectionException');
        } catch (ConnectionException) {
            self::assertSame(1, $attempts);
        }
    }

    public function testTimeoutAndRetriesAreConfigurable(): void
    {
        $config = ConnectionConfig::fromArray('m', ['driver' => 'mysql', 'host' => 'db', 'database' => 'shop', 'timeout' => '3', 'connect_retries' => 0]);
        self::assertSame(3, $config->timeout);
        self::assertSame(0, $config->connectRetries);

        $defaults = ConnectionConfig::fromArray('m', ['driver' => 'sqlite', 'database' => ':memory:']);
        self::assertSame(5, $defaults->timeout);
        self::assertSame(2, $defaults->connectRetries);

        foreach ([['timeout' => 0], ['timeout' => 'soon'], ['connect_retries' => 11], ['connect_retries' => -1]] as $bad) {
            try {
                ConnectionConfig::fromArray('m', ['driver' => 'sqlite', 'database' => ':memory:'] + $bad);
                self::fail('Expected rejection of ' . json_encode($bad));
            } catch (DatabaseException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
