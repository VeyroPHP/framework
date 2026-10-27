<?php

declare(strict_types=1);

namespace VeyroPHP\Http;

use Closure;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Adapts a closure to a PSR-15 request handler.
 *
 * @internal Framework implementation detail, not covered by the backward-compatibility promise.
 */
final class CallableHandler implements RequestHandlerInterface
{
    /**
     * @param Closure(ServerRequestInterface): ResponseInterface $handler
     */
    public function __construct(private readonly Closure $handler) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return ($this->handler)($request);
    }
}
