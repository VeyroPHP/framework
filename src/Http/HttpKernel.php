<?php

declare(strict_types=1);

namespace VeyroPHP\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;
use VeyroPHP\Container\Container;
use VeyroPHP\Container\Exception\ContainerException;
use VeyroPHP\Http\Exception\MethodNotAllowedHttpException;
use VeyroPHP\Http\Exception\NotFoundHttpException;
use VeyroPHP\Http\Middleware\ParameterizedMiddleware;
use VeyroPHP\Http\Middleware\Pipeline;
use VeyroPHP\Routing\HandlerInvoker;
use VeyroPHP\Routing\Router;

/**
 * Handles an HTTP request: global middleware, routing, route middleware, handler.
 *
 * - Every exception is converted to a response by HttpErrorResponder, so
 *   handle() always returns a response.
 * - Route parameters are added as request attributes, and the matched route
 *   is available as the "veyrophp.route" attribute.
 * - HEAD responses have their body removed.
 * - The response uses the request's protocol version.
 * - Scoped container services are reset after every request.
 *
 * Base path: when the application lives in a subdirectory (for example
 * http://localhost/myapp/public/ under XAMPP), that prefix is removed before
 * routing. By default it is detected from SCRIPT_NAME.
 */
final class HttpKernel implements RequestHandlerInterface
{
    public const ROUTE_ATTRIBUTE = 'veyrophp.route';

    /** Request attribute holding the routing path (base path removed), set before any middleware runs. */
    public const PATH_ATTRIBUTE = 'veyrophp.path';

    /**
     * Request attribute holding the base path of routes, as basePath() returns it:
     * "/app/public/index.php" when the URL goes through the front controller, "/app/public"
     * or "/app" otherwise, "" at the web root. Set before any middleware runs.
     */
    public const BASE_ATTRIBUTE = 'veyrophp.base';

    /**
     * Request attribute holding the base path of files in public/: the route base
     * without a front-controller script ("/app/public"), "" at the web root. Static files
     * must use this one, or the web server hands them to PHP (HTTP-3).
     */
    public const PUBLIC_BASE_ATTRIBUTE = 'veyrophp.public_base';

    /**
     * @param list<MiddlewareInterface|string> $middleware global middleware, outermost first
     * @param string|null $basePath fixed base path such as "/myapp/public"; null detects it from SCRIPT_NAME
     */
    public function __construct(
        private readonly Router $router,
        private readonly Container $container,
        private readonly HandlerInvoker $invoker,
        private readonly HttpErrorResponder $errors,
        private readonly ResponseFactory $responses,
        private readonly array $middleware = [],
        private readonly ?string $basePath = null,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // Resolved once per request; middleware, views and redirects read the attributes.
        $base = $this->basePath($request);
        $request = $request
            ->withAttribute(self::BASE_ATTRIBUTE, $base)
            ->withAttribute(self::PUBLIC_BASE_ATTRIBUTE, self::publicBase($base))
            ->withAttribute(self::PATH_ATTRIBUTE, self::pathBelow($request->getUri()->getPath(), $base));

        try {
            $response = (new Pipeline(
                $this->resolveMiddleware($this->middleware),
                new CallableHandler($this->dispatch(...)),
            ))->handle($request);
        } catch (Throwable $e) {
            $response = $this->errors->respond($e, $request);
        } finally {
            $this->container->resetScope();
        }

        if (strtoupper($request->getMethod()) === 'HEAD') {
            $response = $this->responses->withoutBody($response);
        }

        $response = $this->prefixLocalRedirect($response, $request);

        // Answer in the client's protocol version. An "HTTP/1.1" status line for an
        // HTTP/1.0 request makes Apache keep the connection open until its keep-alive timeout.
        return $response->withProtocolVersion($request->getProtocolVersion());
    }

    /**
     * The base path removed from this request ("" when the app is at the web root),
     * e.g. "/myapp/public". Local redirects get it prepended automatically.
     */
    public function basePath(ServerRequestInterface $request): string
    {
        $path = $request->getUri()->getPath();

        foreach ($this->basePathCandidates($request) as $prefix) {
            if ($prefix !== '' && $prefix !== '/' && ($path === $prefix || str_starts_with($path, $prefix . '/'))) {
                return $prefix;
            }
        }

        return '';
    }

