# ADR 0005: Container design

- Status: Accepted
- Date: 2026-10-25

## Context

Phase 1 needs a dependency injection container with constructor injection, interface bindings, singletons, scoped services and circular-dependency detection. The plan warns against building a complicated container before the basics are proven, and asks for explicit behavior over magic.

## Decision

`VeyroPHP\Container\Container` implements PSR-11 (`psr/container` ^2.0, the first runtime dependency, allowed by ADR 0003).

1. **Registration is explicit.** `bind()` (transient), `singleton()`, `scoped()` and `instance()`. A binding's concrete is a factory `Closure(Container): mixed`, a class name, or `null` (autowire the id itself).
2. **Bindings are validated when registered.** A class-name concrete must exist, be instantiable and, when the id is a class or interface, be a subtype of it. Mistakes fail at boot time instead of on the first request that uses the service.
3. **Autowiring is limited to constructor parameters with a single class or interface type.** No property, setter or method injection. Built-in, union, intersection and untyped parameters are not guessed; they use their default value or fail with a message naming the parameter, the class and the resolution chain. Variadic parameters receive no arguments.
4. **Unregistered instantiable classes are autowired as transient.** Sharing an instance always requires an explicit `singleton()` or `scoped()`.
5. **A class-name concrete is built directly.** `bind(LoggerInterface::class, FileLogger::class)` builds a `FileLogger` regardless of any separate binding for `FileLogger`. To alias one binding to another, use a factory: `fn (Container $c) => $c->get(FileLogger::class)`.
6. **Circular dependencies are detected** through autowiring and through factories, and reported with the full chain (`A -> B -> C -> A`).
7. **Captive dependencies are rejected.** Resolving a scoped service while any singleton is being built throws, because the singleton would keep a stale scoped instance after `resetScope()`. This protects future long-running workers (see risk list in the architecture overview).
8. **Exceptions are not swallowed.** Errors thrown by constructors or factories propagate unchanged. A failed singleton is not cached and can be retried.
9. **`has()` follows PSR-11**: true when `get()` would not throw `NotFoundException`. It does not promise that building succeeds.
10. **`make()`** is a typed variant of `get()` for classes and interfaces. It checks the result with `instanceof` and gives static analysis the right type.

## Not included yet

- Reflection caching or a compiled container. This will be considered only after the Phase 2 benchmarks measure resolution cost.
- Contextual bindings, tagged services, decorators and `call()` method injection. These will be added only when a real use case appears.

## Consequences

- Behavior is predictable, and errors point to the exact parameter.
- Applications must register shared services explicitly, which takes slightly more configuration than containers that share by default.
- Reflection runs on every autowired resolution until caching is added.
