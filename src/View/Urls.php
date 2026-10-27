<?php

declare(strict_types=1);

namespace VeyroPHP\View;

use Psr\Http\Message\ServerRequestInterface;
use VeyroPHP\Http\HttpKernel;
use VeyroPHP\Routing\Router;

/**
 * URL helpers for templates, shared as $urls by ShareUrlHelpers. Every
 * result includes the application's base path ("/myapp" under XAMPP, "" at a
 * document root) and is a path, not an absolute URL.
 *
 *     <a href="<?= $e($urls->route('posts.show', ['post' => $id])) ?>">
 *     <a href="<?= $e($urls->route('posts.show', $id)) ?>">     positional, like Laravel's route()
 *     <a href="<?= $e($urls->to('/login')) ?>">                       a path handled by the router
 *     <link rel="canonical" href="<?= $e($urls->current()) ?>">
 *     <a class="<?= $urls->is('admin/*') ? 'active' : '' ?>">         like request()->is()
 *     <?php if ($urls->routeIs('admin.*')): ?>                         like request()->routeIs()
 *     <link rel="canonical" href="<?= $view->url($urls->absolute()) ?>">     absolute, from APP_URL
 *     <link rel="stylesheet" href="<?= $view->url($urls->asset('/css/site.css')) ?>">   versioned
 */
final class Urls
{
    private readonly string $publicBase;

    /**
     * @param string|null $appUrl the configured application URL (app.url); absolute() uses its origin
     * @param string|null $publicPath the public directory; asset() reads file times from it
     * @param string|null $publicBase base path of files in public/ (HttpKernel::PUBLIC_BASE_ATTRIBUTE);
     *                                null uses $base. It differs from $base when URLs go through
     *                                "/index.php", which static files must not (HTTP-3).
     */
    public function __construct(
        private readonly Router $router,
        private readonly string $base,
        private readonly ?ServerRequestInterface $request = null,
        private readonly ?string $appUrl = null,
        private readonly ?string $publicPath = null,
        ?string $publicBase = null,
    ) {
        $this->publicBase = $publicBase ?? $base;
    }

    /**
     * An absolute URL for canonical links, sitemaps and mails: the origin of APP_URL
     * plus $path (default: the current path). The origin is never taken from the
     * request's Host header, which a client can set. Leading slashes are collapsed,
     * so a request for "//evil.example/x" cannot become a protocol-relative URL.
     *
     * @throws \LogicException when app.url is not an http or https URL
     */
    public function absolute(?string $path = null): string
    {
        $parts = $this->appUrl === null ? false : parse_url($this->appUrl);

        if (!is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || ($parts['host'] ?? '') === '') {
            throw new \LogicException('Absolute URLs need the application URL: set APP_URL (config app.url) to an http or https URL, such as https://example.com.');
        }

        $origin = strtolower($parts['scheme']) . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');

        return $origin . '/' . ltrim($path ?? $this->current(), '/');
    }

    /**
     * A path to a file in public/ with a version from its modification time, so
     * browsers can cache it for long and still get a changed file at once:
     * asset('/css/site.css') gives "/css/site.css?v=t3k9d2". Without the file (or
     * without a public directory) the plain path is returned.
     *
     * @throws \InvalidArgumentException for ".." segments, backslashes or NUL bytes
     */
    public function asset(string $path): string
    {
        if (str_contains($path, "\0") || str_contains($path, '\\') || in_array('..', explode('/', $path), true)) {
            throw new \InvalidArgumentException(sprintf('Asset path "%s" must stay inside public/.', str_replace("\0", '\0', $path)));
        }

        $url = $this->publicBase . '/' . ltrim($path, '/');

        if ($this->publicPath === null) {
            return $url;
        }

        $file = rtrim($this->publicPath, '/\\') . '/' . ltrim($path, '/');
        $time = is_file($file) ? filemtime($file) : false;

        return $time === false ? $url : $url . '?v=' . base_convert((string) $time, 10, 36);
    }

    /**
     * Path of a named route. Positional values fill the route's parameters in order.
     */
    public function route(string $name, mixed $parameters = []): string
    {
        if (!is_array($parameters)) {
            $parameters = [$parameters];
        }

        if ($parameters !== [] && array_is_list($parameters)) {
            $named = [];

            foreach ($this->router->route($name)->parameterNames() as $i => $parameter) {
                if (array_key_exists($i, $parameters)) {
                    $named[$parameter] = $parameters[$i];
                }
            }

            $parameters = $named;
        }

        /** @var array<string, string|int|float|\Stringable> $parameters */
        return $this->base . $this->router->path($name, $parameters);
    }

    /**
     * A path inside the application, e.g. to('/login'). It keeps "/index.php" when the
     * request came through it, so links reach the router; use asset() for files in public/.
     */
    public function to(string $path = '/'): string
    {
        return $this->base . '/' . ltrim($path, '/');
    }

    /**
     * The path of the current request (without the query string).
     */
    public function current(): string
    {
        return $this->request === null ? $this->to('/') : $this->request->getUri()->getPath();
    }

    public function base(): string
    {
        return $this->base;
    }

    /**
     * Whether the current path (without the base path) matches a pattern, as
     * Laravel's request()->is('admin/*'): "*" matches anything, slashes at
     * the ends are ignored.
     */
    public function is(string ...$patterns): bool
    {
        $path = trim($this->routePath(), '/');

        foreach ($patterns as $pattern) {
            if (self::wildcard(trim($pattern, '/'), $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the current route's name matches a pattern, as Laravel's
     * request()->routeIs('admin.*').
     */
    public function routeIs(string ...$patterns): bool
    {
        if ($this->request === null) {
            return false;
        }

        $name = $this->router->match($this->request->getMethod(), $this->routePath())->route?->getName();

        if ($name === null) {
            return false;
        }

        foreach ($patterns as $pattern) {
            if (self::wildcard($pattern, $name)) {
                return true;
            }
        }

        return false;
    }

    private function routePath(): string
    {
        $path = $this->request?->getAttribute(HttpKernel::PATH_ATTRIBUTE);

        return is_string($path) ? $path : '/';
    }

    private static function wildcard(string $pattern, string $value): bool
    {
        return preg_match('#^' . str_replace('\*', '.*', preg_quote($pattern, '#')) . '$#uD', $value) === 1;
    }
}
