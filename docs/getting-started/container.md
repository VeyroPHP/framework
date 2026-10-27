# Dependency Injection Container

Status: **implemented** (Phase 1). Design decisions are in [ADR 0005](../architecture/adr/0005-container-design.md).

`VeyroPHP\Container\Container` implements the PSR-11 `ContainerInterface`.

## Autowiring

Classes do not need to be registered. Constructor parameters typed with a class or interface are resolved automatically:

```php
use VeyroPHP\Container\Container;

final class UserService
{
    public function __construct(private UserRepository $users) {}
}

$container = new Container();
$service = $container->make(UserService::class); // UserRepository is built too
```

Unregistered classes are **transient**: every resolution creates a new instance.

Parameters with built-in types (`int`, `string`, `array`), union types or no type are never guessed. They use their default value, or resolution fails with a message such as:

```
Cannot resolve parameter $port of App\Mailer::__construct() while resolving App\UserService -> App\Mailer:
built-in type "int" is not autowired and has no default value.
```

Use a factory for such classes (see below).

## Registering services

| Method | Lifetime |
| --- | --- |
| `bind($id, $concrete = null)` | New value on every resolution |
| `singleton($id, $concrete = null)` | Built once, reused for the container's lifetime |
| `scoped($id, $concrete = null)` | Reused until `resetScope()`, e.g. once per request |
| `instance($id, $value)` | An existing value, reused |

`$concrete` can be:

- `null`: autowire `$id` itself.
- A class name: autowire that class. It must exist, be instantiable and implement or extend `$id`; otherwise registration throws immediately.
- A factory `Closure(Container): mixed`.

```php
$container->bind(LoggerInterface::class, FileLogger::class);

$container->singleton(Mailer::class, fn (Container $c) => new Mailer(
    port: 587,
    logger: $c->make(LoggerInterface::class),
));

$container->scoped(RequestContext::class);
```

Registering an id again replaces the old binding and discards any cached instance.

A class-name concrete is built directly; it does not go through that class's own binding. To make an interface return the same singleton as a concrete class, use a factory:

```php
$container->singleton(FileLogger::class);
$container->bind(LoggerInterface::class, fn (Container $c) => $c->get(FileLogger::class));
```

## Reading services

- `get($id)` returns the value (PSR-11).
- `make(SomeClass::class)` returns the value and checks that it is an instance of that class. Prefer it in application code because static analysis then knows the type.
- `has($id)` is true when the id is registered or is an instantiable class.

The container registers itself under `Container::class` and `Psr\Container\ContainerInterface`.

## Errors

All exceptions implement `Psr\Container\ContainerExceptionInterface` and `VeyroPHP\Exception\VeyroPHPException`.

| Exception | When |
| --- | --- |
| `NotFoundException` | The id is not registered and is not an instantiable class |
| `CircularDependencyException` | A service depends on itself, directly or indirectly. The message shows the chain, for example `A -> B -> C -> A` |
| `ContainerException` | Invalid registration, an unresolvable parameter, a wrong type from `make()`, or a captive dependency |

Exceptions thrown inside constructors or factories are not wrapped. If a singleton factory throws, nothing is cached and the next `get()` tries again.

## Scoped services and singletons

A singleton must not depend on a scoped service, because it would keep the first scope's instance forever. The container throws when this happens, even when the scoped service is reached indirectly:

```
Scoped service "App\RequestContext" cannot be injected into a singleton, because the singleton would keep it
after the scope is reset while resolving App\Cache -> App\RequestContext.
```

Either make the consumer scoped or transient, or inject something that looks up the current scoped value when it is needed.
