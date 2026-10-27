<?php

declare(strict_types=1);

// Classes used by the benchmarks (loaded through the autoload-dev classmap).

namespace VeyroPHP\Bench\Fixtures;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class Clock {}

final class Repository
{
    public function __construct(public readonly Clock $clock) {}
}

final class Service
{
    public function __construct(public readonly Repository $repository, public readonly Clock $clock) {}
}

final class Controller
{
    public function __construct(public readonly Service $service) {}
}

final class PassThrough implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request);
    }
}
