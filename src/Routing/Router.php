<?php

declare(strict_types=1);

namespace VeyroPHP\Routing;

use Closure;
use Psr\Http\Server\MiddlewareInterface;
use VeyroPHP\Routing\Exception\RoutingException;

/**
 * Collects routes and matches requests against them.
 *
 * Matching rules:
 *
 * - Static routes are checked before routes with parameters, regardless of
 *   registration order, so "/users/me" wins over "/users/{id}".
 * - Routes with parameters are checked in registration order.
 * - HEAD requests are served by GET routes.
 * - Paths are compared after percent-encoding normalization. Trailing slashes
 *   are significant: "/users/" does not match "/users".
 * - When the path matches but the method does not, the result is
 *   "method not allowed" with the list of allowed methods.
 *
 * Definitions are validated when matching starts (or when route() or routes()
 * is called): duplicate method + path combinations and duplicate names throw.
 */
final class Router
{
    /** @var list<Route> */
    private array $routes = [];

    /** @var list<array{prefix: string, middleware: list<MiddlewareInterface|string>, name: string}> */
    private array $groups = [];

    private bool $compiled = false;

    /** @var array<string, list<Route>> */
    private array $static = [];

    /** @var list<Route> */
    private array $dynamic = [];

    /** @var array<string, Route> */
    private array $named = [];

    /**
     * @param Closure|array{class-string, string}|class-string $handler
     */
    public function get(string $path, Closure|array|string $handler): Route
    {
        return $this->map(['GET'], $path, $handler);
    }

    /**
     * @param Closure|array{class-string, string}|class-string $handler
     */
    public function post(string $path, Closure|array|string $handler): Route
    {
        return $this->map(['POST'], $path, $handler);
    }

    /**
     * @param Closure|array{class-string, string}|class-string $handler
     */
    public function put(string $path, Closure|array|string $handler): Route
    {
        return $this->map(['PUT'], $path, $handler);
    }

    /**
     * @param Closure|array{class-string, string}|class-string $handler
     */
    public function patch(string $path, Closure|array|string $handler): Route
    {
        return $this->map(['PATCH'], $path, $handler);
    }

    /**
     * @param Closure|array{class-string, string}|class-string $handler
     */
    public function delete(string $path, Closure|array|string $handler): Route
    {
        return $this->map(['DELETE'], $path, $handler);
    }

    /**
     * @param Closure|array{class-string, string}|class-string $handler
     */
    public function options(string $path, Closure|array|string $handler): Route
    {
        return $this->map(['OPTIONS'], $path, $handler);
    }

    /**
     * @param list<string> $methods
     * @param Closure|array{class-string, string}|class-string $handler a closure, [Controller::class, 'method'] or an invokable class
     */
    public function map(array $methods, string $path, Closure|array|string $handler): Route
    {
        if ($this->compiled) {
            throw RoutingException::frozen();
        }

        $prefix = '';
        $middleware = [];
        $namePrefix = '';

        foreach ($this->groups as $group) {
            $prefix .= $group['prefix'];
            $middleware = [...$middleware, ...$group['middleware']];
            $namePrefix .= $group['name'];
        }

        $fullPath = $prefix === '' ? $path : ($path === '/' ? $prefix : $prefix . $path);
        self::assertValidHandler($fullPath, $handler);

        $route = new Route($methods, $fullPath, $handler, $namePrefix, $middleware);
        $this->routes[] = $route;

        return $route;
    }

    /**
     * Defines routes that share a path prefix, middleware and name prefix.
     *
     * @param Closure(Router): void $routes
     * @param list<MiddlewareInterface|string> $middleware
     */
    public function group(string $prefix, Closure $routes, array $middleware = [], string $namePrefix = ''): void
    {
        if ($prefix !== '' && ($prefix[0] !== '/' || str_ends_with($prefix, '/'))) {
            throw RoutingException::invalidPath($prefix, 'group prefix must start with "/" and must not end with "/"');
        }

        $this->groups[] = ['prefix' => $prefix, 'middleware' => $middleware, 'name' => $namePrefix];

        try {
            $routes($this);
        } finally {
            array_pop($this->groups);
        }
    }

