<?php

declare(strict_types=1);

namespace VeyroPHP\Logging\Exception;

use RuntimeException;
use VeyroPHP\Exception\VeyroPHPException;

/**
 * Thrown when a log entry cannot be written or a logger is misconfigured.
 */
final class LoggingException extends RuntimeException implements VeyroPHPException
{
    public static function invalidChannel(string $channel): self
    {
        return new self(sprintf('Invalid log channel "%s": use letters, digits, ".", "_" or "-".', $channel));
    }

    public static function cannotCreateDirectory(string $directory, string $reason): self
    {
        return new self(sprintf('Cannot create log directory "%s": %s', $directory, $reason));
    }

    public static function cannotWrite(string $path, string $reason): self
    {
        return new self(sprintf('Cannot write to log file "%s": %s', $path, $reason));
    }
}
