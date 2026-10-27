<?php

declare(strict_types=1);

namespace VeyroPHP\Security;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use VeyroPHP\Http\Exception\HttpException;
use VeyroPHP\Http\Middleware\ParameterizedMiddleware;

/**
 * Rejects form submissions that fill a field people cannot see. Simple bots fill
 * every field; people leave the hidden one empty.
 *
 *     $router->post('/contact', $handler)->middleware(Honeypot::field('website'));
 *
 *     <!-- in the form; hidden from people, ignored by screen readers and autofill -->
 *     <div hidden aria-hidden="true"><input name="website" tabindex="-1" autocomplete="off"></div>
 *
 * A filled field (whitespace does not count) gives 400 before the handler runs.
 * GET requests and forms without the field pass. It stops simple bots only: use
 * it together with rate limiting.
 */
final class Honeypot implements ParameterizedMiddleware
{
    private string $field = 'website';

    public static function field(string $name): string
    {
        self::assertName($name);

        return self::class . ':' . $name;
    }

    public function withArguments(string ...$arguments): static
    {
        $clone = clone $this;
        $clone->field = $arguments[0] ?? 'website';
        self::assertName($clone->field);

        return $clone;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            $body = $request->getParsedBody();
            $value = is_array($body) ? ($body[$this->field] ?? null) : null;

            if ($value !== null && (!is_string($value) || trim($value) !== '')) {
                throw new HttpException(400, 'The request could not be processed.');
            }
        }

        return $handler->handle($request);
    }

    private static function assertName(string $name): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_-]*$/D', $name) !== 1) {
            throw new \InvalidArgumentException(sprintf('Invalid honeypot field name "%s".', $name));
        }
    }
}