    /**
     * Matches a request method and path.
     *
     * @param string $path the request path, still percent-encoded
     */
    public function match(string $method, string $path): RouteMatch
    {
        $this->compile();

        $method = strtoupper($method);
        $path = Route::normalizeEncoding($path === '' ? '/' : $path);
        $allowed = [];

        foreach ($this->static[$path] ?? [] as $route) {
            if ($route->allowsMethod($method)) {
                return RouteMatch::found($route, []);
            }
            $allowed = [...$allowed, ...$route->methods()];
        }

        foreach ($this->dynamic as $route) {
            $parameters = $route->match($path);

            if ($parameters === null) {
                continue;
            }

            if ($route->allowsMethod($method)) {
                return RouteMatch::found($route, $parameters);
            }

            $allowed = [...$allowed, ...$route->methods()];
        }

        if ($allowed === []) {
            return RouteMatch::notFound();
        }

        if (in_array('GET', $allowed, true)) {
            $allowed[] = 'HEAD';
        }

        $allowed = array_values(array_unique($allowed));
        sort($allowed);

        return RouteMatch::methodNotAllowed($allowed);
    }

    /**
     * Generates the path for a named route, without the application's base path.
     *
     * @param array<string, string|int|float|bool|\Stringable> $parameters extra keys become the query string
     */
    public function path(string $name, array $parameters = []): string
    {
        return $this->route($name)->generate($parameters);
    }

    public function route(string $name): Route
    {
        $this->compile();

        return $this->named[$name] ?? throw RoutingException::unknownName($name);
    }

    /**
     * @return list<Route> in registration order
     */
    public function routes(): array
    {
        $this->compile();

        return $this->routes;
    }

    private function compile(): void
    {
        if ($this->compiled) {
            return;
        }

        $seen = [];

        foreach ($this->routes as $route) {
            $route->freeze();

            foreach ($route->methods() as $method) {
                $key = $method . ' ' . $route->patternKey();

                if (isset($seen[$key])) {
                    throw RoutingException::duplicateRoute($method, $route->path());
                }

                $seen[$key] = true;
            }

            $name = $route->getName();

            if ($name !== null) {
                if (isset($this->named[$name])) {
                    throw RoutingException::duplicateName($name);
                }

                $this->named[$name] = $route;
            }

            if ($route->isStatic()) {
                $this->static[$route->staticPath()][] = $route;
            } else {
                $this->dynamic[] = $route;
            }
        }

        $this->compiled = true;
    }

    /**
     * @param Closure|array<mixed>|string $handler
     */
    private static function assertValidHandler(string $path, Closure|array|string $handler): void
    {
        if ($handler instanceof Closure) {
            return;
        }

        if (is_string($handler)) {
            if (!class_exists($handler)) {
                throw RoutingException::invalidHandler($path, sprintf('class "%s" does not exist', $handler));
            }

            if (!method_exists($handler, '__invoke')) {
                throw RoutingException::invalidHandler($path, sprintf('class "%s" is not invokable', $handler));
            }

            return;
        }

        if (count($handler) !== 2 || !is_string($handler[0] ?? null) || !is_string($handler[1] ?? null)) {
            throw RoutingException::invalidHandler($path, 'expected [ClassName::class, \'method\']');
        }

        [$class, $method] = $handler;

        if (!class_exists($class)) {
            throw RoutingException::invalidHandler($path, sprintf('class "%s" does not exist', $class));
        }

        if (!method_exists($class, $method) || !(new \ReflectionMethod($class, $method))->isPublic()) {
            throw RoutingException::invalidHandler($path, sprintf('"%s::%s()" is not a public method', $class, $method));
        }
    }
}
