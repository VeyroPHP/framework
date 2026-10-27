<?php

declare(strict_types=1);

namespace VeyroPHP\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use VeyroPHP\Http\CallableHandler;

/**
 * Runs PSR-15 middleware in order, ending with a final handler.
 *
 * The first middleware in the list is the outermost: it sees the request
 * first and the response last.
 *
 * @internal Framework implementation detail, not covered by the backward-compatibility promise.
 */
final class Pipeline implements RequestHandlerInterface
{
    /**
     * @param list<MiddlewareInterface> $middleware
     */
    public function __construct(
        private readonly array $middleware,
        private readonly RequestHandlerInterface $final,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->dispatch(0, $request);
    }

    private function dispatch(int $index, ServerRequestInterface $request): ResponseInterface
    {
        $middleware = $this->middleware[$index] ?? null;

        if ($middleware === null) {
            return $this->final->handle($request);
        }

        return $middleware->process(
            $request,
            new CallableHandler(fn(ServerRequestInterface $next): ResponseInterface => $this->dispatch($index + 1, $next)),
        );
    }
}
