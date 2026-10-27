# Architecture Overview

Status: **proposed**. Implemented today: `Application`, `Exception` (including `ErrorHandler`), `Container`, `Config` (including `Env`), `Logging`, `Http` (kernel, middleware), `Routing`, `View`, `Database` (connections, dialects, query builder) and `Support` (ULID).

Built-in service providers live in the module they configure (`Config\ConfigServiceProvider`, `Logging\LoggingServiceProvider`). They depend on `Application`, but `Application` never depends on them, so the graph stays acyclic. Everything else below is the target design and will change if implementation shows a better path; such changes are recorded as ADRs.

## Module dependency map

Arrows point from a module to the modules it may depend on. A module must never depend on a module above it in this list, which keeps the graph acyclic.

```
Console ──────────────┐
                      ▼
Http ──► Routing ──► Container ──► Contracts
 │          │           ▲              ▲
 │          ▼           │              │
 │      Middleware ─────┘              │
 ▼                                     │
Validation ──► Security ───────────────┤
                                       │
Orm ──► Database ──► Profiling ────────┤
            │                          │
            └──► Cache (optional) ─────┤
                                       │
Application ──► Container, Config, Logging, Exception
Config, Logging, Exception ────────────┘
```

| Module | Responsibility | May depend on |
| --- | --- | --- |
| `Contracts` | Framework interfaces that other modules implement or consume | nothing (PSR interfaces only) |
| `Exception` | `VeyroPHPException` marker and shared exception types | nothing |
| `Config` | Load and read configuration and environment values | `Contracts`, `Exception` |
| `Logging` | PSR-3 compatible logging and secret redaction | `Contracts`, `Exception` |
| `Container` | Dependency injection, bindings, singletons, scopes, cycle detection | `Contracts`, `Exception` |
| `Application` | Bootstrap and lifecycle, service providers | `Container`, `Config`, `Logging`, `Exception` |
| `Http` | Request/response, kernel, emitter, exception-to-response mapping | `Routing`, `Middleware`, `Container`, `Contracts` |
| `Routing` | Route collection, matching, URL generation | `Container`, `Contracts` |
| `Middleware` | Pipeline and built-in middleware (CORS, rate limit, CSRF) | `Container`, `Contracts`, `Security` |
| `Validation` | Rules, validators, error formatting | `Contracts` |
| `Security` | CSRF tokens, password hashing, rate-limit store contracts, redirect checks | `Contracts`, `Cache` contract |
| `Database` | Connections, query builder, dialects, transactions, schema, migrations | `Contracts`, `Profiling` |
| `Profiling` | Query count, timing, slow-query and N+1 reports | `Contracts`, `Logging` |
| `Cache` | Cache contract and array, file and Redis drivers | `Contracts` |
| `Orm` | Optional models and relations on top of the query builder | `Database` |
| `Console` | CLI kernel and commands | any module it exposes |

Rules:

- `Database` never depends on `Http`. The framework must be usable as a CLI or queue worker without an HTTP layer.
- `Orm` is optional; nothing else depends on it.
- Redis is optional; only the Redis cache driver needs `ext-redis` or a client library.
- No module reads globals like `$_SERVER` except the HTTP request factory at the edge.

## Request lifecycle (target)

1. Front controller creates `Application` with the base path.
2. Configuration and service providers are loaded; the container is built.
3. The HTTP kernel creates a request from globals (the only place globals are read).
4. The middleware pipeline runs, ending in the router.
5. The router matches the route, the container resolves the controller, the action runs.
6. Exceptions are mapped to responses; production responses never contain stack traces.
7. The response is emitted. Request-scoped services are released.

## Major technical risks

| Risk | Why it matters | Mitigation |
| --- | --- | --- |
| Scope is very large for one maintainer | Risk of many half-finished modules | Strict phase order; a phase starts only when the previous phase's acceptance criteria pass |
| Container complexity | Autowiring magic hurts debuggability and performance | Start with explicit bindings plus constructor autowiring; no property injection; benchmark resolution before adding a compiled container |
| PSR-7 immutability cost | Many `with*()` copies per request | Benchmark in Phase 2; ADR 0004 keeps a native fast path possible |
| Long-running workers later | State leaking between requests if services hold request data | Request-scoped services from Phase 1; no static state in core; tests that run two requests through one kernel |
| Three SQL dialects | Subtle differences (identifier quoting, upsert, `RETURNING`, booleans, limits) | Dialect classes with per-dialect SQL snapshot tests plus integration tests against real database versions in CI |
| PostgreSQL not available locally | XAMPP has no `pdo_pgsql` | PostgreSQL integration tests run in CI with a service container; document the local setup |
| Nested transactions | Savepoint support and error behavior differ | Savepoint-based strategy decided by ADR in Phase 3, tested on all three databases |
| ORM hiding queries | N+1 and serialization-triggered queries | Lazy loading disabled or reported by default in development; tests assert query counts |
| Benchmark credibility | Unsupported numbers damage trust | Publish scripts, raw results, hardware and versions; never publish numbers that were not measured |
| Branding change | Name is provisional | Name appears only in `composer.json`, namespace and docs; no name in config keys or env variable prefixes until confirmed |

## Decisions

| ADR | Title | Status |
| --- | --- | --- |
| [0001](adr/0001-record-architecture-decisions.md) | Record architecture decisions | Accepted |
| [0002](adr/0002-supported-php-versions.md) | Supported PHP versions | Accepted |
| [0003](adr/0003-dependency-policy.md) | Dependency policy | Accepted |
| [0004](adr/0004-http-message-standard.md) | HTTP message standard | Accepted |
| [0005](adr/0005-container-design.md) | Container design | Accepted |
| [0006](adr/0006-configuration.md) | Configuration | Accepted |
| [0007](adr/0007-environment-variables.md) | Environment variables | Accepted |
| [0008](adr/0008-logging-and-error-handling.md) | Logging and error handling | Accepted |
| [0009](adr/0009-http-kernel-and-routing.md) | HTTP kernel and routing | Accepted |
| [0010](adr/0010-database-layer.md) | Database layer | Accepted |
| [0011](adr/0011-query-profiling-and-benchmarks.md) | Query profiling and benchmarks | Accepted |
| [0012](adr/0012-cache.md) | Cache | Accepted |
| [0013](adr/0013-schema-and-migrations.md) | Schema builder and migrations | Accepted |
| [0014](adr/0014-orm.md) | ORM | Accepted |
| [0015](adr/0015-validation-sessions-auth-rbac.md) | Validation, sessions, authentication and RBAC | Accepted |
