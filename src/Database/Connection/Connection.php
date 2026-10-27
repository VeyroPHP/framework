<?php

declare(strict_types=1);

namespace VeyroPHP\Database\Connection;

use BackedEnum;
use Closure;
use DateTimeInterface;
use PDO;
use PDOException;
use PDOStatement;
use Stringable;
use Throwable;
use VeyroPHP\Database\Dialect\Dialect;
use VeyroPHP\Database\Exception\DatabaseException;
use VeyroPHP\Database\Exception\QueryException;
use VeyroPHP\Database\Exception\TransactionException;
use VeyroPHP\Database\Profiling\QueryExecuted;
use VeyroPHP\Database\Query\Builder;
use VeyroPHP\Database\Query\Expression;

/**
 * A database connection: runs prepared statements and manages transactions.
 *
 * The PDO connection is opened lazily on the first query.
 *
 * Bindings are positional ("?") and typed: null, bool, int, float, string,
 * DateTimeInterface (formatted "Y-m-d H:i:s" in its own time zone), BackedEnum
 * (its value) and Stringable. Other types are rejected.
 *
 * Nested transaction() / beginTransaction() calls use savepoints, so an inner
 * failure rolls back only the inner part.
 */
final class Connection
{
    private ?PDO $pdo = null;

    private int $transactionLevel = 0;

    /**
     * True after the connection was lost inside a transaction, until every open
     * level is rolled back. Statements are refused meanwhile, so work meant for the
     * lost transaction cannot run in autocommit mode on a new connection.
     */
    private bool $lostInTransaction = false;

    /** @var list<Closure(QueryExecuted): void> */
    private array $listeners = [];

    /** @var Closure(int): void */
    private readonly Closure $sleep;

    /**
     * @param Closure(): PDO $connector opens the PDO connection
     * @param (Closure(int): void)|null $sleep waits the given microseconds between
     *                                         transaction attempts (for tests)
     */
    public function __construct(
        private readonly string $name,
        private readonly Dialect $dialect,
        private readonly Closure $connector,
        ?Closure $sleep = null,
    ) {
        $this->sleep = $sleep ?? static function (int $microseconds): void {
            usleep($microseconds);
        };
    }

    public function name(): string
    {
        return $this->name;
    }

    public function dialect(): Dialect
    {
        return $this->dialect;
    }

    public function pdo(): PDO
    {
        return $this->pdo ??= ($this->connector)();
    }

    /**
     * Registers a listener called after every statement, including failed ones.
     *
     * Listeners run synchronously; an exception thrown by a listener propagates
     * to the caller of the query.
     *
     * @param Closure(QueryExecuted): void $listener
     */
    public function listen(Closure $listener): void
    {
        $this->listeners[] = $listener;
    }

    public function isConnected(): bool
    {
        return $this->pdo !== null;
    }

    /**
     * Closes the connection. The next query opens a new one.
     *
     * @throws TransactionException when a transaction is active
     */
    public function disconnect(): void
    {
        if ($this->transactionLevel > 0) {
            throw TransactionException::disconnectInTransaction();
        }

        $this->pdo = null;
    }

    public function table(string|Expression $table): Builder
    {
        return (new Builder($this))->from($table);
    }

    public function raw(string $sql): Expression
    {
        return new Expression($sql);
    }

    /**
     * @param list<mixed> $bindings
     *
     * @return list<array<string, mixed>>
     *
     * @throws QueryException
     */
    public function select(string $sql, array $bindings = []): array
    {
        return $this->run($sql, $bindings, static function (PDOStatement $statement): array {
            /** @var list<array<string, mixed>> */
            return $statement->fetchAll(PDO::FETCH_ASSOC);
        }, true);
    }

    /**
     * @param list<mixed> $bindings
     *
     * @return array<string, mixed>|null
     *
     * @throws QueryException
     */
    public function selectOne(string $sql, array $bindings = []): ?array
    {
        return $this->run($sql, $bindings, static function (PDOStatement $statement): ?array {
            /** @var array<string, mixed>|false $row */
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            $statement->closeCursor();

            return $row === false ? null : $row;
        }, true);
    }

    /**
     * Runs an INSERT, UPDATE, DELETE or other statement and returns the number of affected rows.
     *
     * @param list<mixed> $bindings
     *
     * @throws QueryException
     */
    public function affectingStatement(string $sql, array $bindings = []): int
    {
        return $this->run($sql, $bindings, static fn(PDOStatement $statement): int => $statement->rowCount());
    }

    /**
     * Runs a statement that returns no rows, such as DDL.
     *
     * @param list<mixed> $bindings
     *
     * @throws QueryException
     */
    public function statement(string $sql, array $bindings = []): void
    {
        $this->run($sql, $bindings, static fn(): null => null);
    }

    public function lastInsertId(?string $sequence = null): string
    {
        $id = $this->pdo()->lastInsertId($sequence);

        return $id === false ? '' : $id;
    }

