<?php

declare(strict_types=1);

namespace VeyroPHP\Http\Middleware;

use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use VeyroPHP\Http\Exception\BadRequestHttpException;

/**
 * Decodes JSON request bodies into the parsed body as an associative array.
 *
 * Applies when the Content-Type is "application/json" or ends in "+json".
 * An empty body leaves the parsed body unchanged. Invalid JSON, or JSON that is
 * not an object or array, results in a 400 response.
 */
final class JsonBodyParser implements MiddlewareInterface
{
    /**
     * @param positive-int $maxDepth maximum nesting depth of the decoded JSON
     */
    public function __construct(private readonly int $maxDepth = 64) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!self::isJson($request->getHeaderLine('Content-Type'))) {
            return $handler->handle($request);
        }

        $body = (string) $request->getBody();

        if (trim($body) === '') {
            return $handler->handle($request);
        }

        try {
            $data = json_decode($body, true, $this->maxDepth, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException $e) {
            throw new BadRequestHttpException('Malformed JSON request body.', $e);
        }

        if (!is_array($data)) {
            throw new BadRequestHttpException('JSON request body must be an object or an array.');
        }

        return $handler->handle($request->withParsedBody($data));
    }

    private static function isJson(string $contentType): bool
    {
        $mediaType = strtolower(trim(explode(';', $contentType, 2)[0]));

        return $mediaType === 'application/json' || (str_starts_with($mediaType, 'application/') && str_ends_with($mediaType, '+json'));
    }
}
