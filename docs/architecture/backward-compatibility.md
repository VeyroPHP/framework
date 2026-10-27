# Backward Compatibility Promise

This page says what stays stable between VeyroPHP releases, so applications know what they can rely on when they update.

## Version numbers

VeyroPHP follows [Semantic Versioning](https://semver.org/) from `0.1.0`:

- **Patch releases** (0.1.0 → 0.1.1) only fix bugs and security issues. They never break the covered API.
- **Before 1.0, minor releases** (0.1 → 0.2) may contain breaking changes. Each one is listed in the CHANGELOG under "Changed" or "Removed" with upgrade steps.
- **From 1.0, breaking changes** happen only in major releases (1.x → 2.0).

Raising the minimum PHP version follows [ADR 0002](adr/0002-supported-php-versions.md): a minor release before 1.0, a major release after.

## What is covered

- Public classes, interfaces, methods, constants and constructor parameters that are **not** marked `@internal`.
- Protected members that applications are meant to override or set: the `Model` constants (`TABLE`, `FILLABLE`, `HIDDEN`, `CASTS`, `PUBLIC_ID`, ...), `Command::definition()`/`help()`/`execute()`, `Migration::up()`/`down()`.
- Configuration keys read by the framework (`config/*.php`) and environment variable names in the starter app.
- Console command names, options and exit codes (0 success, 1 failure, 2 usage error).
- HTTP behavior the framework guarantees: status codes for framework errors (404, 405, 403 for CSRF, 422 for validation, 429 for rate limits) and the JSON error format.
- Database schemas created by framework migrations (`RbacSchema`) and the migrations table.

## What is not covered

- Anything marked `@internal`. These classes are implementation details (SQL compilers, dialects, schema grammars, error renderers, the middleware pipeline and similar). They may change in any release, including patch releases.
- Private members, and the exact text of exception and error messages.
- Code under `tests/`, `bench/` and `resources/`.
- The HTML of default error pages and of the starter app's views; the starter app is copied into your project and is yours to change.
- Behavior that was a bug. If code relied on a bug, the fix still ships, and the CHANGELOG says so.
- Security fixes. If keeping the old behavior would leave applications vulnerable, the fix ships in a patch release even if it breaks something, and the CHANGELOG explains what to change.

## Deprecations

When practical, a feature is marked `@deprecated` (with the replacement) at least one minor release before it is removed, and the CHANGELOG lists it under "Deprecated".

## Extending framework classes

Most framework classes are `final`. Use composition: wrap a class, decorate it in the container, or implement the interface it implements (`Store`, `Rule`, `RouteParameterResolver`, `ExceptionRenderer`, PSR interfaces). Classes are made non-final only when there is a tested extension point.
