<?php

declare(strict_types=1);

namespace VeyroPHP\Routing\Exception;

use LogicException;
use VeyroPHP\Exception\VeyroPHPException;

/**
 * Thrown for invalid route definitions and URL generation errors.
 *
 * These are programming errors, not client errors, so they map to 500.
 */
final class RoutingException extends LogicException implements VeyroPHPException
{
    public static function invalidPath(string $path, string $reason): self
    {
        return new self(sprintf('Invalid route path "%s": %s.', $path, $reason));
    }

    public static function invalidMethod(string $method): self
    {
        return new self(sprintf('Unsupported HTTP method "%s". Use GET, HEAD, POST, PUT, PATCH, DELETE or OPTIONS.', $method));
    }

    public static function invalidConstraint(string $path, string $parameter, string $pattern): self
    {
        return new self(sprintf('Invalid regular expression "%s" for parameter "%s" in route "%s".', $pattern, $parameter, $path));
    }

    public static function unknownParameter(string $path, string $parameter): self
    {
        return new self(sprintf('Route "%s" has no parameter "%s".', $path, $parameter));
    }

    public static function invalidHandler(string $path, string $reason): self
    {
        return new self(sprintf('Invalid handler for route "%s": %s.', $path, $reason));
    }

    public static function duplicateRoute(string $method, string $path): self
    {
        return new self(sprintf('Route %s %s is defined more than once.', $method, $path));
    }

    public static function duplicateName(string $name): self
    {
        return new self(sprintf('Route name "%s" is used more than once.', $name));
    }

    public static function unknownName(string $name): self
    {
        return new self(sprintf('No route named "%s".', $name));
    }

    public static function frozen(): self
    {
        return new self('Routes cannot be added or changed after the router has started matching requests.');
    }

    public static function missingParameter(string $name, string $parameter): self
    {
        return new self(sprintf('Missing parameter "%s" to generate the URL for route "%s".', $parameter, $name));
    }

    public static function parameterMismatch(string $name, string $parameter): self
    {
        return new self(sprintf('Parameter "%s" does not match its constraint in route "%s".', $parameter, $name));
    }

    public static function unresolvableArgument(string $handler, string $parameter, string $reason): self
    {
        return new self(sprintf('Cannot resolve argument $%s of handler %s: %s.', $parameter, $handler, $reason));
    }

    public static function unsupportedReturn(string $handler, string $type): self
    {
        return new self(sprintf('Handler %s returned %s; return a response, string, array, JsonSerializable or null.', $handler, $type));
    }
}
