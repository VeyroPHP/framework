# ADR 0003: Dependency policy

- Status: Accepted
- Date: 2026-10-25

## Context

The framework must be independent of Laravel and Symfony, but should not reinvent mature, low-level standards. Every runtime dependency becomes a dependency of every application built on VeyroPHP.

## Decision

**Runtime dependencies (`require`)**

- PSR interface packages (`psr/container`, `psr/log`, `psr/http-message`, etc.) are allowed when the module that uses them is built.
- Other runtime packages need an ADR that explains why, the alternatives considered, the maintenance status, the license (must be MIT, BSD, Apache-2.0 or similar) and its transitive dependencies.
- No Laravel (`illuminate/*`) or Symfony framework packages (`symfony/http-kernel`, `symfony/framework-bundle`) as runtime dependencies. Small standalone Symfony components may be proposed through an ADR, but the default is to avoid them.
- Optional integrations (Redis, etc.) are `suggest` entries, not `require` entries.

**Development dependencies (`require-dev`)**

- Tools: PHPUnit, PHPStan and PHP-CS-Fixer. Adding another tool requires a short note in the pull request.

**Versions**

- Use caret constraints at the lowest version actually tested (`^11.5`, not `*` or `>=`).
- `composer.lock` is not committed, because this is a library and applications resolve their own versions. CI instead tests both the highest and the lowest allowed versions (`--prefer-lowest`).
- `composer audit` runs in CI.

## Consequences

- Applications get a small, predictable dependency tree.
- Some functionality is implemented in the framework instead of pulled in, which takes more effort; each such case should be weighed against an existing package.
- Phase 0 has no runtime dependencies other than PHP itself.
