<?php

declare(strict_types=1);

namespace VeyroPHP\Application\Exception;

use LogicException;
use VeyroPHP\Exception\VeyroPHPException;

/**
 * Thrown when the application lifecycle is used in the wrong order.
 */
final class ApplicationException extends LogicException implements VeyroPHPException
{
    public static function alreadyBooted(string $action): self
    {
        return new self(sprintf('Cannot %s: the application has already been booted.', $action));
    }

    public static function duplicateProvider(string $class): self
    {
        return new self(sprintf('Service provider "%s" is already registered.', $class));
    }
}
