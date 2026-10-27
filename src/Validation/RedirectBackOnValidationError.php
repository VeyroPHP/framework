<?php

declare(strict_types=1);

namespace VeyroPHP\Validation;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use VeyroPHP\Container\Container;
use VeyroPHP\Http\ContentNegotiation;
use VeyroPHP\Http\ResponseFactory;
use VeyroPHP\Session\Session;
use VeyroPHP\Support\SensitiveKey;
use VeyroPHP\View\View;

/**
 * Opt-in: sends an HTML form that failed validation back to the page it came from,
 * with its errors and input for the next request. Templates get:
 *
 *     $old('email')                 the submitted value (or a default)
 *     $errors->first('email')       an ErrorBag of the messages
 *
 * Add it after StartSession: `RedirectBackOnValidationError::class`.
 *
 * - Only form submissions (not GET/HEAD/OPTIONS) from clients that do not prefer
 *   JSON are redirected; JSON clients keep the 422 response.
 * - The target is the Referer only when it has this request's scheme, host and
 *   port; otherwise the fallback path. Never an external URL.
 * - Never flashed: uploaded files and objects, fields with a secret-looking name
 *   (SensitiveKey: password, secret, token, api-key, card-number, otp, cvv, ... in any
 *   spelling), plus the names given here.
 * - With this middleware, "old" and "errors" are shared view names: templates' own
 *   data must not use them.
 */
final class RedirectBackOnValidationError implements MiddlewareInterface
{
    private const ERRORS = '_validation_errors';

    private const OLD_INPUT = '_old_input';

    /**
     * @param list<string> $dontFlash more field names never to flash (exact, case-insensitive)
     */
    public function __construct(
        private readonly ResponseFactory $responses,
        private readonly ContainerInterface $container,
        private readonly string $fallback = '/',
        private readonly array $dontFlash = [],
    ) {
        if (!ResponseFactory::isLocalPath($fallback)) {
            throw new \InvalidArgumentException('The fallback must be a path on this site, such as "/".');
        }
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $session = $request->getAttribute(Session::class);

        if (!$session instanceof Session) {
            throw new \LogicException('RedirectBackOnValidationError needs StartSession before it in the middleware list.');
        }

        $this->shareWithViews($session);

        try {
            return $handler->handle($request);
        } catch (ValidationException $e) {
            if (in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true) || ContentNegotiation::prefersJson($request)) {
                throw $e;
            }

            $body = $request->getParsedBody();
            $session->flash(self::ERRORS, $e->errors());
            $session->flash(self::OLD_INPUT, is_array($body) ? $this->safe($body) : []);

            return $this->responses->redirect($this->target($request), 303);
        }
    }

    private function shareWithViews(Session $session): void
    {
        // bound(), not has(): has() is true for any instantiable class, and building a View
        // without its directory would fail in applications that do not register views.
        $registered = $this->container instanceof Container ? $this->container->bound(View::class) : $this->container->has(View::class);

        if (!$registered) {
            return;
        }

        $view = $this->container->get(View::class);

        if (!$view instanceof View) {
            return;
        }

        $old = $session->get(self::OLD_INPUT, []);
        $old = is_array($old) ? $old : [];
        $errors = $session->get(self::ERRORS, []);
        $bag = [];

        foreach (is_array($errors) ? $errors : [] as $field => $messages) {
            if (is_string($field) && is_array($messages)) {
                $bag[$field] = array_values(array_filter($messages, is_string(...)));
            }
        }

        $view->share('old', static function (string $key, mixed $default = null) use ($old): mixed {
            $value = $old;

            foreach (explode('.', $key) as $segment) {
                if (!is_array($value) || !array_key_exists($segment, $value)) {
                    return $default;
                }
                $value = $value[$segment];
            }

            return $value;
        });
        $view->share('errors', new ErrorBag($bag));
    }

    /**
     * Scalars only, without sensitive fields, at any depth.
     *
     * @param array<array-key, mixed> $input
     *
     * @return array<array-key, mixed>
     */
    private function safe(array $input): array
    {
        $safe = [];

        foreach ($input as $key => $value) {
            if ($this->sensitive((string) $key)) {
                continue;
            }

            if (is_array($value)) {
                $safe[$key] = $this->safe($value);
            } elseif (is_scalar($value) || $value === null) {
                $safe[$key] = $value;
            }
            // Uploaded files and other objects are dropped.
        }

        return $safe;
    }

    private function sensitive(string $key): bool
    {
        return SensitiveKey::matches($key, false) || in_array(strtolower($key), array_map(strtolower(...), $this->dontFlash), true);
    }

    /**
     * The Referer's path and query when it is this site (same scheme, host and port).
     */
    private function target(ServerRequestInterface $request): string
    {
        $referer = $request->getHeaderLine('Referer');
        $parts = $referer === '' ? false : parse_url($referer);
        $uri = $request->getUri();

        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return $this->fallback;
        }

        $defaultPort = static fn(string $scheme): ?int => match (strtolower($scheme)) {
            'http' => 80,
            'https' => 443,
            default => null,
        };
        $sameOrigin = strtolower($parts['scheme']) === strtolower($uri->getScheme())
            && strtolower($parts['host']) === strtolower($uri->getHost())
            && ($parts['port'] ?? $defaultPort($parts['scheme'])) === ($uri->getPort() ?? $defaultPort($uri->getScheme()));

        if (!$sameOrigin) {
            return $this->fallback;
        }

        $path = ($parts['path'] ?? '') === '' ? '/' : $parts['path'];
        $location = $path . (isset($parts['query']) ? '?' . $parts['query'] : '');

        return ResponseFactory::isLocalPath($location) && preg_match('/[\x00-\x1F\x7F]/', $location) !== 1 ? $location : $this->fallback;
    }
}
