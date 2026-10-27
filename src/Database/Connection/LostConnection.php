<?php

declare(strict_types=1);

namespace VeyroPHP\Database\Connection;

use PDOException;

/**
 * Recognizes errors caused by a broken connection (as opposed to a bad query),
 * and connect errors that are worth retrying.
 *
 * @internal Framework implementation detail, not covered by the backward-compatibility promise.
 */
final class LostConnection
{
    /** MySQL/MariaDB client errors: server has gone away, lost connection, connection reset. */
    private const MYSQL_LOST = [2006, 2013, 2055, 4031];

    /** MySQL/MariaDB connect errors worth retrying: can't connect, unknown host (DNS hiccup), too many connections. */
    private const MYSQL_TRANSIENT_CONNECT = [1040, 2002, 2003, 2005];

    private const MESSAGES = [
        'server has gone away',
        'lost connection to',
        'no connection to the server',
        'server closed the connection unexpectedly',
        'ssl connection has been closed unexpectedly',
        'connection reset by peer',
        'broken pipe',
        'terminating connection due to administrator command',
        'connection is no longer usable',
        'error while sending',
        'decryption failed or bad record mac',
    ];

    /**
     * True when a query failed because the connection broke.
     */
    public static function causedBy(PDOException $e): bool
    {
        [$sqlState, $code] = self::codes($e);

        // SQLSTATE class 08 = connection exception; 57P01-57P03 = PostgreSQL server shutdown/termination.
        if (str_starts_with($sqlState, '08') || in_array($sqlState, ['57P01', '57P02', '57P03'], true)) {
            return true;
        }

        if (in_array($code, self::MYSQL_LOST, true)) {
            return true;
        }

        $message = strtolower($e->getMessage());
        foreach (self::MESSAGES as $text) {
            if (str_contains($message, $text)) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when opening a connection failed for a reason that may go away on
     * its own (server restarting, network blip, connection limit). Wrong
     * passwords or unknown databases are not retried.
     */
    public static function isTransientConnectError(PDOException $e): bool
    {
        [$sqlState, $code] = self::codes($e);

        if (in_array($code, self::MYSQL_TRANSIENT_CONNECT, true) || in_array($sqlState, ['08001', '08006', '57P03'], true)) {
            return true;
        }

        $message = strtolower($e->getMessage());

        return str_contains($message, 'connection refused')
            || str_contains($message, 'timed out')
            || str_contains($message, 'too many connections')
            || str_contains($message, 'the database system is starting up')
            || self::causedBy($e);
    }

    /**
     * @return array{string, int|null} SQLSTATE and driver error code
     */
    private static function codes(PDOException $e): array
    {
        $info = is_array($e->errorInfo) ? $e->errorInfo : [];
        $sqlState = is_string($info[0] ?? null) ? $info[0] : '';
        $code = is_int($info[1] ?? null) ? $info[1] : null;

        // Connect errors carry no errorInfo; the message holds "SQLSTATE[HY000] [2002] ...".
        if (preg_match('/SQLSTATE\[(\w{5})\](?: \[(\d+)\])?/', $e->getMessage(), $m) === 1) {
            $sqlState = $sqlState !== '' ? $sqlState : $m[1];
            $code ??= isset($m[2]) ? (int) $m[2] : null;
        }

        return [$sqlState, $code];
    }
}
