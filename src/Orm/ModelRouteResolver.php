<?php

declare(strict_types=1);

namespace VeyroPHP\Orm;

use VeyroPHP\Orm\Exception\ModelNotFoundException;
use VeyroPHP\Routing\RouteParameterResolver;

/**
 * Resolves route parameters for handler arguments typed with a Model:
 *
 *     $router->get('/users/{user}', fn (User $user) => $user);
 *
 * Models with PUBLIC_ID are looked up by their ULID; others by primary key
 * (digits only). Anything not found, including malformed values, is a 404.
 * This does not check authorization; the handler must still verify that the
 * current user may access the model.
 *
 * @internal Framework implementation detail, not covered by the backward-compatibility promise.
 */
final class ModelRouteResolver implements RouteParameterResolver
{
    public function __construct(private readonly Orm $orm) {}

    public function supports(string $class): bool
    {
        return is_subclass_of($class, Model::class);
    }

    public function resolve(string $class, string $value): object
    {
        if (!is_subclass_of($class, Model::class)) {
            throw new ModelNotFoundException($class);
        }

        if ($class::publicIdColumn() !== null) {
            return $this->orm->findByPublicIdOrFail($class, $value);
        }

        if (preg_match('/^[1-9]\d{0,18}$/D', $value) !== 1) {
            throw new ModelNotFoundException($class);
        }

        return $this->orm->findOrFail($class, (int) $value);
    }
}
