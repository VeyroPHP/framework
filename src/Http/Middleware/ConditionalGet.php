<?php

declare(strict_types=1);

namespace VeyroPHP\Http\Middleware;

use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use VeyroPHP\Support\Base64Url;

/**
 * Adds an ETag to successful GET and HEAD responses and answers 304 Not Modified
 * when the client already has that version (If-None-Match), so unchanged pages
 * are not sent again.
 *
 *     ->middleware(ConditionalGet::class)               // ETag only
 *     ->middleware(ConditionalGet::cache(300))          // + Cache-Control: private, max-age=300
 *     ->middleware(ConditionalGet::cache(600, true))    // + Cache-Control: public, max-age=600
 *
 * - Only 200 responses with a body of known size up to 1 MB get an ETag; streamed
 *   files and errors are left alone. An ETag set by the handler is kept.
 * - Cache-Control is added only when asked for and not already set. A response
 *   that already sets a cookie here gets "private, no-cache" instead. The session
 *   cookie is added later, by the global StartSession, which this middleware cannot
 *   see; StartSession turns "public" into "private, no-cache" itself, so shared
 *   caches never store a personal page.
 * - The page is still built; the saving is in bandwidth and client rendering.
 */
final class ConditionalGet implements ParameterizedMiddleware
{
    private const MAX_BODY_BYTES = 1_048_576;

    private ?int $maxAge = null;

    private bool $public = false;

    public static function cache(int $maxAgeSeconds, bool $public = false): string
    {
        if ($maxAgeSeconds < 0) {
            throw new \InvalidArgumentException('max-age must not be negative.');
        }

        return self::class . ':' . $maxAgeSeconds . ($public ? ',public' : '');
    }

    public function withArguments(string ...$arguments): static
    {
        $clone = clone $this;
        $maxAge = $arguments[0] ?? '';

        if ($maxAge !== '' && !ctype_digit($maxAge)) {
            throw new \InvalidArgumentException('ConditionalGet expects "maxAgeSeconds[,public]".');
        }

        $clone->maxAge = $maxAge === '' ? null : (int) $maxAge;
        $clone->public = ($arguments[1] ?? '') === 'public';

        return $clone;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        if (!in_array($request->getMethod(), ['GET', 'HEAD'], true) || $response->getStatusCode() !== 200) {
            return $response;
        }

        if ($this->maxAge !== null && !$response->hasHeader('Cache-Control')) {
            $response = $response->withHeader('Cache-Control', $response->hasHeader('Set-Cookie')
                ? 'private, no-cache'
                : ($this->public ? 'public' : 'private') . ', max-age=' . $this->maxAge);
        }

        if (!$response->hasHeader('ETag')) {
            $size = $response->getBody()->getSize();

            if ($size === null || $size > self::MAX_BODY_BYTES) {
                return $response;
            }

            $hash = substr(hash('sha256', (string) $response->getBody(), true), 0, 16);
            $response = $response->withHeader('ETag', '"' . Base64Url::encode($hash) . '"');
        }

        if (!self::matches($request->getHeaderLine('If-None-Match'), $response->getHeaderLine('ETag'))) {
            return $response;
        }

        return $response->withStatus(304)
            ->withoutHeader('Content-Length')
            ->withoutHeader('Content-Type')
            ->withBody(Stream::create(''));
    }

    /**
     * Weak comparison, as RFC 9110 requires for If-None-Match.
     */
    private static function matches(string $ifNoneMatch, string $etag): bool
    {
        if ($ifNoneMatch === '' || $etag === '') {
            return false;
        }

        $strip = static fn(string $tag): string => str_starts_with($tag, 'W/') ? substr($tag, 2) : $tag;

        foreach (explode(',', $ifNoneMatch) as $candidate) {
            $candidate = trim($candidate);

            if ($candidate === '*' || $strip($candidate) === $strip($etag)) {
                return true;
            }
        }

        return false;
    }
}
