<?php

declare(strict_types=1);

namespace VeyroPHP\Container\Exception;

use Psr\Container\ContainerExceptionInterface;
use ReflectionParameter;
use RuntimeException;
use VeyroPHP\Exception\VeyroPHPException;

/**
 * Thrown when a service is registered incorrectly or cannot be built.
 */
class ContainerException extends RuntimeException implements ContainerExceptionInterface, VeyroPHPException
{
    public static function emptyId(): self
    {
        return new self('Service id must not be empty.');
    }

    public static function concreteNotFound(string $id, string $concrete): self
    {
        return new self(sprintf('Cannot bind "%s" to "%s": class "%s" does not exist.', $id, $concrete, $concrete));
    }

    public static function concreteNotInstantiable(string $id, string $concrete): self
    {
        return new self(sprintf('Cannot bind "%s" to "%s": the class is abstract, an interface or has a non-public constructor.', $id, $concrete));
    }

    public static function concreteNotSubtype(string $id, string $concrete): self
    {
        return new self(sprintf('Cannot bind "%s" to "%s": "%s" does not extend or implement "%s".', $id, $concrete, $concrete, $id));
    }

    /**
     * @param list<string> $chain
     */
    public static function notInstantiable(string $class, array $chain): self
    {
        return new self(sprintf(
            'Class "%s" cannot be instantiated (abstract, interface or non-public constructor)%s.',
            $class,
            self::describeChain($chain),
        ));
    }

    /**
     * @param list<string> $chain
     */
    public static function unresolvableParameter(ReflectionParameter $parameter, string $reason, array $chain): self
    {
        $class = $parameter->getDeclaringClass()?->getName() ?? 'unknown class';

        return new self(sprintf(
            'Cannot resolve parameter $%s of %s::__construct()%s: %s.',
            $parameter->getName(),
            $class,
            self::describeChain($chain),
            $reason,
        ));
    }

    /**
     * @param list<string> $chain
     */
    public static function captiveDependency(string $id, array $chain): self
    {
        return new self(sprintf(
            'Scoped service "%s" cannot be injected into a singleton, because the singleton would keep it after the scope is reset%s.',
            $id,
            self::describeChain($chain),
        ));
    }

    public static function wrongType(string $id, mixed $value): self
    {
        return new self(sprintf('Service "%s" resolved to %s, expected an instance of "%s".', $id, get_debug_type($value), $id));
    }

    /**
     * @param list<string> $chain
     */
    protected static function describeChain(array $chain): string
    {
        return $chain === [] ? '' : ' while resolving ' . implode(' -> ', $chain);
    }
}
