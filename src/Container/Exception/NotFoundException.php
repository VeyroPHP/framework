<?php

declare(strict_types=1);

namespace VeyroPHP\Container\Exception;

use Psr\Container\NotFoundExceptionInterface;

/**
 * Thrown when an id has no binding and is not an instantiable class.
 */
final class NotFoundException extends ContainerException implements NotFoundExceptionInterface
{
    public static function forId(string $id): self
    {
        return new self(sprintf('No binding found for "%s", and it is not an instantiable class.', $id));
    }
}
