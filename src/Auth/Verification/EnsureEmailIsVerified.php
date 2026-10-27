<?php

declare(strict_types=1);

namespace VeyroPHP\Auth\Verification;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use VeyroPHP\Auth\Auth;
use VeyroPHP\Http\ContentNegotiation;
use VeyroPHP\Http\Exception\HttpException;
use VeyroPHP\Http\ResponseFactory;

/**
 * Route middleware for pages that need a confirmed email address. Use after
 * Authenticate. Unverified users get a redirect to the notice page (config
 * "auth.verification.notice_path", default "/email/verify"), or 403 for JSON
 * requests.
 */
final class EnsureEmailIsVerified implements MiddlewareInterface
{
    public function __construct(
        private readonly Auth $auth,
        private readonly EmailVerifier $verifier,
        private readonly ResponseFactory $responses,
        private readonly string $noticePath = '/email/verify',
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $user = $this->auth->user() ?? throw new HttpException(401, 'Unauthenticated.');

        if ($this->verifier->isVerified($user)) {
            return $handler->handle($request);
        }

        if (ContentNegotiation::prefersJson($request)) {
            throw new HttpException(403, 'Verify your email address before opening this page.');
        }

        return $this->responses->redirect($this->noticePath);
    }
}
