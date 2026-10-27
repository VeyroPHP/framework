<?php

declare(strict_types=1);

namespace VeyroPHP\View\Exception;

use RuntimeException;
use Throwable;
use VeyroPHP\Exception\VeyroPHPException;

final class ViewException extends RuntimeException implements VeyroPHPException
{
    public static function invalidName(string $name): self
    {
        return new self(sprintf('Invalid view name "%s": use letters, digits, "_" and "-", with "." between directories.', $name));
    }

    public static function notFound(string $name, string $path): self
    {
        return new self(sprintf('View "%s" not found (expected file "%s").', $name, $path));
    }

    public static function invalidDataKey(string $name, string $key): self
    {
        return new self(sprintf('Invalid data key "%s" for view "%s": keys must be valid PHP variable names and must not be "e", "view" or "this".', $key, $name));
    }

    public static function renderFailed(string $name, Throwable $previous): self
    {
        return new self(sprintf('Rendering view "%s" failed: %s: %s', $name, $previous::class, $previous->getMessage()), 0, $previous);
    }
}
