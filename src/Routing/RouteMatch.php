<?php

declare(strict_types=1);

namespace VeyroPHP\Routing;

/**
 * The result of matching a request against the router.
 */
final class RouteMatch
{
    /**
     * @param array<string, string> $parameters
     * @param list<string> $allowedMethods
     */
    private function __construct(
        public readonly ?Route $route,
        public readonly array $parameters,
        public readonly array $allowedMethods,
    ) {}

    /**
     * @param array<string, string> $parameters
     */
    public static function found(Route $route, array $parameters): self
    {
        return new self($route, $parameters, []);
    }

    public static function notFound(): self
    {
        return new self(null, [], []);
    }

    /**
     * @param list<string> $allowedMethods
     */
    public static function methodNotAllowed(array $allowedMethods): self
    {
        return new self(null, [], $allowedMethods);
    }

    public function isFound(): bool
    {
        return $this->route !== null;
    }

    public function isMethodNotAllowed(): bool
    {
        return $this->route === null && $this->allowedMethods !== [];
    }
}
