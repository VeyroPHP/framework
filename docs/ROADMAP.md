# Roadmap

A phase starts only after the previous phase's acceptance criteria pass. Status values: **Done**, **In progress**, **Next**, **Planned**.

| Phase | Title | Status |
| --- | --- | --- |
| 0 | Architecture and repository setup | Done (CI not yet run on GitHub) |
| 1 | Core and dependency injection | Done |
| 2 | HTTP kernel and routing | Done |
| 3 | Database and query builder | Done (PostgreSQL verified only in CI config, not run yet) |
| 4 | Query profiler and performance baseline | Done |
| 5 | Cache and ORM | Done (PostgreSQL and Redis verified only in CI config) |
| 6 | Security and validation | Done (no external security review yet) |
| 7 | CLI and starter project | Done (create-project verified from a local archive; Packagist in Phase 8) |
| 8 | Release candidate and 0.1.0 | Next |
| 9 | Ecosystem expansion | Planned (after core is stable) |

## Phase 0: Architecture and repository setup

- [x] Scope and requirements document ([architecture/scope.md](architecture/scope.md))
- [x] Architecture overview, module dependency map and risk list ([architecture/overview.md](architecture/overview.md))
- [x] ADRs: decision records, PHP versions, dependency policy; HTTP standard proposed
- [x] Composer package, PSR-4 autoloading
- [x] PHPUnit, PHPStan (level max), PHP-CS-Fixer
- [x] GitHub Actions workflow
- [x] Smallest testable foundation: `Application` with version and safe `basePath()`
- [ ] CI run green on GitHub (needs a remote repository)

Acceptance criteria:

- [x] Fresh install of dependencies works (`composer install`)
- [x] Test suite executes
- [x] Static analysis and formatting checks run locally; configured in CI
- [x] No Laravel runtime dependency (no runtime dependencies at all)

## Phase 1: Core and dependency injection

Milestones, in order:

1. [x] Add `psr/container`; container implements PSR-11 ([ADR 0005](architecture/adr/0005-container-design.md)). `psr/log` is added with milestone 9.
2. [x] Container: explicit bindings, singletons, constructor autowiring, interface bindings, clear error on unresolvable parameters.
3. [x] Container: circular-dependency detection with the full resolution chain in the message.
4. [x] Container: scoped (per-request) services, `resetScope()`, captive-dependency detection.
5. [x] Config: load PHP array files from `config/`, dot-notation access, typed getters that fail on wrong type ([ADR 0006](architecture/adr/0006-configuration.md)).
6. [x] Environment: own strict `.env` parser and typed `Env` ([ADR 0007](architecture/adr/0007-environment-variables.md)).
7. [x] Service providers: `register()` and `boot()` phases, deterministic order, lifecycle hooks, `ConfigServiceProvider` ([application.md](getting-started/application.md)).
8. [x] Error handling: error-to-exception conversion, uncaught and fatal error handling, safe renderers, CLI exit status ([ADR 0008](architecture/adr/0008-logging-and-error-handling.md)).
9. [x] Logging: PSR-3 `FileLogger`, redaction, log-injection protection, `LoggingServiceProvider` ([ADR 0008](architecture/adr/0008-logging-and-error-handling.md)).

Milestone 9 was implemented before 8, because the error handler logs through PSR-3.

Acceptance criteria:

- [x] Constructor injection works.
- [x] Interface bindings work.
- [x] Circular dependencies fail clearly.
- [x] Configuration and error handling have deterministic tests (including real-process tests for uncaught exceptions and fatal errors).

## Phase 2: HTTP kernel and routing

[ADR 0004](architecture/adr/0004-http-message-standard.md) accepted (PSR-7/15/17 with `nyholm/psr7`); design in [ADR 0009](architecture/adr/0009-http-kernel-and-routing.md).

1. [x] Request creation from globals, response emitter.
2. [x] Router: methods, static and parameter routes, constraints, correct 404 and 405 (with `Allow`), encoding normalization.
3. [x] HTTP kernel with PSR-15 middleware pipeline and exception-to-response mapping (HTML/JSON).
4. [x] Controllers resolved through the container; typed route arguments; JSON responses; `JsonBodyParser`.
5. [x] Named routes, groups, prefixes and URL generation.
6. [x] Minimal PHP view renderer with escaping helper.
7. [x] Starter application `veyrophp-app` with a welcome page at `/` (front controller, `.htaccess` for Apache/XAMPP).

Acceptance criteria:

- [x] A minimal application can serve an HTTP request (verified with `php -S` and XAMPP Apache).
- [x] Route parameters and middleware work.
- [x] 404 and 405 responses are correct.
- [x] Invalid requests and thrown exceptions are handled safely.

