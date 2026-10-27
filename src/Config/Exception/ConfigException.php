<?php

declare(strict_types=1);

namespace VeyroPHP\Config\Exception;

use RuntimeException;
use Throwable;
use VeyroPHP\Exception\VeyroPHPException;

/**
 * Thrown for missing keys, wrong value types and unreadable configuration files.
 *
 * Messages never contain configuration values, because they may hold secrets.
 */
final class ConfigException extends RuntimeException implements VeyroPHPException
{
    public static function invalidKey(string $key): self
    {
        return new self(sprintf('Invalid config key "%s": keys must be non-empty and must not contain empty segments.', $key));
    }

    public static function missingKey(string $key): self
    {
        return new self(sprintf('Config key "%s" is not defined.', $key));
    }

    public static function wrongType(string $key, string $expected, mixed $value): self
    {
        return new self(sprintf('Config key "%s" must be of type %s, %s given.', $key, $expected, get_debug_type($value)));
    }

    /**
     * A value of the right type that is still not allowed. The value itself is not shown.
     */
    public static function invalidValue(string $key, string $expected): self
    {
        return new self(sprintf('Config key "%s" must be %s.', $key, $expected));
    }

    public static function directoryNotFound(string $directory): self
    {
        return new self(sprintf('Config directory "%s" does not exist or is not readable.', $directory));
    }

    public static function notCacheable(string $key, mixed $value): self
    {
        return new self(sprintf('Config key "%s" holds a %s, which cannot be cached; use strings, numbers, booleans and arrays in config files.', $key, get_debug_type($value)));
    }

    public static function invalidFileName(string $file): self
    {
        return new self(sprintf('Config file "%s" must not contain a dot before ".php", because its key could not be read with dot notation.', $file));
    }

    public static function fileDidNotReturnArray(string $file, mixed $returned): self
    {
        return new self(sprintf('Config file "%s" must return an array, %s returned.', $file, get_debug_type($returned)));
    }

    public static function fileFailed(string $file, Throwable $previous): self
    {
        return new self(sprintf('Config file "%s" threw %s while loading.', $file, $previous::class), 0, $previous);
    }
}
