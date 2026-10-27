<?php

declare(strict_types=1);

namespace VeyroPHP\Routing;

use Closure;
use Psr\Http\Server\MiddlewareInterface;
use VeyroPHP\Routing\Exception\RoutingException;

/**
 * A single route: HTTP methods, a path pattern, a handler, and optional name,
 * constraints and middleware.
 *
 * Path syntax: "/users/{id}" or "/users/{id:\d+}". A parameter matches one
 * path segment ("[^/]+") unless a constraint is given inline or with where().
 * Optional parameters are not supported; define a second route instead.
 *
 * Constraints are checked against the percent-encoded value (after
 * normalization), both when matching and when generating paths. An encoded
 * slash ("%2F") therefore stays inside one segment.
 *
 * Routes can be changed until the router starts matching; after that they are frozen.
 */
final class Route
{
    public const METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];

    private const PARAMETER = '/\{([A-Za-z_][A-Za-z0-9_]*)(?::((?:[^{}]|\{[^{}]*\})+))?\}/';

    private const DEFAULT_CONSTRAINT = '[^/]+';

    /** @var list<string> */
    private readonly array $methods;

    /** @var list<string|array{string}> static text (already URL-encoded) or [parameter name] */
    private readonly array $parts;

    /** @var array<string, string> parameter name => constraint */
    private array $constraints = [];

    private ?string $name = null;

    /** @var list<MiddlewareInterface|string> */
    private array $middleware;

    private ?string $regex = null;

    private bool $frozen = false;

    /**
     * @param list<string> $methods
     * @param Closure|array{class-string, string}|class-string $handler
     * @param list<MiddlewareInterface|string> $middleware middleware inherited from groups
     */
    public function __construct(
        array $methods,
        private readonly string $path,
        private readonly Closure|array|string $handler,
        private readonly string $namePrefix = '',
        array $middleware = [],
    ) {
        if ($methods === []) {
            throw RoutingException::invalidPath($path, 'at least one HTTP method is required');
        }

        $normalized = [];
        foreach ($methods as $method) {
            $upper = strtoupper($method);
            if (!in_array($upper, self::METHODS, true)) {
                throw RoutingException::invalidMethod($method);
            }
            $normalized[] = $upper;
        }

        $this->methods = array_values(array_unique($normalized));
        $this->parts = self::parse($path, $this->constraints);
        $this->middleware = $middleware;
    }

    public function name(string $name): self
    {
        $this->assertNotFrozen();

        if ($name === '') {
            throw RoutingException::invalidPath($this->path, 'route name must not be empty');
        }

        $this->name = $this->namePrefix . $name;

        return $this;
    }

    /**
     * Sets a regular expression the parameter must match, without delimiters or anchors.
     */
    public function where(string $parameter, string $pattern): self
    {
        $this->assertNotFrozen();

        if (!array_key_exists($parameter, $this->constraints)) {
            throw RoutingException::unknownParameter($this->path, $parameter);
        }

        self::assertValidConstraint($this->path, $parameter, $pattern);
        $this->constraints[$parameter] = $pattern;
        $this->regex = null;

        return $this;
    }

    /**
     * @param MiddlewareInterface|string ...$middleware runs after group middleware, in the given order
     */
    public function middleware(MiddlewareInterface|string ...$middleware): self
    {
        $this->assertNotFrozen();

        foreach ($middleware as $item) {
            $this->middleware[] = $item;
        }

        return $this;
    }

    /**
     * @return list<string>
     */
    public function methods(): array
    {
        return $this->methods;
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * @return Closure|array{class-string, string}|class-string
     */
    public function handler(): Closure|array|string
    {
        return $this->handler;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    /**
     * @return list<MiddlewareInterface|string>
     */
    public function getMiddleware(): array
    {
        return $this->middleware;
    }

    /**
     * @return list<string>
     */
    public function parameterNames(): array
    {
        return array_keys($this->constraints);
    }

    public function isStatic(): bool
    {
        return $this->constraints === [];
    }

    /**
     * The normalized, URL-encoded path for a static route, used as a lookup key.
     */
    public function staticPath(): string
    {
        $path = '';
        foreach ($this->parts as $part) {
            $path .= is_string($part) ? $part : '';
        }

        return $path;
    }

    public function allowsMethod(string $method): bool
    {
        return in_array($method, $this->methods, true) || ($method === 'HEAD' && in_array('GET', $this->methods, true));
    }

    /**
     * Matches a normalized request path.
     *
     * @return array<string, string>|null decoded parameter values, or null when the path does not match
     */
    public function match(string $path): ?array
    {
        if ($this->isStatic()) {
            return $path === $this->staticPath() ? [] : null;
        }

        if (preg_match($this->regex(), $path, $matches) !== 1) {
            return null;
        }

        $parameters = [];
        foreach ($this->parameterNames() as $name) {
            $parameters[$name] = rawurldecode($matches[$name]);
        }

        return $parameters;
    }

    /**
     * Builds the path for this route. Values are URL-encoded; extra parameters become the query string.
     *
     * @param array<string, string|int|float|bool|\Stringable> $parameters
     */
    public function generate(array $parameters = []): string
    {
        $label = $this->name ?? $this->path;
        $path = '';

        foreach ($this->parts as $part) {
            if (is_string($part)) {
                $path .= $part;
                continue;
            }

            $name = $part[0];

            if (!array_key_exists($name, $parameters)) {
                throw RoutingException::missingParameter($label, $name);
            }

            // Constraints apply to the encoded value, exactly as during matching.
            $encoded = self::normalizeEncoding(rawurlencode(self::stringify($parameters[$name])));

            if (preg_match('#^(?:' . self::escapeDelimiter($this->constraints[$name]) . ')$#D', $encoded) !== 1) {
                throw RoutingException::parameterMismatch($label, $name);
            }

            $path .= $encoded;
            unset($parameters[$name]);
        }

        if ($parameters !== []) {
            $path .= '?' . http_build_query(array_map(self::stringify(...), $parameters), '', '&', PHP_QUERY_RFC3986);
        }

        return $path;
    }

    /**
     * The signature used to detect duplicate definitions. Parameter names are
     * ignored, so "/u/{id}" and "/u/{name}" count as the same pattern.
     */
    public function patternKey(): string
    {
        return $this->isStatic()
            ? $this->staticPath()
            : (preg_replace('/\(\?P<[A-Za-z0-9_]+>/', '(', $this->regex()) ?? $this->regex());
    }

    /**
     * @internal called by the router when matching starts
     */
    public function freeze(): void
    {
        $this->regex();
        $this->frozen = true;
    }

    private function regex(): string
    {
        if ($this->regex !== null) {
            return $this->regex;
        }

        $regex = '';
        foreach ($this->parts as $part) {
            $regex .= is_string($part)
                ? preg_quote($part, '#')
                : sprintf('(?P<%s>%s)', $part[0], self::escapeDelimiter($this->constraints[$part[0]]));
        }

        return $this->regex = '#^' . $regex . '$#D';
    }

    private function assertNotFrozen(): void
    {
        if ($this->frozen) {
            throw RoutingException::frozen();
        }
    }

    /**
     * @param array<string, string> $constraints filled with the parameters found
     *
     * @return list<string|array{string}>
     */
    private static function parse(string $path, array &$constraints): array
    {
        if ($path === '' || $path[0] !== '/') {
            throw RoutingException::invalidPath($path, 'must start with "/"');
        }

        if (str_contains($path, '//')) {
            throw RoutingException::invalidPath($path, 'must not contain empty segments');
        }

        if (preg_match_all(self::PARAMETER, $path, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === false) {
            throw RoutingException::invalidPath($path, 'could not be parsed');
        }

        $parts = [];
        $offset = 0;

        foreach ($matches as $match) {
            [$whole, $start] = $match[0];
            $name = $match[1][0];
            $constraint = isset($match[2]) && $match[2][1] !== -1 ? $match[2][0] : self::DEFAULT_CONSTRAINT;

            if ($start > $offset) {
                $parts[] = self::encodeStatic($path, substr($path, $offset, $start - $offset));
            }

            if (array_key_exists($name, $constraints)) {
                throw RoutingException::invalidPath($path, sprintf('parameter "%s" is used twice', $name));
            }

            self::assertValidConstraint($path, $name, $constraint);
            $constraints[$name] = $constraint;
            $parts[] = [$name];
            $offset = $start + strlen($whole);
        }

        if ($offset < strlen($path)) {
            $parts[] = self::encodeStatic($path, substr($path, $offset));
        }

        return $parts;
    }

    private static function encodeStatic(string $path, string $text): string
    {
        if (str_contains($text, '{') || str_contains($text, '}')) {
            throw RoutingException::invalidPath($path, 'unbalanced "{" or "}", or an invalid parameter name');
        }

        return implode('/', array_map(
            static fn(string $segment): string => self::normalizeEncoding(rawurlencode(rawurldecode($segment))),
            explode('/', $text),
        ));
    }

    private static function assertValidConstraint(string $path, string $parameter, string $pattern): void
    {
        if ($pattern === '' || @preg_match('#^(?:' . self::escapeDelimiter($pattern) . ')$#D', '') === false) {
            throw RoutingException::invalidConstraint($path, $parameter, $pattern);
        }
    }

    private static function escapeDelimiter(string $pattern): string
    {
        return str_replace('#', '\#', $pattern);
    }

    private static function stringify(string|int|float|bool|\Stringable $value): string
    {
        return match (true) {
            $value === true => '1',
            $value === false => '0',
            default => (string) $value,
        };
    }

    /**
     * Normalizes percent-encoding as RFC 3986 section 6.2.2 describes: unreserved
     * characters are decoded and other escapes use uppercase hex digits.
     */
    public static function normalizeEncoding(string $path): string
    {
        return preg_replace_callback(
            '/%([0-9A-Fa-f]{2})/',
            static function (array $match): string {
                $char = chr((int) hexdec($match[1]));

                return preg_match('/^[A-Za-z0-9\-._~]$/D', $char) === 1 ? $char : '%' . strtoupper($match[1]);
            },
            $path,
        ) ?? $path;
    }
}
