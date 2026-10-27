<?php

declare(strict_types=1);

namespace VeyroPHP\Session;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use VeyroPHP\Http\Exception\HttpException;
use VeyroPHP\Http\HttpKernel;

/**
 * Rejects state-changing requests (POST, PUT, PATCH, DELETE) whose CSRF token
 * does not match the session's token, with 403 Forbidden.
 *
 * The token is read from the "_token" form field or the "X-CSRF-Token" header
 * and compared with hash_equals(). It is also exposed to handlers as the
 * request attribute "csrf_token" (a CsrfToken, created on first read) for use in forms:
 *
 *     <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
 *
 * Paths in $except (route paths; a trailing "*" matches a prefix) are skipped,
 * e.g. ['/api/*', '/webhooks/payment']. Must run after StartSession.
 */
final class VerifyCsrfToken implements MiddlewareInterface
{
    public const ATTRIBUTE = 'csrf_token';

    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS', 'TRACE'];

    /**
     * @param list<string> $except
     */
    public function __construct(private readonly array $except = []) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $session = $request->getAttribute(Session::class);

        if (!$session instanceof Session) {
            throw SessionException::csrfWithoutSession();
        }

        if (!in_array(strtoupper($request->getMethod()), self::SAFE_METHODS, true) && !$this->isExcepted($request)) {
            $expected = $session->existingToken();
            $given = $this->givenToken($request);

            if ($expected === null || $given === null || !hash_equals($expected, $given)) {
                // 403, not Laravel's non-standard 419: Apache with mod_php turns unknown status codes into 500.
                throw new HttpException(403, 'The form expired or the CSRF token is invalid. Reload the page and try again.');
            }
        }

        // Lazy: a token (and with it a stored session) is only created when a page reads it.
        return $handler->handle($request->withAttribute(self::ATTRIBUTE, new CsrfToken($session)));
    }

    private function givenToken(ServerRequestInterface $request): ?string
    {
        $body = $request->getParsedBody();
        $fromBody = is_array($body) ? ($body['_token'] ?? null) : null;

        if (is_string($fromBody) && $fromBody !== '') {
            return $fromBody;
        }

        $header = $request->getHeaderLine('X-CSRF-Token');

        return $header !== '' ? $header : null;
    }

    private function isExcepted(ServerRequestInterface $request): bool
    {
        $path = $request->getAttribute(HttpKernel::PATH_ATTRIBUTE);
        $path = is_string($path) ? $path : $request->getUri()->getPath();

        foreach ($this->except as $pattern) {
            if (str_ends_with($pattern, '*') ? str_starts_with($path, substr($pattern, 0, -1)) : $path === $pattern) {
                return true;
            }
        }

        return false;
    }
}
