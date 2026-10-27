<?php

declare(strict_types=1);

namespace VeyroPHP\Auth\Tokens;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use VeyroPHP\Http\Exception\HttpException;
use VeyroPHP\Http\Middleware\ParameterizedMiddleware;

/**
 * Route middleware after AuthenticateToken: 403 unless the token has all the
 * given abilities.
 *
 *     ->middleware(AuthenticateToken::class)->middleware(RequireAbility::for('orders.read'))
 *
 * Abilities restrict a token; they do not grant permissions. Check RBAC
 * permissions too (RequirePermission) where the user's role matters.
 */
final class RequireAbility implements ParameterizedMiddleware
{
    /** @var list<string> */
    private array $abilities = [];

    public static function for(string $ability, string ...$more): string
    {
        return self::class . ':' . implode(',', [$ability, ...$more]);
    }

    public function withArguments(string ...$arguments): static
    {
        $clone = clone $this;
        $clone->abilities = array_values($arguments);

        return $clone;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->abilities === []) {
            throw new \LogicException('RequireAbility needs at least one ability, e.g. RequireAbility::for(\'orders.read\').');
        }

        $token = $request->getAttribute(AuthenticateToken::ATTRIBUTE);

        if (!$token instanceof AccessToken) {
            throw new HttpException(401, 'A bearer token is required.', ['WWW-Authenticate' => 'Bearer']);
        }

        foreach ($this->abilities as $ability) {
            if (!$token->can($ability)) {
                throw new HttpException(403, sprintf('This token may not "%s".', $ability));
            }
        }

        return $handler->handle($request);
    }
}
