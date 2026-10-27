<?php

declare(strict_types=1);

namespace VeyroPHP\Container\Exception;

/**
 * Thrown when resolving a service requires that same service again.
 */
final class CircularDependencyException extends ContainerException
{
    /**
     * @param list<string> $chain resolution chain ending with the repeated id
     */
    public static function forChain(array $chain): self
    {
        return new self('Circular dependency detected: ' . implode(' -> ', $chain) . '.');
    }
}
