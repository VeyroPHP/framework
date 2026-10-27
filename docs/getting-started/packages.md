# Using Other Composer Packages (including Laravel's)

To **write** a package for VeyroPHP, see [Creating VeyroPHP packages](../packages/creating-packages.md); before installing unknown packages, read [Package trust and security](../packages/security.md).

VeyroPHP applications are normal Composer projects, so most PHP packages work. What matters is whether a package needs a specific framework.

Checked on 2026-10-26 with a fresh project (VeyroPHP + PHP 8.2):

| Kind of package | Example | Result |
| --- | --- | --- |
| Framework-agnostic | `nesbot/carbon`, Guzzle, Monolog, PHPMailer, `league/*`, Symfony components | ✅ Installs and works |
| Laravel components that work standalone | `illuminate/collections` (`collect()`) | ✅ Installs and works, also with VeyroPHP's container autowiring |
| Laravel-specific packages | `spatie/laravel-permission` | ❌ Installs, but fails at the first call: `Call to undefined function config()` |

Laravel-specific packages (Spatie's `laravel-*`, Sanctum, Livewire, Filament, Horizon, Cashier…) expect a Laravel application: its service providers, facades, `config()` helper, Eloquent and Artisan. VeyroPHP does not provide those, and no adapter is planned. Use the VeyroPHP equivalent instead, for example the RBAC module for roles and permissions.

### Can a Laravel package be made to work anyway?

Tried on 2026-10-26 with `spatie/laravel-permission` 6 (outside Laravel, PHP 8.2). It works only after booting, by hand, the Laravel pieces it expects. Each piece was found by running it and reading the next error:

1. Laravel's own container, set as the global instance, plus facades.
2. Laravel's config repository with the package's config **and** Laravel's `auth` guard settings.
3. Global helpers `config()`, `app()` and `__()`.
4. Eloquent (`illuminate/database`) on its own database connection, plus `db` and `db.schema` bindings for its migration.
5. Laravel's cache manager, bound under three names.

About 100 lines; afterwards roles, permissions and `hasRole()` / `hasPermissionTo()` worked. Still missing: its route middleware (Laravel middleware is not PSR-15), `Gate` / `$user->can()`, Blade directives and its Artisan commands. Costs: a second container, a second ORM and a second database connection next to VeyroPHP's, about 24 MB of extra packages, and shims that can break with any package update. **Not recommended**; VeyroPHP's RBAC covers most of the same ground:

| Feature | spatie/laravel-permission | VeyroPHP RBAC |
| --- | --- | --- |
| Roles, permissions, permissions per role | ✅ | ✅ |
| Permissions given to one user directly | ✅ | ✅ (grants) |
| Taking one permission away from one user | ❌ | ✅ (denials) |
| Super-admin that passes every check | via `Gate::before` | ✅ built in |
| Route middleware | ✅ | ✅ `RequirePermission`, `RequireRole` |
| Admin screens | ❌ (needs e.g. Filament) | ✅ starter app `/admin/access` |
| Many users' roles in few queries | cached | ✅ `preload()` |
| Teams (roles per organization) | ✅ | ❌ not yet |
| Wildcard permissions (`posts.*`) | ✅ | ❌ not yet |
| Several auth guards | ✅ | ❌ (one) |

Packages built on Laravel's HTTP layer (Sanctum guards, Livewire, Filament) register routes, middleware and Blade views in Laravel's router and view engine. Bridging them would mean rebuilding Laravel's HTTP stack, so they are not an option. To use them, run a separate Laravel application (for example an admin panel on its own subdomain) against the same database.

Notes:

- Laravel 13 components and current Spatie packages need PHP 8.3; on PHP 8.2 Composer picks the previous major version (for example `illuminate/collections` 12) automatically.
- VeyroPHP uses PSR standards (PSR-3 logging, PSR-7/15/17 HTTP, PSR-11 container, PSR-16 cache), so packages built on them plug in directly.
