<?php

declare(strict_types=1);

namespace VeyroPHP\Security;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use VeyroPHP\Http\ClientIp;
use VeyroPHP\Http\Exception\HttpException;
use VeyroPHP\Http\HttpKernel;
use VeyroPHP\Http\Middleware\ParameterizedMiddleware;
use VeyroPHP\Routing\Route;

/**
 * Limits requests per client and route.
 *
 *     ->middleware(ThrottleRequests::limit(60, 1))        // 60 per minute
 *     ->middleware(ThrottleRequests::limit(5, 1, 'login')) // shared bucket name
 *
 * - Without a bucket name the key is the matched route (its name, or its methods
 *   and pattern), so every spelling of the URL and every value of its parameters
 *   share one limit (VQ-013). Outside routing the normalized path is used.
 * - Clients are keyed by IP; IPv6 addresses by their first $ipv6Prefix bits (64 by
 *   default, config security.throttle.ipv6_prefix), so one network cannot rotate
 *   addresses for fresh limits. Clients behind one IPv4 address share a limit.
 * - The limit is checked and counted in one step (RateLimiter::attempt()).
 *
 * Over the limit: 429 with Retry-After. Successful responses carry
 * X-RateLimit-Limit and X-RateLimit-Remaining.
 */
final class ThrottleRequests implements ParameterizedMiddleware
{
    private int $maxAttempts = 60;

    private int $decaySeconds = 60;

    private ?string $bucket = null;

    public function __construct(
        private readonly RateLimiter $limiter,
        private readonly ClientIp $clientIp,
        private readonly int $ipv6Prefix = 64,
    ) {}

    public static function limit(int $maxAttempts, int $perMinutes = 1, ?string $bucket = null): string
    {
        return self::class . ':' . $maxAttempts . ',' . $perMinutes . ($bucket !== null ? ',' . $bucket : '');
    }

    public function withArguments(string ...$arguments): static
    {
        $max = $arguments[0] ?? '';
        $minutes = $arguments[1] ?? '1';

        if (!ctype_digit($max) || (int) $max < 1 || !ctype_digit($minutes) || (int) $minutes < 1) {
            throw new \InvalidArgumentException('ThrottleRequests expects "maxAttempts,minutes[,bucket]" with positive integers.');
        }

        $clone = clone $this;
        $clone->maxAttempts = (int) $max;
        $clone->decaySeconds = (int) $minutes * 60;
        $clone->bucket = isset($arguments[2]) && $arguments[2] !== '' ? $arguments[2] : null;

        return $clone;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $key = 'throttle:' . ($this->bucket ?? self::routeKey($request)) . '|' . ClientIp::forRateLimit($this->clientIp->of($request), $this->ipv6Prefix);

        if (!$this->limiter->attempt($key, $this->maxAttempts, $this->decaySeconds)) {
            throw new HttpException(429, 'Too many requests. Please try again later.', [
                'Retry-After' => (string) max(1, $this->limiter->availableIn($key)),
            ]);
        }

        $response = $handler->handle($request);

        return $response
            ->withHeader('X-RateLimit-Limit', (string) $this->maxAttempts)
            ->withHeader('X-RateLimit-Remaining', (string) $this->limiter->remaining($key, $this->maxAttempts));
    }

    /**
     * The matched route, independent of how the URL was spelled; the normalized path
     * when the middleware runs outside routing.
     */
    private static function routeKey(ServerRequestInterface $request): string
    {
        $route = $request->getAttribute(HttpKernel::ROUTE_ATTRIBUTE);

        if ($route instanceof Route) {
            return 'route:' . ($route->getName() ?? implode('|', $route->methods()) . ' ' . $route->path());
        }

        $path = $request->getAttribute(HttpKernel::PATH_ATTRIBUTE);

        return 'path:' . Route::normalizeEncoding(is_string($path) ? $path : $request->getUri()->getPath());
    }
}
