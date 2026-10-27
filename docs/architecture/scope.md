# Scope and Requirements

This document defines what VeyroPHP is trying to be, and what the first release (`0.1.0`) includes and excludes. The full engineering plan this was derived from lives with the project owner; this file is the reference inside the repository.

## Goal

An independent PHP framework for server-rendered websites, REST APIs and backends for SPA, mobile and desktop clients. It must not depend on Laravel or Symfony as its underlying framework. Small, well-established Composer packages and PSR standards may be used (see [ADR 0003](adr/0003-dependency-policy.md)).

## Principles

1. Explicit behavior over hidden magic.
2. Secure defaults, without claiming absolute security.
3. No hidden or unnecessary database queries; query cost must be measurable.
4. Every meaningful feature has automated tests, including failure paths.
5. No fake implementations, placeholder success responses or swallowed exceptions.
6. No performance or security claims that are not backed by reproducible evidence.
7. Keep `0.1.0` focused.

## In scope for 0.1.0

| Area | Contents |
| --- | --- |
| Core | Application lifecycle, DI container, service providers, configuration, environment, error handling, logging contracts |
| HTTP | Request/response, kernel, middleware pipeline, JSON, cookies, exception-to-response mapping |
| Routing | All common methods, parameters, constraints, named routes, groups, URL generation, correct 404/405 |
| Database | PDO connection manager, MySQL/PostgreSQL/SQLite dialects, query builder, transactions, migrations |
| Diagnostics | Query count and timing, slow-query report, redacted logging, N+1 detection in development |
| Cache | Cache contract, array and file drivers, optional Redis driver |
| ORM | Optional, built on the query builder, explicit eager loading |
| Security | CSRF, sessions and cookies, password hashing, validation, rate limiting, uploads, threat model |
| CLI | `serve`, generators, migrations, `route:list`, `test` |

## Out of scope for 0.1.0

- Web, SPA, mobile and desktop starter kits and SDKs (Phase 9).
- OpenAPI generation.
- A plugin ecosystem.
- Multi-tenancy.
- Async runtimes (Swoole, RoadRunner, FrankenPHP worker mode). The design must not prevent them later; see the risk list in [overview.md](overview.md).
- Any claim of production readiness.

## Quality bar

A feature counts as done only when it is implemented, tested (including failure cases), documented, passes static analysis and code style, and the roadmap and progress log are updated.
