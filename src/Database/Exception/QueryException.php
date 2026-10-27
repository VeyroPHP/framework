<?php

declare(strict_types=1);

namespace VeyroPHP\Database\Exception;

use PDOException;
use VeyroPHP\Database\Connection\LostConnection;

/**
 * Thrown when the database rejects a query.
 *
 * The message contains the SQL with placeholders and the number of bindings,
 * never the bound values. Note that some driver messages (for example MySQL's
 * "Duplicate entry 'x'") quote data themselves.
 */
final class QueryException extends DatabaseException
{
    private function __construct(
        string $message,
        private readonly string $sql,
        private readonly int $bindingCount,
        private readonly string $sqlState,
        PDOException $previous,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function failed(string $connection, string $sql, int $bindingCount, PDOException $previous): self
    {
        $sqlState = is_array($previous->errorInfo) && is_string($previous->errorInfo[0] ?? null)
            ? $previous->errorInfo[0]
            : (string) $previous->getCode();

        return new self(
            sprintf('Query failed on connection "%s": %s (SQL: %s; %d binding%s)', $connection, $previous->getMessage(), $sql, $bindingCount, $bindingCount === 1 ? '' : 's'),
            $sql,
            $bindingCount,
            $sqlState,
            $previous,
        );
    }

    public function getSql(): string
    {
        return $this->sql;
    }

    public function getBindingCount(): int
    {
        return $this->bindingCount;
    }

    /**
     * The five-character SQLSTATE, e.g. "23000" for integrity constraint violations.
     */
    public function getSqlState(): string
    {
        return $this->sqlState;
    }

    /**
     * True for failures that a repeated transaction can succeed after: a deadlock
     * (SQLSTATE 40001 on MySQL/MariaDB, 40P01 on PostgreSQL) or a serialization
     * failure (40001 on PostgreSQL). Lock wait timeouts are not included: retrying
     * them usually waits on the same lock again.
     */
    public function isDeadlock(): bool
    {
        return $this->sqlState === '40001' || $this->sqlState === '40P01';
    }

    /**
     * True when the query failed because the connection to the database broke
     * (server restarted, idle connection closed), not because of the query.
     */
    public function isLostConnection(): bool
    {
        $previous = $this->getPrevious();

        return $previous instanceof PDOException && LostConnection::causedBy($previous);
    }
}