    /**
     * Route paths are relative to the application, so redirect('/login') must become
     * "/myapp/public/login" when the app is installed in a subdirectory.
     */
    private function prefixLocalRedirect(ResponseInterface $response, ServerRequestInterface $request): ResponseInterface
    {
        $location = $response->getHeaderLine('Location');
        $base = self::stringAttribute($request, self::BASE_ATTRIBUTE) ?? $this->basePath($request);

        if ($base === '' || !ResponseFactory::isLocalPath($location)) {
            return $response;
        }

        // A front-controller base ("/app/public/index.php") is not needed for redirects. A
        // location that already has either form of the base keeps it (HTTP-2).
        $directory = self::publicBase($base);

        foreach (array_unique([$base, $directory]) as $prefix) {
            if ($prefix !== '' && ($location === $prefix || str_starts_with($location, $prefix . '/'))) {
                return $response;
            }
        }

        return $directory === '' ? $response : $response->withHeader('Location', $directory . $location);
    }

    /**
     * The request path with the base path removed, as used for routing.
     */
    public function routePath(ServerRequestInterface $request): string
    {
        return self::pathBelow($request->getUri()->getPath(), $this->basePath($request));
    }

    /**
     * The base for files in public/: the route base without a front-controller script.
     */
    private static function publicBase(string $base): string
    {
        return str_ends_with($base, '.php') ? rtrim(str_replace('\\', '/', dirname($base)), '/') : $base;
    }

    /**
     * $path with $base (a prefix basePath() found in it, or "") removed.
     */
    private static function pathBelow(string $path, string $base): string
    {
        if ($path === '' || $path === $base) {
            return '/';
        }

        return $base !== '' && str_starts_with($path, $base . '/') ? substr($path, strlen($base)) : $path;
    }

    private static function stringAttribute(ServerRequestInterface $request, string $name): ?string
    {
        $value = $request->getAttribute($name);

        return is_string($value) ? $value : null;
    }

    private function dispatch(ServerRequestInterface $request): ResponseInterface
    {
        // The path comes from the URI as it is now (middleware may have rebuilt it); the
        // base was resolved once in handle().
        $base = self::stringAttribute($request, self::BASE_ATTRIBUTE) ?? $this->basePath($request);
        $match = $this->router->match($request->getMethod(), self::pathBelow($request->getUri()->getPath(), $base));

        if ($match->isMethodNotAllowed()) {
            throw new MethodNotAllowedHttpException($match->allowedMethods);
        }

        $route = $match->route ?? throw new NotFoundHttpException();

        $request = $request->withAttribute(self::ROUTE_ATTRIBUTE, $route);
        foreach ($match->parameters as $name => $value) {
            $request = $request->withAttribute($name, $value);
        }

        return (new Pipeline(
            $this->resolveMiddleware($route->getMiddleware()),
            new CallableHandler(fn(ServerRequestInterface $r): ResponseInterface => $this->invoker->invoke($route->handler(), $r, $match->parameters)),
        ))->handle($request);
    }

    /**
     * @param list<MiddlewareInterface|string> $middleware
     *
     * @return list<MiddlewareInterface>
     */
    private function resolveMiddleware(array $middleware): array
    {
        $resolved = [];

        foreach ($middleware as $item) {
            if (!is_string($item)) {
                $resolved[] = $item;
                continue;
            }

            // "Class:arg1,arg2" passes arguments to a ParameterizedMiddleware.
            $parts = explode(':', $item, 2);
            $arguments = $parts[1] ?? null;
            $instance = $this->container->get($parts[0]);

            if ($arguments !== null) {
                if (!$instance instanceof ParameterizedMiddleware) {
                    throw ContainerException::wrongType(ParameterizedMiddleware::class, $instance);
                }

                $instance = $instance->withArguments(...explode(',', $arguments));
            }

            if (!$instance instanceof MiddlewareInterface) {
                throw ContainerException::wrongType(MiddlewareInterface::class, $instance);
            }

            $resolved[] = $instance;
        }

        return $resolved;
    }

    /**
     * @return list<string>
     */
    private function basePathCandidates(ServerRequestInterface $request): array
    {
        if ($this->basePath !== null) {
            return [rtrim($this->basePath, '/')];
        }

        $script = $request->getServerParams()['SCRIPT_NAME'] ?? null;

        if (!is_string($script) || $script === '' || $script[0] !== '/') {
            return [];
        }

        $directory = rtrim(str_replace('\\', '/', dirname($script)), '/');
        $candidates = [$script, $directory];

        // A rewrite in the project root (".../app/.htaccess" → public/) gives clean
        // URLs like "/app/users" while the script is "/app/public/index.php".
        if (str_ends_with($directory, '/public')) {
            $candidates[] = substr($directory, 0, -strlen('/public'));
        }

        // Tried in order: "/app/public/index.php/users", "/app/public/users", "/app/users".
        return $candidates;
    }
}
