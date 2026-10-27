<?php

declare(strict_types=1);

namespace VeyroPHP\Auth\Tokens;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use VeyroPHP\Auth\Auth;
use VeyroPHP\Auth\AuthConfig;
use VeyroPHP\Http\Exception\HttpException;
use VeyroPHP\Orm\Orm;

/**
 * Route middleware for APIs: requires "Authorization: Bearer <token>" and makes
 * the token's user the current user (Auth::user()) for this request only.
 * The AccessToken is available as the request attribute AuthenticateToken::ATTRIBUTE.
 *
 * Answers 401 with "WWW-Authenticate: Bearer" for a missing, unknown, expired
 * or revoked token, or a deleted user. Use it instead of Authenticate on API
 * routes, which are excepted from CSRF checks (session.csrf_except).
 */
final class AuthenticateToken implements MiddlewareInterface
{
    public const ATTRIBUTE = 'veyrophp.access_token';

    public function __construct(
        private readonly ApiTokens $tokens,
        private readonly Auth $auth,
        private readonly Orm $orm,
        private readonly AuthConfig $config,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $header = $request->getHeaderLine('Authorization');

        if (preg_match('/^Bearer\s+(\S+)$/iD', $header, $m) !== 1) {
            throw self::unauthorized('A bearer token is required.');
        }

        $token = $this->tokens->find($m[1]) ?? throw self::unauthorized('The token is invalid, expired or revoked.');
        $user = $this->orm->find($this->config->model, $token->userId) ?? throw self::unauthorized('The token is invalid, expired or revoked.');

        $this->auth->loginForRequest($user);

        return $handler->handle($request->withAttribute(self::ATTRIBUTE, $token));
    }

    private static function unauthorized(string $message): HttpException
    {
        return new HttpException(401, $message, ['WWW-Authenticate' => 'Bearer']);
    }
}
