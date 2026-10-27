# Creating VeyroPHP packages

A VeyroPHP package is a normal Composer package. You write it in your own repository and namespace, without changing the framework. You publish it with Git and Packagist, and people install it with `composer require`. VeyroPHP adds only a small integration contract on top. Design: [ADR 0032](../architecture/adr/0032-package-ecosystem.md).

A complete, tested example is the sample package `acme/veyrophp-greeting`, built during development at `D:\xampp\htdocs\veyrophp-packages\acme-greeting`. It has:

- a service
- a config file with defaults the application can override
- a console command
- a route
- an event listener
- a publishable config file
- tests and a README

The framework's own optional modules are packages too: see `packages/ai` (`veyrophp/ai`) and [ADR 0034](../architecture/adr/0034-first-party-packages.md).

## 1. Structure

```
acme-greeting/
  composer.json
  config/greeting.php          default settings
  src/GreetingServiceProvider.php
  src/Greeter.php
  src/Console/GreetCommand.php
  tests/GreetingPackageTest.php
  README.md, LICENSE, CHANGELOG.md
```

## 2. composer.json

```json
{
    "name": "acme/veyrophp-greeting",
    "type": "library",
    "license": "MIT",
    "require": {
        "php": "^8.2",
        "veyrophp/core": "^0.1"
    },
    "require-dev": { "phpunit/phpunit": "^11.5" },
    "autoload": { "psr-4": { "Acme\\Greeting\\": "src/" } },
    "extra": {
        "veyrophp": {
            "providers": ["Acme\\Greeting\\GreetingServiceProvider"]
        }
    }
}
```

- **Your own vendor and namespace.** The `veyrophp/` vendor name and the `VeyroPHP\` namespace are reserved for official packages.
- **Declare what you support:** `php` and `veyrophp/core`. Composer then refuses to install the package where it would not work.
- **`extra.veyrophp.providers`** lists the service providers to register, in order. Nothing else is needed for auto-discovery.

## 3. The service provider

Extend `VeyroPHP\Package\PackageServiceProvider`. The class must be constructible without arguments.

```php
final class GreetingServiceProvider extends PackageServiceProvider
{
    public function register(Application $app): void
    {
        $this->mergeConfig($app, 'greeting', __DIR__ . '/../config/greeting.php');
        $this->publishes($app, [__DIR__ . '/../config/greeting.php' => 'config/greeting.php'], 'greeting-config');
        $this->commands($app, GreetCommand::class);

        $app->container()->singleton(Greeter::class, static fn (Container $c): Greeter => new Greeter(
            $c->make(Config::class)->string('greeting.template'),
        ));
    }

    public function boot(Application $app): void
    {
        $this->listen($app, Login::class, GreetOnLogin::class);
        // Routes: $app->container()->make(Router::class)->get(...) when the Router is bound.
    }
}
```

| Need | How |
| --- | --- |
| Services | Bind them in `register()`: `singleton()`, `bind()`, `scoped()` (per request). Constructor injection works for your classes. |
| Configuration | `mergeConfig($app, 'key', file)`. Use one top-level key named after your package. The application's values always win, and lists set by the application are not merged. |
| Publishing files | `publishes($app, [source => 'relative/target'], 'tag')`. The user runs `php veyro vendor:publish --tag=...`. Targets must stay inside the application, and files are never overwritten without `--force`. |
| Console commands | `commands($app, MyCommand::class)`. Name them `package:action` (`greeting:hello`). |
| Event listeners | `listen($app, Event::class, Listener::class)` in `boot()`. |
| Routes | Register them in `boot()` through the `Router`, behind a config switch so applications can turn them off. Prefix paths and route names with your package name. |
| Middleware | Ship middleware classes. Packages **cannot** add global middleware; applications add your middleware where they want it, and the README says how. |
| Migrations | Ship a schema class (like `QueueSchema`) or a publishable migration file. |

Keep `register()` free of work: no database queries, no network calls, no files written. It runs on every request.

## 4. Testing locally

Install the framework next to the package through a Composer path repository:

```json
"repositories": [{ "type": "path", "url": "../../veyrophp", "options": { "versions": { "veyrophp/core": "0.1.x-dev" } } }],
"minimum-stability": "dev",
"prefer-stable": true
```

Then test with `VeyroPHP\Testing\TestApplication`:

```php
$test = TestApplication::forPackage(dirname(__DIR__), ['greeting' => ['template' => 'Hi :name']]);

$test->make(Greeter::class)->greet('Asha');
[$code, $output] = $test->command(['greeting:hello', 'Ravi']);
$test->cleanUp();
```

- **`forPackage()`** registers exactly the providers in your `composer.json`, so a typo there fails the test.
- **The default test stack:** SQLite in memory, array cache and mail, sync queue, events and console. Pass `framework:` providers (for example `HttpServiceProvider`) for routes, then use `$test->http()->get('/...')`.
- **Before publishing:** try the package in a fresh application. Add a path repository, `composer require acme/veyrophp-greeting`, then check `php veyro package:discover`, your commands and your routes.

## 5. Versions and compatibility

- Use semantic versioning for your package. Tag releases (`v1.0.0`) in Git, and keep a CHANGELOG.
- Require `veyrophp/core` with a caret constraint for the series you tested (`^0.1`). While VeyroPHP is 0.x, a minor release (0.2) may break compatibility; patch releases (0.1.x) will not. From 1.0, `^1.0` covers every 1.x release. See the [backward-compatibility promise](../architecture/backward-compatibility.md).
- Depend only on the public API. Classes marked `@internal` may change in any release.
- Declare the PHP versions you test (`"php": "^8.2"`) and run your CI on each.

## 6. Publishing

1. Push the repository to GitHub (or another Git host). Include `README.md`, `LICENSE` and `CHANGELOG.md`.
2. Tag a release: `git tag v1.0.0 && git push --tags`.
3. Submit the repository URL at packagist.org (signed in), and enable the GitHub hook so new tags appear automatically.
4. Check that it installs in a clean application: `composer require acme/veyrophp-greeting`.

Each step is separate: a package can be tested locally without being on GitHub, be on GitHub without being on Packagist, and be on Packagist without being verified to install. Say exactly which steps happened.

## 7. The README your users need

Every package README should cover:

- requirements (PHP, VeyroPHP)
- installation
- configuration and publishing
- usage
- testing
- upgrading
- removal (`composer remove`, then delete published files)
- how to report security problems

The sample package's README follows this list.

## 8. Security for package authors

See [Package trust and security](security.md). In short:

- Never run code at install time: no Composer scripts or plugins in a library.
- Do not read secrets you do not need.
- No network calls on boot.
- Pin nothing you do not have to.
- Run `composer audit` (or `php veyro security:audit` in an application) in CI.
- Publish a security contact.
