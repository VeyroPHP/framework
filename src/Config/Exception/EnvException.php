<?php

declare(strict_types=1);

namespace VeyroPHP\Config\Exception;

use RuntimeException;
use VeyroPHP\Exception\VeyroPHPException;

/**
 * Thrown for .env syntax errors, missing variables and values of the wrong form.
 *
 * Messages contain variable names and line numbers, never values.
 */
final class EnvException extends RuntimeException implements VeyroPHPException
{
    public static function syntax(int $line, string $problem): self
    {
        return new self(sprintf('Invalid .env syntax on line %d: %s.', $line, $problem));
    }

    public static function duplicate(string $name, int $line): self
    {
        return new self(sprintf('Environment variable "%s" is defined twice in .env (again on line %d).', $name, $line));
    }

    public static function fileNotReadable(string $path): self
    {
        return new self(sprintf('Env file "%s" exists but could not be read.', $path));
    }

    public static function missing(string $name): self
    {
        return new self(sprintf('Environment variable "%s" is not set and no default was given.', $name));
    }

    public static function invalid(string $name, string $expected): self
    {
        return new self(sprintf('Environment variable "%s" must be %s.', $name, $expected));
    }
}
