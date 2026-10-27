<?php

declare(strict_types=1);

namespace VeyroPHP\Security;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;
use VeyroPHP\Http\HttpErrorResponder;

/**
 * Adds defensive response headers. Headers a handler already set are kept.
 *
 * Defaults:
 * - X-Content-Type-Options: nosniff
 * - X-Frame-Options: SAMEORIGIN, and CSP frame-ancestors 'self' (clickjacking)
 * - Referrer-Policy: strict-origin-when-cross-origin
 * - Permissions-Policy: camera, microphone and geolocation disabled
 * - Cross-Origin-Opener-Policy: same-origin
 * - Content-Security-Policy: same-origin resources; inline styles allowed
 *   (the starter views use them), inline scripts not allowed
 * - Strict-Transport-Security: only on HTTPS requests and only when $hstsSeconds > 0
 *
 * Pass null for a header to disable it, or a string to replace the default.
 *
 * With an HttpErrorResponder, exceptions thrown further down (a CSRF 403, a 500)
 * are turned into responses here so error pages get the headers too. Register
 * it as one of the outermost global middleware.
 */
final class SecurityHeaders implements MiddlewareInterface
{
    public const DEFAULT_CSP = "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; "
        . "font-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'";

    /** @var array<string, string|null> */
    private readonly array $headers;

    /**
     * @param array<string, string|null> $overrides header name => value, or null to disable
     */
    public function __construct(
        array $overrides = [],
        private readonly int $hstsSeconds = 0,
        private readonly ?HttpErrorResponder $errors = null,
    ) {
        $this->headers = $overrides + [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Content-Security-Policy' => self::DEFAULT_CSP,
        ];

        foreach ($this->headers as $name => $value) {
            if ($value !== null && preg_match('/[\r\n]/', $value) === 1) {
                throw new \InvalidArgumentException(sprintf('Header %s contains a line break.', $name));
            }
        }
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            $response = $handler->handle($request);
        } catch (Throwable $e) {
            if ($this->errors === null) {
                throw $e;
            }
            $response = $this->errors->respond($e, $request);
        }

        foreach ($this->headers as $name => $value) {
            if ($value !== null && !$response->hasHeader($name)) {
                $response = $response->withHeader($name, $value);
            }
        }

        if ($this->hstsSeconds > 0 && $request->getUri()->getScheme() === 'https' && !$response->hasHeader('Strict-Transport-Security')) {
            $response = $response->withHeader('Strict-Transport-Security', 'max-age=' . $this->hstsSeconds . '; includeSubDomains');
        }

        return $response;
    }
}
