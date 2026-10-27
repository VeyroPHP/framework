# ADR 0034: First-party packages split from the core

- Status: Accepted
- Date: 2026-10-26

## Context

The package-ecosystem plan (ROADMAP, step 6) asked for official packages to be split from the core one at a time. They should use the same contract as third-party packages (ADR 0032), so that contract is proven by the framework's own code. Publishing them (GitHub, Packagist) is postponed by the owner.

## Decisions

- **Monorepo.** First-party packages live in `packages/<name>` in the framework repository, each with its own:
  - `composer.json` (`veyrophp/<name>`, requires `veyrophp/core`, MIT)
  - `extra.veyrophp.providers`
  - `README.md`, `LICENSE` and `CHANGELOG.md`
  - They share the framework's version numbers. Publishing later means a read-only split repository per package (the Symfony/Laravel model). That is part of the postponed publication work.
- **Inside the monorepo:**
  - The framework's `composer.json` autoloads packages in `autoload-dev` only, so `veyrophp/core` itself does not contain them.
  - `phpunit.xml` (testsuite `Packages`), PHPStan and php-cs-fixer cover `packages/`.
  - `archive.exclude` keeps `/packages` out of the framework's dist.
- **Namespaces do not change.** `VeyroPHP\Ai\…` stays `VeyroPHP\Ai\…`, so application code keeps working. Applications only add `composer require veyrophp/<name>`.
- **Boundary test** (`tests/Unit/Architecture/PackageBoundaryTest.php`):
  - The core never references a package namespace.
  - Every package is a valid VeyroPHP package whose providers exist.
  - Packages use only core classes that exist and are not `@internal`, the same rule as for third-party packages.
  - Both violations were introduced on purpose and were caught.
- **What gets split.** A module becomes a package when it is optional for most applications and no other core module uses it, and especially when it brings an external dependency.
  - `VeyroPHP\Ai` is the first: optional, nothing in the core uses it, and its driver needs `anthropic-ai/sdk`.
  - Dependency scan of the core, 2026-10-26: nothing else in `src/` uses Notifications, Translation, Filesystem, Scheduling or Auth\Rbac, so they *could* be split.
  - Mail, Queue, Orm, Session and Cache are used across the core and stay in it.
  - The next splits happen only with a reason (an external dependency, a different release pace, or many applications not needing the module). Splitting everything would make every application manage more packages for no gain.
- **Package-local installs** (a `vendor/` inside `packages/<name>`) are not used. A path repository back to the framework root would point a junction at its own parent. Independence is checked instead by installing the package into applications outside the repository.

## Verified (2026-10-26)

- **Ai moved** (`src/Ai` → `packages/ai/src`, tests → `packages/ai/tests`).
  - The framework suite passes, including the package suite (20 tests).
  - PHPStan and php-cs-fixer are clean, and `composer validate` passes for both.
- **Starter:**
  - Before `composer require veyrophp/ai`, the starter failed to find `AiServiceProvider`, which shows the framework really no longer contains it.
  - After the require (path repository), discovery registered it and the explicit `register()` was removed from `bootstrap/app.php`.
  - The `Ai` service resolves, and `AI_PROVIDER=fake php veyro ai:ask` answers.
  - The starter's 29 tests pass.
- **Upgrade path:** the separate clean application still has the old explicit `register(new AiServiceProvider())`. After `composer require veyrophp/ai`, it works, with exactly one provider instance (discovery skips explicitly registered providers).

## Consequences

- **Upgrading:** applications that use AI must add `composer require veyrophp/ai`. This is noted in the CHANGELOG.
- **Junctions:** both local applications now also have `vendor/veyrophp/ai` as a junction into the framework. Remove it before deleting their `vendor/`.
