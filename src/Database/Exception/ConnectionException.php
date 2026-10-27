<?php

declare(strict_types=1);

namespace VeyroPHP\Database\Exception;

use PDOException;

/**
 * Thrown when a database connection cannot be opened.
 */
final class ConnectionException extends DatabaseException
{
    public static function failed(string $connection, string $driver, PDOException $previous): self
    {
        return new self(
            sprintf('Could not connect to database connection "%s" (%s): %s', $connection, $driver, $previous->getMessage()),
            0,
            $previous,
        );
    }
}
