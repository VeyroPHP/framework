<?php

declare(strict_types=1);

namespace VeyroPHP\Auth\Access;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use VeyroPHP\Http\Middleware\ParameterizedMiddleware;

/**
 * Allows the request only if the current user passes an ability that needs no record:
 *
 *     $router->get('/reports/export', ...)->middleware(Authorize::for('reports.export'));
 *
 * Checks on one record ("update" a post) belong in the handler, after the record is
 * loaded: $gate->authorize('update', $post). Guests: 401. Denied users: 403.
 */
final class Authorize implements ParameterizedMiddleware
{
    private ?string $ability = null;

    public function __construct(private readonly Gate $gate) {}

    /**
     * The middleware string for a route: "Authorize:reports.export".
     */
    public static function for(string $ability): string
    {
        AccessRules::assertName($ability);

        return self::class . ':' . $ability;
    }

    public function withArguments(string ...$arguments): static
    {
        if (count($arguments) !== 1) {
            throw new AccessException('Authorize needs exactly one ability, e.g. Authorize::for(\'reports.export\').');
        }

        $clone = clone $this;
        $clone->ability = $arguments[0];

        return $clone;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->ability === null) {
            throw new AccessException('Authorize needs an ability, e.g. Authorize::for(\'reports.export\').');
        }

        $this->gate->authorize($this->ability);

        return $handler->handle($request);
    }
}
