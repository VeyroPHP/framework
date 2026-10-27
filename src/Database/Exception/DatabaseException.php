<?php

declare(strict_types=1);

namespace VeyroPHP\Database\Exception;

use RuntimeException;
use VeyroPHP\Exception\VeyroPHPException;

/**
 * Base exception for database configuration and usage errors.
 *
 * Messages never contain bound values or passwords.
 */
class DatabaseException extends RuntimeException implements VeyroPHPException
{
    public static function invalidConfig(string $connection, string $key, string $reason): self
    {
        return new self(sprintf('Invalid database configuration for connection "%s", key "%s": %s.', $connection, $key, $reason));
    }

    public static function unknownConnection(string $name): self
    {
        return new self(sprintf('Database connection "%s" is not configured.', $name));
    }

    public static function invalidIdentifier(string $identifier): self
    {
        return new self(sprintf(
            'Invalid SQL identifier "%s": use letters, digits and "_" (not starting with a digit), "." between table and column, an optional " as alias", or raw() for expressions.',
            $identifier,
        ));
    }

    public static function invalidOperator(string $operator): self
    {
        return new self(sprintf('Unsupported comparison operator "%s".', $operator));
    }

    public static function unsupportedBinding(int $position, string $type): self
    {
        return new self(sprintf('Cannot bind a value of type %s at position %d.', $type, $position));
    }

    public static function invalidQuery(string $reason): self
    {
        return new self($reason);
    }
}
