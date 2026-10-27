<?php

declare(strict_types=1);

namespace VeyroPHP\Container;

use Closure;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use VeyroPHP\Container\Exception\CircularDependencyException;
use VeyroPHP\Container\Exception\ContainerException;
use VeyroPHP\Container\Exception\NotFoundException;

/**
 * Dependency injection container.
 *
 * Services are registered explicitly with bind(), singleton(), scoped() or
 * instance(). Classes that are not registered are autowired: their constructor
 * parameters are resolved by class or interface type, and each resolution
 * creates a new instance. See docs/getting-started/container.md.
 */
final class Container implements ContainerInterface
{
    /** @var array<string, array{concrete: Closure|string|null, lifetime: Lifetime}> */
    private array $bindings = [];

    /** @var array<string, mixed> */
    private array $singletons = [];

    /** @var array<string, mixed> */
    private array $scoped = [];

    /** @var list<array{id: string, lifetime: Lifetime}> */
    private array $resolving = [];

    public function __construct()
    {
        $this->instance(self::class, $this);
        $this->instance(ContainerInterface::class, $this);
    }

    /**
     * Registers a service that is built again on every resolution.
     *
     * @param Closure(Container): mixed|class-string|null $concrete factory, class to autowire, or null to autowire $id itself
     *
     * @throws ContainerException when the binding is invalid
     */
    public function bind(string $id, Closure|string|null $concrete = null): void
    {
        $this->register($id, $concrete, Lifetime::Transient);
    }

    /**
     * Registers a service that is built once and then reused.
     *
     * @param Closure(Container): mixed|class-string|null $concrete
     *
     * @throws ContainerException when the binding is invalid
     */
    public function singleton(string $id, Closure|string|null $concrete = null): void
    {
        $this->register($id, $concrete, Lifetime::Singleton);
    }

    /**
     * Registers a service that is reused until resetScope() is called.
     *
     * @param Closure(Container): mixed|class-string|null $concrete
     *
     * @throws ContainerException when the binding is invalid
     */
    public function scoped(string $id, Closure|string|null $concrete = null): void
    {
        $this->register($id, $concrete, Lifetime::Scoped);
    }

    /**
     * Registers an already created value as a singleton.
     *
     * @throws ContainerException when the id is empty
     */
    public function instance(string $id, mixed $value): void
    {
        $this->register($id, static fn(): mixed => $value, Lifetime::Singleton);
        $this->singletons[$id] = $value;
    }

    /**
     * Returns true when get() would not throw NotFoundException for $id.
     *
     * A true result does not guarantee that building succeeds: a class can be
     * instantiable while one of its constructor parameters cannot be resolved.
     */
    public function has(string $id): bool
    {
        return isset($this->bindings[$id]) || self::isInstantiableClass($id);
    }

    /**
     * True only when $id was registered explicitly (bind, singleton, scoped or instance),
     * unlike has(), which is also true for any instantiable class.
     */
    public function bound(string $id): bool
    {
        return isset($this->bindings[$id]);
    }

    /**
     * @throws NotFoundException when $id has no binding and is not an instantiable class
     * @throws CircularDependencyException when $id depends on itself
     * @throws ContainerException when the service cannot be built
     */
    public function get(string $id): mixed
    {
        $binding = $this->bindings[$id] ?? null;

        if ($binding === null) {
            if (!self::isInstantiableClass($id)) {
                throw NotFoundException::forId($id);
            }

            return $this->resolve($id, null, Lifetime::Transient);
        }

        $lifetime = $binding['lifetime'];

        if ($lifetime === Lifetime::Singleton && array_key_exists($id, $this->singletons)) {
            return $this->singletons[$id];
        }

        if ($lifetime === Lifetime::Scoped) {
            $this->assertNotCaptive($id);

            if (array_key_exists($id, $this->scoped)) {
                return $this->scoped[$id];
            }
        }

        $value = $this->resolve($id, $binding['concrete'], $lifetime);

        if ($lifetime === Lifetime::Singleton) {
            $this->singletons[$id] = $value;
        } elseif ($lifetime === Lifetime::Scoped) {
            $this->scoped[$id] = $value;
        }

        return $value;
    }