Deferred: route caching (after Phase 4 measurements), measuring PSR-7 overhead (Phase 4).

## Phase 3: Database and query builder

Design in [ADR 0010](architecture/adr/0010-database-layer.md); guide in [database/README.md](database/README.md).

1. [x] Connection configuration and lazy PDO connection manager (MySQL, PostgreSQL, SQLite).
2. [x] Prepared statement execution with typed bindings and predictable exceptions.
3. [x] Dialect abstraction: identifier quoting, limits, upserts, savepoints. (`RETURNING` not needed yet; `lastInsertId()` works on all three.)
4. [x] Query builder: select, insert (bulk), upsert, update, delete, joins, nested where, grouping, having, ordering, pagination.
5. [x] Transactions with nested savepoints.
6. [x] SQL injection regression tests; integration tests against SQLite (always), MariaDB 10.4 (local), MySQL 8 and PostgreSQL 16 (CI job added).
7. [x] ULID generator for public identifiers.

Acceptance criteria:

- [x] CRUD operations work against all three databases. *(SQLite and MariaDB run locally; PostgreSQL runs only in the CI job, which has not run yet.)*
- [x] SQL injection regression tests pass.
- [x] Dialect-specific SQL is tested (compilation tests per dialect, plus real databases).
- [x] Connection and transaction failures are handled correctly.

## Phase 4: Query profiler and performance baseline

Design in [ADR 0011](architecture/adr/0011-query-profiling-and-benchmarks.md). Results in [performance/baseline-2026-10-25.md](performance/baseline-2026-10-25.md).

1. [x] Query events: listener hook on `Connection` and `DatabaseManager` with SQL, binding count, duration and connection name.
2. [x] Per-request query count and total time; slow-query threshold reporting (`QueryProfiler`, `ProfileQueriesMiddleware`).
3. [x] Redacted query log (`QueryLogger`, bindings never logged).
4. [x] N+1 detection (same SQL repeated per request), with tests asserting exact query counts.
5. [x] Benchmark suite (`bench/`) with documented hardware and raw results; PSR-7 cost measured in isolation; HTTP benchmark through Apache.
6. [x] Database diagnostics documentation and `Builder::explain()`.

Acceptance criteria:

- [x] Tests verify query count and execution behavior.
- [x] Benchmarks can be repeated on documented hardware and software (`php bench/run.php`, raw JSON committed).
- [x] Performance results include workload details and limitations.

Opcache measurement done on 2026-10-26: 1,327 requests/s (4.2× the opcache-off result). Owner concern: servers with opcache off stay slow. Mitigations: the starter app's welcome page warns when opcache is off (done); the Phase 8 deployment checklist must require opcache; a config cache that reduces files per request will be measured on an opcache-off server before it is built. Open follow-ups (not blocking): consider config cache, container compilation, route regex chunking and statement caching only if profiling shows they matter.

## Phase 5: Cache and ORM

1. [x] Cache (PSR-16) with array, file and optional Redis drivers; TTL, prefixes, remember; stampede and failure behavior defined and tested ([ADR 0012](architecture/adr/0012-cache.md)).
2. [x] Schema builder (create/alter tables per dialect) and migrations with a migrations table, run/rollback/status API ([ADR 0013](architecture/adr/0013-schema-and-migrations.md)).
3. [x] Starter app `users` migration (owner request) and provisional `php veyro migrate` script.
4. [x] Optional ORM on top of the query builder: models, attributes, casts, mass-assignment protection, serialization without hidden queries ([ADR 0014](architecture/adr/0014-orm.md)).
5. [x] Relations with explicit eager loading; lazy loading disabled (reading an unloaded relation throws); N+1 regression tests with exact query counts.
6. [x] Route lookup by `public_id` (ULID) for models (owner request).

Acceptance criteria:

- [x] Cache invalidation behavior is tested.
- [x] ORM operations use the query builder correctly (exact SQL asserted in tests).
- [x] N+1 regression tests demonstrate expected query counts.
- [x] ORM features do not execute unexpected queries (serialization, unchanged saves and invalid public ids run zero queries).

## Phase 6: Security and validation

Milestones:

Design in [ADR 0015](architecture/adr/0015-validation-sessions-auth-rbac.md) and [ADR 0016](architecture/adr/0016-rate-limiting-headers-cors-uploads.md); guides in [security/authentication.md](security/authentication.md) and [security/hardening.md](security/hardening.md).

