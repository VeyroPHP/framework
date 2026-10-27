<?php

declare(strict_types=1);

namespace VeyroPHP\Database\Exception;

use Throwable;

/**
 * Thrown for transaction misuse and failed rollbacks.
 */
final class TransactionException extends DatabaseException
{
    public static function notActive(string $action): self
    {
        return new self(sprintf('Cannot %s: no transaction is active.', $action));
    }

    public static function levelChanged(int $expected, int $actual): self
    {
        return new self(sprintf(
            'The transaction callback changed the transaction level (expected %d, found %d). Commit or roll back only what you begin inside the callback.',
            $expected,
            $actual,
        ));
    }

    public static function implicitlyCommitted(): self
    {
        return new self('The transaction was ended by the database (for example by a DDL statement on MySQL) before it was committed.');
    }

    public static function rollbackFailed(Throwable $original, Throwable $rollbackError): self
    {
        return new self(
            sprintf('Rolling back after %s failed: %s: %s', $original::class, $rollbackError::class, $rollbackError->getMessage()),
            0,
            $original,
        );
    }

    public static function connectionLost(int $openLevels): self
    {
        return new self(sprintf(
            'The database connection was lost inside a transaction, so the transaction is gone. No more queries run on this connection until the %d open transaction level(s) are rolled back: leave the transaction() block, or call rollBack().',
            $openLevels,
        ));
    }

    public static function disconnectInTransaction(): self
    {
        return new self('Cannot disconnect while a transaction is active.');
    }
}
