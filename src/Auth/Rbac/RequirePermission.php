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
 * Allows the request only if the logged-in user has one of the permissions.
 *
 *     $router->get('/admin/posts', ...)->middleware(RequirePermission::for('posts.edit'));
 *     $router->get('/reports', ...)->middleware(RequirePermission::for('reports.view', 'reports.export'));
 *
 * Guests: 401. Logged in without permission: 403. Use Authenticate before it
 * if browsers should be redirected to the login page instead of seeing 401.
 */
final class RequirePermission implements ParameterizedMiddleware
{
    /** @var list<string> */
    private array $permissions = [];

    public function __construct(
        private readonly Auth $auth,
        private readonly Rbac $rbac,
    ) {}

    /**
     * The middleware string for a route: "RequirePermission:a,b".
     */
    public static function for(string $permission, string ...$more): string
    {
        return self::class . ':' . implode(',', [$permission, ...$more]);
    }

    public function withArguments(string ...$arguments): static
    {
        $clone = clone $this;
        $clone->permissions = array_values($arguments);

        return $clone;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->permissions === []) {
            throw new \LogicException('RequirePermission needs at least one permission, e.g. RequirePermission::for(\'posts.edit\').');
        }

        $user = $this->auth->user() ?? throw new HttpException(401, 'Unauthenticated.');

        if (!$this->rbac->canAny($user, $this->permissions)) {
            throw new HttpException(403, 'You do not have permission to do this.');
        }

        return $handler->handle($request);
    }
}
