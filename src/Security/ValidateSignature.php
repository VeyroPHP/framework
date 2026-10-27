<?php

declare(strict_types=1);

namespace VeyroPHP\Security;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use VeyroPHP\Http\Exception\HttpException;
use VeyroPHP\Http\HttpKernel;

/**
 * Route middleware: 403 unless the URL carries a valid, unexpired signature
 * made by UrlSigner.
 *
 *     $router->get('/invite/{team}', ...)->middleware(ValidateSignature::class);
 */
final class ValidateSignature implements MiddlewareInterface
{
    public function __construct(private readonly UrlSigner $signer) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getAttribute(HttpKernel::PATH_ATTRIBUTE);

        if (!is_string($path) || !$this->signer->verify($path, $request->getQueryParams())) {
            throw new HttpException(403, 'This link is invalid or has expired.');
        }

        return $handler->handle($request);
    }
}
