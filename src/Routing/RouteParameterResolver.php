<?php

declare(strict_types=1);

namespace VeyroPHP\Routing;

/**
 * Turns a route parameter into an object for a handler parameter typed with a
 * class, e.g. {user} into a User model. Registered with
 * HandlerInvoker::addParameterResolver().
 */
interface RouteParameterResolver
{
    /**
     * @param class-string $class
     */
    public function supports(string $class): bool;

    /**
     * Returns the object, or throws an exception with an HTTP status (such as 404).
     *
     * @param class-string $class
     */
    public function resolve(string $class, string $value): object;
}