1. [x] Request validation: rules, validator, consistent 422 error format.
2. [x] Sessions (in the cache, fixation-safe) with secure cookie defaults; CSRF protection (lazy token, 403).
3. [x] Password hashing (PHP password API, 72-byte bcrypt guard, rehash on login).
4. [x] Authentication: ORM user provider, session login/logout, `Authenticate` middleware, timing-safe `attempt()`.
5. [x] RBAC module (owner request): roles, permissions, per-user grants/denials, super-admin, seeder, `can()`, `RequirePermission`/`RequireRole`. (General policies/gates: not yet.)
6. [x] Rate limiting (cache-backed, login throttling, trusted-proxy client IP), CORS, security headers, upload validation and storage. Secure redirects.
7. [x] [Threat model](security/threat-model.md) and the `Security` test suite (`tests/Security`, attacks against a fully wired app).

Acceptance: all suites green, PHPStan level max clean, mutation checks on CSRF, session fixation and the trusted-proxy check, end-to-end check through Apache. Not done: an external review; SSRF (no HTTP client yet).

Local note: XAMPP has `pdo_mysql` and `pdo_sqlite` but not `pdo_pgsql`.

## Phase 7: CLI and starter project

Milestones (confirmed by the owner on 2026-10-26). Design in [ADR 0017](architecture/adr/0017-console.md); guides in [getting-started/console.md](getting-started/console.md) and [getting-started/quickstart.md](getting-started/quickstart.md).

1. [x] Console kernel in the framework: `#[AsCommand]` classes, arguments and options, exit codes 0/1/2, help, "did you mean", tested with in-memory streams.
2. [x] The starter app's `veyro` script now runs the kernel; `migrate`, `migrate:rollback`, `migrate:status` and `rbac:seed` moved into the framework; `user:create` is an app command.
3. [x] Developer commands: `route:list`, `make:migration`, `make:model`, `make:controller`, `serve`.
4. [x] Starter project installable with `composer create-project` (creates `.env`, database, migrations, roles). No app key: nothing in the framework needs one yet (ADR 0017).
5. [x] `VeyroPHP\Testing\TestClient`; starter app feature tests (login, logout, CSRF, throttling, RBAC); quickstart guide, followed step by step on a fresh install.

Acceptance: all suites green, PHPStan level max clean, mutation checks on the production guard, login throttling and the model stub, end-to-end runs of `serve`, `create-project` and the quickstart. Not done: publishing to Packagist (Phase 8).

## Phase 8: Release candidate and 0.1.0

Milestones (draft, to be confirmed with the owner):

