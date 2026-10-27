# ADR 0032: Package ecosystem contract

- Status: Accepted
- Date: 2026-10-26

## Context

The owner asked for an extensible package ecosystem with a Laravel-like developer experience: packages as independent Composer libraries that integrate services, config, commands, routes and listeners, are discovered automatically, can be tested before publication, and are distributed through Composer, Git and Packagist. There is to be no VeyroPHP registry and no claims of trust.

## Decisions

- **Composer and Packagist for everything.** No registry, resolver or catalog in this release; a catalog website is a later option.
- **Discovery through `extra.veyrophp.providers`**, read from `vendor/composer/installed.json`:
  - No package code runs during discovery.
  - The order is deterministic: packages by name, providers in the order listed.
  - Invalid names and class names are skipped with warnings, never guessed.
  - The result is cached in `bootstrap/cache/packages.php` and rebuilt when `installed.json` changes, or by `package:discover` (run by Composer's `post-autoload-dump` in the starter).
  - Applications opt out per package or completely (`packages.dont_discover`), and providers they registered themselves are not registered twice.
- **Contract:** `PackageServiceProvider` with `mergeConfig`, `publishes`, `commands` and `listen`; `register()` for bindings and `boot()` for wiring.
  - Providers must be constructible without arguments, and a violation gives a clear error naming the class.
- **Config:** `Config::mergeDefaults()` adds a package's top-level key. Application values always win, nested arrays merge by key, and application lists replace default lists (merging lists would silently add items, for example extra allowed hosts). Existing values never change, so `Config` stays immutable for application code.
- **Commands** go through a `CommandRegistry` read when the console is first created, so provider order does not matter.
- **Publishing** (`vendor:publish`) accepts only relative targets inside the application, never overwrites without `--force`, and has `--list`.
- **No global middleware from packages.** Middleware changes every request, so applications add it explicitly. Routes are allowed, and the guide asks for a config switch.
- **`TestApplication`** (Testbench-like) boots an application in a temporary directory with an in-memory test stack. `forPackage()` registers the providers declared in the package's `composer.json`, so the discovery metadata is tested too.
- **Compatibility:** semantic versioning; packages require `veyrophp/core` with a caret constraint; 0.x minors may break; only non-`@internal` API is promised. See the backward-compatibility document.
- **Trust:** installing means trusting. Discovery lists what runs and documentation explains plugins, scripts, lock files and audits. No "safe" labels.

## Verified

- `PackageDiscoveryTest` (7 tests):
  - sorting, validation warnings, cache rebuild after `installed.json` changes, opt-out of one package and of all
  - no code run for missing providers, with a clear error at registration
  - registration order across packages and `register`/`boot`
  - config merge rules (app wins, lists kept, nested defaults added)
  - package commands in the console
  - explicit registration not duplicated
  - provider validation
  - publishing: list, publish, skip existing, `--force`, unknown tag, unsafe targets rejected
  - the `package:discover` output
- The sample package `acme/veyrophp-greeting` (outside the framework, own vendor namespace, own `composer.json`, README, LICENSE) passes its own 3 tests with `TestApplication::forPackage()`, including its route over HTTP and publishing.
- A **clean application** was created from the starter's `composer archive` export with `composer install` and the create-project scripts. The package was installed with `composer require acme/veyrophp-greeting:^1.0` through a path repository simulating release 1.0.0.
  - Discovery registered it.
  - `greeting:hello` printed the default greeting.
  - `GET /greeting/Asha` answered through Apache.
  - `vendor:publish` copied the config, and editing it changed both outputs.
  - `dont_discover` removed the command and, after OPcache revalidated the config file, the route.
  - `composer remove` unregistered it and left the package source intact.

**Not done:** the package has not been pushed to GitHub, released, submitted to Packagist, or installed from Packagist; all of that needs the owner's accounts. First-party packages are not yet split out of `veyrophp/core`.