    /**
     * Runs the callback in a transaction and returns its result.
     *
     * The transaction is committed when the callback returns and rolled back
     * when it throws; the exception is then rethrown unchanged.
     *
     * With $attempts > 1 the whole transaction runs again after a deadlock or a
     * serialization failure (QueryException::isDeadlock()), up to $attempts times,
     * with a short, growing, randomised wait between attempts. Only the outermost
     * transaction retries: inside another transaction the failure is rethrown, because
     * the database may already have aborted the outer one. The callback must be safe
     * to run again: no mail, HTTP calls or other side effects outside the database.
     *
     * @template T
     *
     * @param Closure(Connection): T $callback
     *
     * @return T
     *
     * @throws DatabaseException when $attempts is below 1
     */
    public function transaction(Closure $callback, int $attempts = 1): mixed
    {
        if ($attempts < 1) {
            throw DatabaseException::invalidQuery('A transaction needs at least 1 attempt.');
        }

        $retryable = $this->transactionLevel === 0;

        for ($attempt = 1; ; $attempt++) {
            try {
                return $this->runTransaction($callback);
            } catch (QueryException $e) {
                if (!$retryable || $attempt >= $attempts || !$e->isDeadlock()) {
                    throw $e;
                }

                // 10-50 ms after the first failure, growing per attempt, capped at one second.
                ($this->sleep)(min(1_000_000, random_int(10_000, 50_000) * $attempt));
            }
        }
    }

    /**
     * @template T
     *
     * @param Closure(Connection): T $callback
     *
     * @return T
     */
    private function runTransaction(Closure $callback): mixed
    {
        $this->beginTransaction();
        $level = $this->transactionLevel;

        try {
            $result = $callback($this);
        } catch (Throwable $e) {
            if ($this->transactionLevel === $level) {
                try {
                    $this->rollBack();
                } catch (Throwable $rollbackError) {
                    throw TransactionException::rollbackFailed($e, $rollbackError);
                }
            }

            throw $e;
        }

        if ($this->transactionLevel !== $level) {
            throw TransactionException::levelChanged($level, $this->transactionLevel);
        }

        try {
            $this->commit();
        } catch (Throwable $e) {
            // A failed COMMIT can leave the transaction open (SQLite keeps it open after a
            // deferred constraint violation). Close it, so the next transaction() is real.
            if ($this->transactionLevel === $level) {
                try {
                    $this->rollBack();
                } catch (Throwable $rollbackError) {
                    throw TransactionException::rollbackFailed($e, $rollbackError);
                }
            }

            throw $e;
        }

        return $result;
    }

    public function beginTransaction(): void
    {
        $this->assertNotLostInTransaction();

        if ($this->transactionLevel === 0) {
            try {
                $this->wrap('begin transaction', fn(): bool => $this->pdo()->beginTransaction());
            } catch (QueryException $e) {
                // Nothing has run yet, so starting again on a new connection is safe.
                if (!$e->isLostConnection()) {
                    throw $e;
                }
                $this->pdo = null;
                $this->wrap('begin transaction', fn(): bool => $this->pdo()->beginTransaction());
            }
        } else {
            $this->exec($this->dialect->compileSavepoint($this->savepoint($this->transactionLevel)));
        }

        $this->transactionLevel++;
    }

    public function commit(): void
    {
        if ($this->transactionLevel === 0) {
            throw TransactionException::notActive('commit');
        }

        $this->assertNotLostInTransaction();

        if ($this->transactionLevel === 1) {
            if (!$this->pdo()->inTransaction()) {
                $this->transactionLevel = 0;

                throw TransactionException::implicitlyCommitted();
            }

            try {
                $this->wrap('commit', fn(): bool => $this->pdo()->commit());
            } catch (QueryException $e) {
                if ($e->isLostConnection()) {
                    // Whether the commit reached the server is unknown; do not reuse the connection.
                    $this->pdo = null;
                    $this->transactionLevel = 0;
                }

                throw $e;
            } finally {
                if ($this->pdo !== null && !$this->pdo->inTransaction()) {
                    $this->transactionLevel = 0;
                }
            }

            return;
        }

        $this->exec($this->dialect->compileReleaseSavepoint($this->savepoint($this->transactionLevel - 1)));
        $this->transactionLevel--;
    }

    public function rollBack(): void
    {
        if ($this->transactionLevel === 0) {
            throw TransactionException::notActive('roll back');
        }

        if ($this->lostInTransaction) {
            // The server discarded the transaction with the connection; only unwind the level.
            $this->transactionLevel--;
            $this->lostInTransaction = $this->transactionLevel > 0;

            return;
        }

        if ($this->transactionLevel === 1) {
            $this->transactionLevel = 0;

            try {
                if ($this->pdo()->inTransaction()) {
                    $this->wrap('rollback', fn(): bool => $this->pdo()->rollBack());
                }
            } catch (QueryException $e) {
                // A closed connection discards uncommitted work on the server, so the
                // rollback has effectively happened; only a real rollback error is thrown.
                if (!$e->isLostConnection()) {
                    throw $e;
                }
                $this->pdo = null;
            }

            return;
        }

        $this->exec($this->dialect->compileRollbackToSavepoint($this->savepoint($this->transactionLevel - 1)));
        $this->transactionLevel--;
    }