    /**
     * Resolves a class or interface and checks the type of the result.
     *
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     *
     * @throws ContainerException when the service cannot be built or has the wrong type
     */
    public function make(string $id): object
    {
        $value = $this->get($id);

        if (!$value instanceof $id) {
            throw ContainerException::wrongType($id, $value);
        }

        return $value;
    }

    /**
     * Discards all scoped instances, e.g. at the end of a request.
     */
    public function resetScope(): void
    {
        $this->scoped = [];
    }

    /**
     * @param Closure(Container): mixed|string|null $concrete
     */
    private function register(string $id, Closure|string|null $concrete, Lifetime $lifetime): void
    {
        if ($id === '') {
            throw ContainerException::emptyId();
        }

        if (is_string($concrete)) {
            self::assertValidConcrete($id, $concrete);
        }

        unset($this->singletons[$id], $this->scoped[$id]);
        $this->bindings[$id] = ['concrete' => $concrete, 'lifetime' => $lifetime];
    }

    private static function assertValidConcrete(string $id, string $concrete): void
    {
        if (!class_exists($concrete)) {
            throw ContainerException::concreteNotFound($id, $concrete);
        }

        if (!(new ReflectionClass($concrete))->isInstantiable()) {
            throw ContainerException::concreteNotInstantiable($id, $concrete);
        }

        if ((class_exists($id) || interface_exists($id)) && !is_a($concrete, $id, true)) {
            throw ContainerException::concreteNotSubtype($id, $concrete);
        }
    }

    private function resolve(string $id, Closure|string|null $concrete, Lifetime $lifetime): mixed
    {
        foreach ($this->resolving as $frame) {
            if ($frame['id'] === $id) {
                throw CircularDependencyException::forChain([...$this->chain(), $id]);
            }
        }

        $this->resolving[] = ['id' => $id, 'lifetime' => $lifetime];

        try {
            if ($concrete instanceof Closure) {
                return $concrete($this);
            }

            return $this->build($concrete ?? $id);
        } finally {
            array_pop($this->resolving);
        }
    }

    private function build(string $class): object
    {
        if (!class_exists($class)) {
            throw ContainerException::notInstantiable($class, $this->chain());
        }

        $reflection = new ReflectionClass($class);

        if (!$reflection->isInstantiable()) {
            throw ContainerException::notInstantiable($class, $this->chain());
        }

        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return $reflection->newInstance();
        }

        $arguments = [];

        foreach ($constructor->getParameters() as $parameter) {
            if ($parameter->isVariadic()) {
                break;
            }

            $arguments[] = $this->resolveParameter($parameter);
        }

        return $reflection->newInstanceArgs($arguments);
    }

    private function resolveParameter(ReflectionParameter $parameter): mixed
    {
        $type = $parameter->getType();

        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            $dependency = $type->getName();

            if ($dependency === 'self') {
                $dependency = $parameter->getDeclaringClass()?->getName() ?? $dependency;
            }

            if ($this->has($dependency)) {
                return $this->get($dependency);
            }

            if ($parameter->isDefaultValueAvailable()) {
                return $parameter->getDefaultValue();
            }

            throw ContainerException::unresolvableParameter(
                $parameter,
                sprintf('"%s" has no binding and is not an instantiable class', $dependency),
                $this->chain(),
            );
        }

        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        $reason = match (true) {
            $type === null => 'the parameter has no type and no default value',
            $type instanceof ReflectionNamedType => sprintf('built-in type "%s" is not autowired and has no default value', $type->getName()),
            default => sprintf('union and intersection types ("%s") are not autowired', $type),
        };

        throw ContainerException::unresolvableParameter($parameter, $reason, $this->chain());
    }

    private function assertNotCaptive(string $id): void
    {
        foreach ($this->resolving as $frame) {
            if ($frame['lifetime'] === Lifetime::Singleton) {
                throw ContainerException::captiveDependency($id, [...$this->chain(), $id]);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function chain(): array
    {
        return array_column($this->resolving, 'id');
    }

    private static function isInstantiableClass(string $id): bool
    {
        return class_exists($id) && (new ReflectionClass($id))->isInstantiable();
    }
}
