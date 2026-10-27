<?php

declare(strict_types=1);

namespace VeyroPHP\Exception;

use InvalidArgumentException;

/**
 * Thrown when a filesystem path is missing, malformed or escapes its allowed root.
 */
final class InvalidPathException extends InvalidArgumentException implements VeyroPHPException
{
    public static function baseDirectoryNotFound(string $path): self
    {
        return new self(sprintf('Base path "%s" does not exist or is not a directory.', $path));
    }

    public static function emptyBasePath(): self
    {
        return new self('Base path must not be empty.');
    }

    public static function containsNullByte(): self
    {
        return new self('Path must not contain null bytes.');
    }

    public static function absoluteNotAllowed(string $path): self
    {
        return new self(sprintf('Expected a path relative to the base path, got absolute path "%s".', $path));
    }

    public static function traversalNotAllowed(string $path): self
    {
        return new self(sprintf('Path "%s" must not contain ".." segments.', $path));
    }
}
