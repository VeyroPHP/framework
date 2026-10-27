<?php

declare(strict_types=1);

namespace VeyroPHP\Auth;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use VeyroPHP\Http\ContentNegotiation;
use VeyroPHP\Http\Exception\HttpException;
use VeyroPHP\Http\ResponseFactory;

/**
 * Lets only logged-in users through. Guests get 401 (JSON clients) or a
 * redirect to the login path (browsers).
 */
final class Authenticate implements MiddlewareInterface
{
    public function __construct(
        private readonly Auth $auth,
        private readonly ResponseFactory $responses,
        private readonly AuthConfig $config,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->auth->check()) {
            return $handler->handle($request);
        }

        if (ContentNegotiation::prefersJson($request)) {
            throw new HttpException(401, 'Unauthenticated.');
        }

        return $this->responses->redirect($this->config->loginPath);
    }
}
