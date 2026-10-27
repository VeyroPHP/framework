<?php

declare(strict_types=1);

namespace VeyroPHP\Http\Middleware;

use Psr\Http\Server\MiddlewareInterface;

/**
 * Middleware that accepts arguments in its route definition:
 *
 *     ->middleware(RequirePermission::class . ':posts.edit,posts.publish')
 *
 * The kernel resolves the class from the container (so dependencies are
 * injected) and then calls withArguments() with the comma-separated values.
 */
interface ParameterizedMiddleware extends MiddlewareInterface
{
    public function withArguments(string ...$arguments): static;
}
