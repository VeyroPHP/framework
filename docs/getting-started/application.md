# Application and Service Providers

Status: **implemented** (Phase 1).

## Lifecycle

```php
use VeyroPHP\Application\Application;
use VeyroPHP\Config\ConfigServiceProvider;
use VeyroPHP\Logging\LoggingServiceProvider;

$app = new Application(__DIR__);           // base path must exist

$app->register(new ConfigServiceProvider()); // loads .env and config/
$app->register(new LoggingServiceProvider());
$app->register(new App\Providers\AppServiceProvider());

$app->boot();
```

1. `new Application($basePath)` creates the container and registers the application in it under `Application::class`.
2. `register($provider)` calls the provider's `register()` immediately.
3. `boot()` runs `beforeBoot` callbacks, then `boot()` on every `BootableProvider` in registration order, then `afterBoot` callbacks.

The order is deterministic: providers run in the order they were registered.

## Writing a provider

```php
use VeyroPHP\Application\Application;
use VeyroPHP\Application\BootableProvider;

final class AppServiceProvider implements BootableProvider
{
    public function register(Application $app): void
    {
        // Only add bindings here.
        $app->container()->singleton(PaymentGateway::class, StripeGateway::class);
    }

    public function boot(Application $app): void
    {
        // Runs after every provider is registered, so any service can be used.
        $app->container()->make(PaymentGateway::class)->warmUp();
    }
}
```

Implement `ServiceProvider` if you only need `register()`, or `BootableProvider` if you also need `boot()`.

## Lifecycle rules

Each of these throws `ApplicationException`:

- calling `boot()` twice,
- calling `register()`, `beforeBoot()` or `afterBoot()` after `boot()`,
- registering two providers of the same class.

If a provider or callback throws during `boot()`, the exception propagates unchanged and `isBooted()` stays `false`.

## Built-in providers

| Provider | Registers |
| --- | --- |
| `VeyroPHP\Config\ConfigServiceProvider` | `Env` from `<base>/.env` and `Config` from `<base>/config/`. Both are optional; missing ones give empty values. |
| `VeyroPHP\Logging\LoggingServiceProvider` | `Psr\Log\LoggerInterface` as a `FileLogger` singleton. See [logging and errors](logging-and-errors.md). |

## Paths

`$app->basePath('storage/logs')` returns a path inside the base path. Absolute paths, `..` segments, null bytes and stream wrappers (`phar://`) are rejected with `InvalidPathException`.
