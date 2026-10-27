<?php

declare(strict_types=1);

// Controllers and middleware used by the HTTP and routing tests.

namespace VeyroPHP\Tests\Fixtures\Http;

use JsonSerializable;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use VeyroPHP\Http\ResponseFactory;

final class Greeter
{
    public function greet(string $name): string
    {
        return 'Hello, ' . $name;
    }
}

final class UserController
{
    public function __construct(private readonly Greeter $greeter) {}

    /**
     * @return array{id: int, path: string}
     */
    public function show(int $id, ServerRequestInterface $request): array
    {
        return ['id' => $id, 'path' => $request->getUri()->getPath()];
    }

    public function greet(string $name): string
    {
        return $this->greeter->greet($name);
    }

    public static function staticAction(): string
    {
        return 'static';
    }

    /** @phpstan-ignore method.unused (used to test that private methods are rejected as handlers) */
    private function hidden(): string
    {
        return 'hidden';
    }
}

final class InvokableController
{
    public function __invoke(ResponseFactory $responses): ResponseInterface
    {
        return $responses->text('invoked', 201);
    }
}

final class NotInvokable {}

final class Item implements JsonSerializable
{
    /**
     * @return array{item: bool}
     */
    public function jsonSerialize(): array
    {
        return ['item' => true];
    }
}

final class FailingController
{
    public function __invoke(): never
    {
        throw new RuntimeException('database password=hunter2 is wrong');
    }
}

/**
 * Appends its label to the X-Trace response header and the "trace" request attribute.
 */
final class TraceMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly string $label = 'trace') {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $trace = $request->getAttribute('trace', '');
        $request = $request->withAttribute('trace', (is_string($trace) ? $trace : '') . $this->label . '>');

        $response = $handler->handle($request);

        return $response->withAddedHeader('X-Trace', $this->label);
    }
}

final class ShortCircuitMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly ResponseFactory $responses) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $this->responses->text('blocked', 403);
    }
}
