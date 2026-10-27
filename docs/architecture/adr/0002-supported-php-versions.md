# ADR 0002: Supported PHP versions

- Status: Accepted
- Date: 2026-10-25

## Context

The engineering plan proposed PHP 8.3+ as the initial target, "subject to compatibility and CI verification". The primary development machine runs PHP 8.2.12 (XAMPP). Supporting a version that cannot be run locally would mean code that is never executed during development.

PHP 8.2 receives security fixes until 31 December 2026. PHP 8.3 and 8.4 are actively supported.

## Options

1. **PHP 8.3+.** Allows typed class constants and `#[\Override]`. Cannot be tested on the development machine without installing a second PHP version.
2. **PHP 8.2+.** Testable locally today. Provides readonly classes, `true`/`false`/`null` standalone types and DNF types, which cover the framework's needs.

## Decision

Option 2: require `php: ^8.2`. Composer's `config.platform.php` is set to `8.2.0` so dependency resolution never selects packages that need a newer PHP.

CI tests PHP 8.2, 8.3 and 8.4 on Linux, PHP 8.2 on Windows, and PHP 8.2 with the lowest allowed dependency versions. Only versions tested in CI are documented as supported.

## Consequences

- PHP 8.3-only features (typed class constants, `json_validate()`, `#[\Override]`) are not used.
- Because PHP 8.2 reaches end of life on 31 December 2026, the minimum version must be reviewed before `0.1.0`. Raising it will be a new ADR. The likely outcome is to require 8.3 once the development machine is upgraded.

## Review 2026-10-26: keep 8.2 for 0.1.x, and a policy for raising it

The owner decided to keep `^8.2` for the 0.1.x releases. The release notes for 0.1.0 must say that PHP 8.2 gets no security fixes after 31 December 2026.

Policy for future PHP versions:

1. **Supported means:** a PHP branch that still gets security fixes from php.net and is tested in CI. The framework does not promise to run on PHP versions that php.net no longer patches.
2. **New PHP versions** are added to CI when their first release candidate appears (usually September–October). Deprecations are fixed before the final release in November, so the framework works on day one.
3. **Raising the minimum:** the first framework release after a PHP branch reaches end of life (31 December each year) requires the next branch. Before 1.0 this happens in a minor release (0.1 → 0.2); after 1.0 only in a major release. It is announced in the CHANGELOG at least one release before.
4. **Users on the old PHP** are not broken: Composer keeps installing the last framework version that supports their PHP. That last line gets security fixes for 6 months after the minimum is raised.
5. **Raising the minimum touches:** `composer.json` (`require.php`, `config.platform.php`) in the framework and starter app, the CI matrix, this ADR, the CHANGELOG and the requirements in the README and quickstart. Code is not rewritten; newer language features are adopted gradually.
6. **PHP security releases** (for example PHP2Shell, CVE-2026-109618 and CVE-2026-109619, fixed in 8.2.35, 8.3.36, 8.4.27 and 8.5.12) are bugs in PHP itself. Framework code cannot fix them; only updating PHP can. The deployment guide must state the minimum patch level, not only the branch.

Planned: 0.1.x requires 8.2; the first release after 31 December 2026 (0.2) requires 8.3.
