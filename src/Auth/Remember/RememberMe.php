<?php

declare(strict_types=1);

namespace VeyroPHP\Auth\Remember;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;
use VeyroPHP\Http\HttpErrorResponder;

/**
 * Global middleware for "remember me". Add it after StartSession:
 *
 *     [StartSession::class, RememberMe::class, VerifyCsrfToken::class, ...]
 *
 * It hands the request's remember cookie to RememberTokens; Auth::user() then logs a
 * remembered user in when the session has none. Afterwards it adds the cookie that
 * this request set or removed (login with "remember me", logout, an invalid token).
 *
 * An invalid cookie is usually found by Authenticate, which then throws 401. With an
 * HttpErrorResponder the error is rendered here, so the response still removes the
 * cookie and the next request does not look it up again (as StartSession does, VQ-046).
 */
final class RememberMe implements MiddlewareInterface
{
    public function __construct(
        private readonly RememberTokens $tokens,
        private readonly ?HttpErrorResponder $errors = null,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $cookie = $request->getCookieParams()[$this->tokens->cookieName()] ?? null;
        $this->tokens->useRequestCookie(is_string($cookie) ? $cookie : null, $request->getUri()->getScheme() === 'https');

        try {
            $response = $handler->handle($request);
        } catch (Throwable $e) {
            if ($this->tokens->pendingCookie() === null || $this->errors === null) {
                throw $e;
            }

            $response = $this->errors->respond($e, $request);
        }

        $pending = $this->tokens->pendingCookie();

        return $pending === null ? $response : $response->withAddedHeader('Set-Cookie', $pending->toHeaderValue());
    }
}