    /**
     * 0 when no transaction is active; 2 or more inside nested transactions.
     */
    public function transactionLevel(): int
    {
        return $this->transactionLevel;
    }

    /**
     * Runs one statement. When the connection turns out to be broken (the
     * database restarted, a proxy or the server closed an idle connection):
     *
     * - inside a transaction nothing is retried: the transaction is gone and the
     *   error is thrown. Further statements are refused until the open levels are
     *   rolled back (transaction() does that when its callback throws);
     * - otherwise the connection is reopened and the statement is run once more
     *   if that cannot apply it twice: always when it failed while being
     *   prepared (it never reached the database), and for reads also when it
     *   failed while executing. A write that may already have run is not
     *   repeated; the error is thrown and the next statement uses a new connection.
     *
     * @template T
     *
     * @param array<mixed> $bindings validated to be a list at runtime
     * @param Closure(PDOStatement): T $fetch
     *
     * @return T
     */
    private function run(string $sql, array $bindings, Closure $fetch, bool $isRead = false): mixed
    {
        if (!array_is_list($bindings)) {
            throw DatabaseException::invalidQuery('Bindings must be a list for positional "?" placeholders.');
        }

        $this->assertNotLostInTransaction();

        for ($attempt = 1; ; $attempt++) {
            $pdo = $this->pdo();
            $start = hrtime(true);
            $prepared = false;

            try {
                $statement = $pdo->prepare($sql);
                $prepared = true;
                $this->bind($statement, $bindings);
                $statement->execute();
                $result = $fetch($statement);
            } catch (PDOException $e) {
                $this->dispatch($sql, count($bindings), $start, true);

                if (!LostConnection::causedBy($e)) {
                    throw QueryException::failed($this->name, $sql, count($bindings), $e);
                }

                $this->pdo = null; // never reuse a broken connection

                if ($this->transactionLevel > 0) {
                    $this->lostInTransaction = true;

                    throw QueryException::failed($this->name, $sql, count($bindings), $e);
                }

                if ($attempt > 1 || ($prepared && !$isRead)) {
                    throw QueryException::failed($this->name, $sql, count($bindings), $e);
                }

                continue;
            }

            $this->dispatch($sql, count($bindings), $start, false);

            return $result;
        }
    }

    private function dispatch(string $sql, int $bindingCount, int|float $start, bool $failed): void
    {
        if ($this->listeners === []) {
            return;
        }

        $event = new QueryExecuted($this->name, $sql, $bindingCount, (hrtime(true) - $start) / 1_000_000, $failed);

        foreach ($this->listeners as $listener) {
            $listener($event);
        }
    }

    /**
     * @param list<mixed> $bindings
     */
    private function bind(PDOStatement $statement, array $bindings): void
    {
        foreach ($bindings as $index => $value) {
            $position = $index + 1;

            [$value, $type] = match (true) {
                $value === null => [null, PDO::PARAM_NULL],
                is_bool($value) => $this->dialect->bindBoolean($value),
                is_int($value) => [$value, PDO::PARAM_INT],
                is_float($value) => [(string) $value, PDO::PARAM_STR],
                is_string($value) => [$value, PDO::PARAM_STR],
                $value instanceof DateTimeInterface => [$value->format('Y-m-d H:i:s'), PDO::PARAM_STR],
                $value instanceof BackedEnum => [$value->value, is_int($value->value) ? PDO::PARAM_INT : PDO::PARAM_STR],
                $value instanceof Stringable => [(string) $value, PDO::PARAM_STR],
                default => throw DatabaseException::unsupportedBinding($position, get_debug_type($value)),
            };

            $statement->bindValue($position, $value, $type);
        }
    }

    private function assertNotLostInTransaction(): void
    {
        if ($this->lostInTransaction) {
            throw TransactionException::connectionLost($this->transactionLevel);
        }
    }

    private function exec(string $sql): void
    {
        $this->assertNotLostInTransaction();

        $pdo = $this->pdo();
        $start = hrtime(true);

        try {
            $this->wrap($sql, fn(): int|false => $pdo->exec($sql));
        } catch (QueryException $e) {
            $this->dispatch($sql, 0, $start, true);

            throw $e;
        }

        $this->dispatch($sql, 0, $start, false);
    }

    /**
     * @param Closure(): mixed $operation
     */
    private function wrap(string $sql, Closure $operation): void
    {
        try {
            $operation();
        } catch (PDOException $e) {
            throw QueryException::failed($this->name, $sql, 0, $e);
        }
    }

    private function savepoint(int $level): string
    {
        return 'veyrophp_savepoint_' . $level;
    }
}
