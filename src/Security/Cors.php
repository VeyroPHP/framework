<?php

declare(strict_types=1);

namespace VeyroPHP\Security;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;
use VeyroPHP\Http\HttpErrorResponder;
use VeyroPHP\Http\ResponseFactory;

/**
 * Cross-Origin Resource Sharing for browsers calling the app from other origins.
 *
 * - Only origins in the allow list get CORS headers; "*" allows any origin but
 *   cannot be combined with credentials (rejected at construction).
 * - Preflight requests (OPTIONS with Access-Control-Request-Method) are answered
 *   with 204 here, without reaching the router.
 * - Errors thrown further down are turned into responses here, so they still
 *   carry CORS headers and the browser can read them.
 * - "Vary: Origin" is always added, so caches do not mix responses for different origins.
 *
 * Register it as the outermost global middleware.
 */
final class Cors implements MiddlewareInterface
{
    /**
     * @param list<string> $allowedOrigins exact origins such as "https://app.example.com", or ["*"]
     * @param list<string> $allowedMethods
     * @param list<string> $allowedHeaders
     * @param list<string> $exposedHeaders
     */
    public function __construct(
        private readonly HttpErrorResponder $errors,
        private readonly ResponseFactory $responses,
        private readonly array $allowedOrigins = [],
        private readonly array $allowedMethods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
        private readonly array $allowedHeaders = ['Content-Type', 'Accept', 'Authorization', 'X-CSRF-Token', 'X-Requested-With'],
        private readonly array $exposedHeaders = [],
        private readonly int $maxAge = 600,
        private readonly bool $allowCredentials = false,
    ) {
        if ($allowCredentials && in_array('*', $allowedOrigins, true)) {
            throw new \InvalidArgumentException('CORS: allowed origin "*" cannot be combined with credentials; list the origins explicitly.');
        }

        foreach ($allowedOrigins as $origin) {
            if ($origin !== '*' && preg_match('#^https?://[A-Za-z0-9.-]+(:\d+)?$#D', $origin) !== 1) {
                throw new \InvalidArgumentException(sprintf('CORS: invalid origin "%s"; use scheme://host[:port] without a path.', $origin));
            }
        }
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $origin = $request->getHeaderLine('Origin');
        $allowed = $origin !== '' && $this->isAllowed($origin);

        if ($allowed && strtoupper($request->getMethod()) === 'OPTIONS' && $request->hasHeader('Access-Control-Request-Method')) {
            return $this->withCorsHeaders($this->preflight($this->responses->empty(204), $request), $origin);
        }

        try {
            $response = $handler->handle($request);
        } catch (Throwable $e) {
            if (!$allowed) {
                throw $e;
            }
            $response = $this->errors->respond($e, $request);
        }

        $response = $response->withAddedHeader('Vary', 'Origin');

        return $allowed ? $this->withCorsHeaders($response, $origin) : $response;
    }

    private function isAllowed(string $origin): bool
    {
        return in_array('*', $this->allowedOrigins, true) || in_array($origin, $this->allowedOrigins, true);
    }

    private function preflight(ResponseInterface $response, ServerRequestInterface $request): ResponseInterface
    {
        $response = $response
            ->withHeader('Access-Control-Allow-Methods', implode(', ', $this->allowedMethods))
            ->withHeader('Access-Control-Allow-Headers', implode(', ', $this->allowedHeaders))
            ->withHeader('Vary', 'Origin, Access-Control-Request-Method, Access-Control-Request-Headers');

        return $this->maxAge > 0 ? $response->withHeader('Access-Control-Max-Age', (string) $this->maxAge) : $response;
    }

    private function withCorsHeaders(ResponseInterface $response, string $origin): ResponseInterface
    {
        $wildcard = in_array('*', $this->allowedOrigins, true) && !$this->allowCredentials;
        $response = $response->withHeader('Access-Control-Allow-Origin', $wildcard ? '*' : $origin);

        if ($this->allowCredentials) {
            $response = $response->withHeader('Access-Control-Allow-Credentials', 'true');
        }

        if ($this->exposedHeaders !== []) {
            $response = $response->withHeader('Access-Control-Expose-Headers', implode(', ', $this->exposedHeaders));
        }

        return $response;
    }
}
