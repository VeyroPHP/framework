<?php

declare(strict_types=1);

namespace VeyroPHP\Routing;

use Closure;
use JsonSerializable;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use VeyroPHP\Container\Container;
use VeyroPHP\Http\Exception\NotFoundHttpException;
use VeyroPHP\Http\FromRequest;
use VeyroPHP\Http\ResponseFactory;
use VeyroPHP\Routing\Exception\RoutingException;

/**
 * Calls a route handler with resolved arguments and converts its return value to a response.
 *
 * Arguments are resolved per parameter, in this order:
 *
 * 1. A route parameter with the same name. Untyped, string and mixed
 *    parameters receive the string. int and float parameters receive a
 *    converted value; a value that is not a valid number gives a 404.
 *    Class-typed parameters go through registered RouteParameterResolvers
 *    (for example model binding).
 * 2. A parameter typed ServerRequestInterface receives the current request; a class
 *    implementing FromRequest (such as a FormRequest) is built from it.
 * 3. Another class or interface type is resolved from the container.
 * 4. Otherwise the default value is used, or a RoutingException is thrown.
 *
 * Return values: ResponseInterface is returned as is, a string becomes an
 * HTML response, an array or JsonSerializable becomes JSON, and null becomes
 * 204 No Content. Anything else throws a RoutingException.
 *
 * @internal Framework implementation detail, not covered by the backward-compatibility promise.
 */
final class HandlerInvoker
{
    /** @var list<RouteParameterResolver> */
    private array $resolvers = [];

    public function __construct(
        private readonly Container $container,
        private readonly ResponseFactory $responses,
    ) {}

    /**
     * Lets route parameters bind to class-typed handler arguments (e.g. models).
     */
    public function addParameterResolver(RouteParameterResolver $resolver): void
    {
        $this->resolvers[] = $resolver;
    }

    /**
     * @param Closure|array{class-string, string}|class-string $handler
     * @param array<string, string> $parameters
     */
    public function invoke(Closure|array|string $handler, ServerRequestInterface $request, array $parameters): ResponseInterface
    {
        [$callable, $reflection, $label] = $this->resolve($handler);

        $arguments = [];
        foreach ($reflection->getParameters() as $parameter) {
            if ($parameter->isVariadic()) {
                break;
            }

            $arguments[] = $this->argument($parameter, $request, $parameters, $label);
        }

        return $this->toResponse($callable(...$arguments), $label);
    }

    /**
     * @param Closure|array{class-string, string}|class-string $handler
     *
     * @return array{Closure, ReflectionFunctionAbstract, string}
     */
    private function resolve(Closure|array|string $handler): array
    {
        if ($handler instanceof Closure) {
            return [$handler, new ReflectionFunction($handler), 'closure'];
        }

        [$class, $method] = is_string($handler) ? [$handler, '__invoke'] : $handler;
        $reflection = new ReflectionMethod($class, $method);
        $label = $class . '::' . $method . '()';

        if ($reflection->isStatic()) {
            return [$reflection->getClosure(null), $reflection, $label];
        }

        $closure = $reflection->getClosure($this->container->make($class));

        return [$closure, $reflection, $label];
    }

    /**
     * @param array<string, string> $parameters
     */
    private function argument(ReflectionParameter $parameter, ServerRequestInterface $request, array $parameters, string $label): mixed
    {
        $name = $parameter->getName();
        $type = $parameter->getType();
        $typeName = $type instanceof ReflectionNamedType ? $type->getName() : null;

        if (array_key_exists($name, $parameters)) {
            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $class = $type->getName();

                foreach ($this->resolvers as $resolver) {
                    if (class_exists($class) && $resolver->supports($class)) {
                        return $resolver->resolve($class, $parameters[$name]);
                    }
                }
            }

            return self::convert($parameters[$name], $typeName, $type === null || $type instanceof ReflectionNamedType, $label, $name);
        }

        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            if (is_a($type->getName(), ServerRequestInterface::class, true)) {
                return $request;
            }

            $class = $type->getName();

            if (is_subclass_of($class, FromRequest::class)) {
                return $class::fromRequest($request, $this->container);
            }

            if ($this->container->has($type->getName())) {
                return $this->container->get($type->getName());
            }
        }

        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        throw RoutingException::unresolvableArgument($label, $name, 'no route parameter, request, container service or default value matches it');
    }

    private static function convert(string $value, ?string $typeName, bool $simpleType, string $label, string $name): mixed
    {
        if (!$simpleType) {
            throw RoutingException::unresolvableArgument($label, $name, 'route parameters cannot be injected into union or intersection types');
        }

        return match ($typeName) {
            null, 'string', 'mixed' => $value,
            'int' => self::toInt($value) ?? throw new NotFoundHttpException(),
            'float' => preg_match('/^-?(\d+(\.\d*)?|\.\d+)([eE][-+]?\d+)?$/D', $value) === 1 ? (float) $value : throw new NotFoundHttpException(),
            default => throw RoutingException::unresolvableArgument($label, $name, sprintf('route parameters are strings and cannot be converted to %s', $typeName)),
        };
    }

    /**
     * Accepts an optional minus sign and digits within the int range; leading zeros are allowed.
     */
    private static function toInt(string $value): ?int
    {
        if (preg_match('/^(-?)0*(\d+)$/D', $value, $match) !== 1) {
            return null;
        }

        $int = filter_var($match[1] . $match[2], FILTER_VALIDATE_INT);

        return is_int($int) ? $int : null;
    }

    private function toResponse(mixed $result, string $label): ResponseInterface
    {
        return match (true) {
            $result instanceof ResponseInterface => $result,
            is_string($result) => $this->responses->html($result),
            is_array($result), $result instanceof JsonSerializable => $this->responses->json($result),
            $result === null => $this->responses->empty(204),
            default => throw RoutingException::unsupportedReturn($label, get_debug_type($result)),
        };
    }
}