1. [x] Public API review: 22 implementation classes marked `@internal`; [backward-compatibility promise](architecture/backward-compatibility.md); package contents checked with `composer archive`; no TODOs or half-finished code found. Open: the name "VeyroPHP" is still provisional (owner).
2. [x] Minimum PHP version decision: owner kept `^8.2` for 0.1.x (2026-10-26); policy for raising it in [ADR 0002](architecture/adr/0002-supported-php-versions.md). 0.2 will require 8.3.
3. [x] Documentation pass: the quickstart was run end to end; every guide is checked automatically by `DocumentationTest` (imports, method names, links); deprecation policy in the backward-compatibility promise. Not done: running every snippet of every guide.
4. [x] Security release checklist: threat model reviewed (T20–T25), `composer audit` clean, security suite extended; [deployment guide](deployment.md). Fixed on the way: HTTPS behind a proxy (`TrustProxies`) and session files never cleaned up (`cache:prune`).
5. [x] Performance re-baseline against the Phase 4 numbers ([report](performance/recheck-2026-10-26.md)): with the optimized autoloader the welcome page is at the Phase 4 level; the apparent 20 % regression was the development autoloader. Large-table behaviour measured with 1,000,000 rows (`bench/scale.php`); `cursorPaginate()`, `chunkById()`, `lazyById()` and split bulk inserts added.
6. [ ] Git history and a remote repository (owner decision: GitHub account, public or private), CI green on GitHub for PHP 8.2–8.4 with MySQL, PostgreSQL and Redis.
7. [ ] Packagist publication of `veyrophp/core` and `veyrophp/app` (owner's account), `composer create-project` verified from Packagist, tag `v0.1.0-rc1`, then `v0.1.0`.

## Requested features (owner, 2026-10-25)

### Public identifiers instead of database IDs in URLs

URLs like `/u/{id}` should not expose auto-increment IDs. Decision: keep the internal integer `id` for joins, and add a `public_id` column holding a ULID (random, not guessable, sortable). URLs use only `public_id`. This is **not** a replacement for authorization checks: every request must still verify that the user may access the record. Encrypted or Hashids-style IDs were rejected because they are reversible with a leaked key, and rotating the key breaks every URL.

- Phase 3: `Ulid` generator and validator.
- Phase 5: ORM support for looking up records by `public_id` in routes.

### Users, roles and permissions (RBAC)

- Phase 5: once migrations exist, the starter app ships a `users` migration (created by `php veyro migrate`, as in Laravel).
- Phase 6: optional RBAC module with migrations for `roles`, `permissions`, `role_permission`, `role_user` and `permission_user` (per-user grants and denials), a seeder for master roles (`super-admin`, `admin`, `user`), `$user->can('posts.edit')` checks and a route middleware for permissions.
- ~~Later (Phase 9): an admin UI for managing roles and permissions, as a starter kit rather than part of the core.~~ Done 2026-10-26 in the starter app (`/admin/access`), with escalation rules; see [authentication.md](security/authentication.md#admin-screens-starter-app).

### Database connection resilience (owner request, 2026-10-25) — done 2026-10-26, [ADR 0018](architecture/adr/0018-connection-resilience.md)

The owner asked what happens when the database is slow or keeps disconnecting. Slow queries are covered (Phase 4: slow-query log, N+1 detection, `explain()`), but connection loss is not handled at all: there is no connect timeout, no reconnect and no retry. Planned:

- Connect and read timeouts configurable per connection, with safe defaults.
- Detect lost connections ("server has gone away", "lost connection", SQLSTATE 08xxx) and reconnect once, **only outside a transaction** and only for the failed statement; inside a transaction the error is raised, because replaying half a transaction could corrupt data.
- Optional limited retry with backoff for connecting (not for writes).
- Tests that kill the connection mid-request (SQLite cannot simulate this; needs MySQL/PostgreSQL).
- Docs: when to use persistent connections and why they are off by default.

### AI integration (owner request, 2026-10-25) — first version done 2026-10-26 as `VeyroPHP\Ai` ([guide](getting-started/ai.md), [ADR 0019](architecture/adr/0019-ai.md)); other providers, tool use and embeddings still open

The owner wants AI features to be as easy to add as in Laravel. Not part of the original plan. Planned for after 0.1.0, as an optional package (`veyrophp/ai`) rather than the core:

- One interface for text generation, chat with history, structured (JSON schema) output and embeddings, with drivers for major providers; API keys only from `.env`.
- Streaming responses, timeouts and retries, cost/token logging without logging prompts by default.
- Testing fakes so applications can test AI features without network calls.
- Security notes: prompt injection, never sending secrets or personal data without consent, output escaping.

### Laravel features not yet in VeyroPHP (owner request, 2026-10-26)

After the feature comparison with Laravel 12, the owner asked to build the missing features one at a time. Order (later ones build on earlier ones):

1. [x] Events (PSR-14), auth events, `make:event` / `make:listener` — [ADR 0020](architecture/adr/0020-events.md), [guide](getting-started/events.md)
2. [x] Encryption (AES-256-GCM, purposes, key rotation, `key:generate`) — [ADR 0021](architecture/adr/0021-encryption.md), [guide](getting-started/encryption.md)
3. [x] Mail (SMTP with STARTTLS/TLS, MIME, views, attachments, log and array transports, `mail:test`, `make:mail`) — [ADR 0022](architecture/adr/0022-mail.md), [guide](getting-started/mail.md); verified against Mailpit with STARTTLS
4. [x] Queue and background jobs (database, Redis, sync; retries, failed jobs, queued listeners and mail, worker commands) — [ADR 0023](architecture/adr/0023-queues.md), [guide](getting-started/queues.md); pcntl timeout and SIGTERM verified on Linux (WSL)
5. [x] Password reset and email verification, signed URLs — [ADR 0024](architecture/adr/0024-password-reset-and-verification.md), [guide](security/authentication.md#forgot-password); a reset ends other sessions (password fingerprint), and `logoutOtherDevices()` does it on request (ADR 0041)
6. [x] Scheduler (cron expressions, time zones, no-overlap locks, `schedule:run`/`list`/`work`) — [ADR 0025](architecture/adr/0025-scheduler.md), [guide](getting-started/scheduling.md)
7. [x] API tokens (Bearer, abilities, expiry, CLI) — [ADR 0026](architecture/adr/0026-api-tokens.md), [guide](security/api-tokens.md)
8. [x] Model factories and seeders, fake data — [ADR 0027](architecture/adr/0027-factories-and-seeders.md), [guide](database/factories-and-seeders.md)
9. [x] File storage (local, S3-compatible, safe downloads, `storage:link`) — [ADR 0028](architecture/adr/0028-file-storage.md), [guide](getting-started/storage.md); `storage:link` verified on Linux; not yet tried against a real S3-compatible server
10. [x] Translation (files, plurals incl. Hindi, SetLocale, translated validation, `lang:missing`) — [ADR 0029](architecture/adr/0029-translation.md), [guide](getting-started/translation.md)
11. [x] Notifications (mail, database, custom channels, queued) — [ADR 0030](architecture/adr/0030-notifications.md), [guide](getting-started/notifications.md)
12. [x] Template layouts, sections, components and stacks — [ADR 0031](architecture/adr/0031-layouts-and-components.md), [guide](getting-started/views.md#layouts-sections-components-and-stacks)

### Features for real applications (owner request, 2026-10-27)

Chosen after the release-readiness review, in this order:

1. [x] Authorization policies and gate, `Authorize` middleware, `make:policy` — [ADR 0040](architecture/adr/0040-authorization-policies-and-gate.md), [guide](security/authentication.md#policies-and-the-gate-record-level-checks)
2. [x] Auth: remember me, log out other devices, framework login throttle (MISC-9) — [ADR 0041](architecture/adr/0041-remember-me-other-devices-login-throttle.md), [guide](security/authentication.md#remember-me)
3. [x] Validation: conditional `required_*`, date comparisons, `digits`, `uuid`, `json` and more; form requests and `make:request` — [ADR 0042](architecture/adr/0042-validation-rules-and-form-requests.md), [guide](security/authentication.md#form-requests)
4. [x] Mail API transports (Mailgun, Amazon SES, Brevo) and the SMS channel (MSG91, Twilio) — [ADR 0043](architecture/adr/0043-mail-api-transports-and-sms.md); not yet tried against real accounts

### Package ecosystem (owner request, 2026-10-26)

1. [x] Package contract: discovery, providers, config merge and publishing, commands, listeners — [ADR 0032](architecture/adr/0032-package-ecosystem.md)
2. [x] Sample third-party package (`acme/veyrophp-greeting`) tested locally and in a clean application
3. [x] Package test kit (`TestApplication`) and compatibility rules
4. [x] Docs: [creating packages](packages/creating-packages.md), [package security](packages/security.md)
5. [x] Security, first milestone: dependency audit (`composer audit` + OSV.dev), JSON reports, policy, CI workflows, clean and vulnerable fixtures — [ADR 0033](architecture/adr/0033-dependency-audit.md)
   - [x] Scheduled rescans with notifications (`--notify`, daily in the starter)
   - [ ] Static suspicious-pattern checks; license policy; isolated inspection
6. [x] First-party packages split from the core: monorepo `packages/`, boundary test, `veyrophp/ai` first — [ADR 0034](architecture/adr/0034-first-party-packages.md)
   - [ ] Further splits only with a reason; candidates (unused by the rest of the core): Notifications, Translation, Filesystem, Scheduling, Rbac
7. [ ] Publication: GitHub repository, release tags, Packagist (owner's accounts) and install from Packagist
8. [x] Developer adoption, first milestone: Laravel and CodeIgniter guides, read-only porting analyzer `port:analyze` — [ADR 0036](architecture/adr/0036-porting-assistant.md)
   - [x] Converters into a new project (Laravel routes, migrations, model skeletons) with TODO(port) notes and conflict checks
   - [x] Controller and Blade converters, public files; a converted page served end to end
   - [ ] Form requests, middleware, Eloquent scopes; CodeIgniter 4 converters
   - [x] Schema additions seen in real projects (longText, enum, timestamp, after, softDeletes, unsigned integers, primary, useCurrent)
   - [ ] Package catalog

## Quality programme (owner, 2026-10-26)

Research problems in PHP and other PHP frameworks, check whether they affect VeyroPHP, and fix the ones it can fix, one verifiable milestone at a time. The research log and the issue registry (every finding with its evidence) are kept outside the public repository; fixes are listed in the CHANGELOG.

1. [x] Baseline and research batch 1: input validation, PDO, request environment, session, PHP versions (VQ-001 to VQ-010)
2. [x] VQ-001 fixed (D modifier, tests first, static guard test); VQ-004 verified, no test needed; VQ-010 withdrawn
3. [ ] Batch 2: transactions, nested transactions and deadlocks on real MySQL/MariaDB and PostgreSQL
4. [ ] Batch 3: queues, cache stampede, worker memory
5. [ ] Batch 4: uploads, streaming, CSRF/CORS edge cases, rate limits, SSRF
6. [ ] Dependency and licence review; reproducible HTTP benchmarks

## Later phases

See the scope document and the engineering plan. Each phase gets its milestone list here when it becomes **Next**.
