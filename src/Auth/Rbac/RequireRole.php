<?php

declare(strict_types=1);

namespace VeyroPHP\Auth\Rbac;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use VeyroPHP\Auth\Auth;
use VeyroPHP\Http\Exception\HttpException;
use VeyroPHP\Http\Middleware\ParameterizedMiddleware;

/**
 * Allows the request only if the logged-in user has one of the roles.
 * Prefer RequirePermission: permissions can be regrouped without code changes.
 *
 *     ->middleware(RequireRole::for('admin', 'super-admin'))
 */
final class RequireRole implements ParameterizedMiddleware
{
    /** @var list<string> */
    private array $roles = [];

    public function __construct(
        private readonly Auth $auth,
        private readonly Rbac $rbac,
    ) {}

    public static function for(string $role, string ...$more): string
    {
        return self::class . ':' . implode(',', [$role, ...$more]);
    }

    public function withArguments(string ...$arguments): static
    {
        $clone = clone $this;
        $clone->roles = array_values($arguments);

        return $clone;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->roles === []) {
            throw new \LogicException('RequireRole needs at least one role.');
        }

        $user = $this->auth->user() ?? throw new HttpException(401, 'Unauthenticated.');

        foreach ($this->roles as $role) {
            if ($this->rbac->hasRole($user, $role)) {
                return $handler->handle($request);
            }
        }

        throw new HttpException(403, 'You do not have permission to do this.');
    }
}
