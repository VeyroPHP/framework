<p align="center"><img src="resources/branding/veyrophp-logo-dark-480.png" width="260" alt="VeyroPHP logo"></p>

# VeyroPHP

VeyroPHP is an independent PHP framework under early development. It aims for explicit behavior, secure defaults and performance claims backed by reproducible measurements.

> **Status: pre-alpha (Phase 7 complete).** HTTP, routing, database, ORM, security, console and a starter project work, but nothing is published or externally reviewed yet. Do not use it in production. See [docs/ROADMAP.md](docs/ROADMAP.md) for the plan and [CHANGELOG.md](CHANGELOG.md) for what has changed.

The name `VeyroPHP`, the package `veyrophp/core` and the namespace `VeyroPHP\` are provisional and may change before the first public release.

## Requirements

- PHP 8.2 or newer. Tested so far on PHP 8.2 (Windows). The CI workflow also covers 8.3, 8.4 and Linux, but has not run yet.
- Required extensions (Composer checks them): `ctype`, `filter`, `mbstring`, `openssl`
- Optional extensions, needed only for the feature named: `pdo` plus a driver (database), `fileinfo` (upload type detection), `redis` (Redis cache and queue), `simplexml` (S3 disk), `tokenizer` (`view:lint`, porting assistant), `pcntl` (queue job timeouts on Linux/macOS). A missing one gives a clear error when the feature is first used.
- Composer 2

## Development setup

```bash
git clone <repository-url> veyrophp
cd veyrophp
composer install
composer check
```

`composer check` runs the code-style check, static analysis and the test suite.

| Command | What it does |
| --- | --- |
| `composer test` | Runs PHPUnit |
| `composer analyse` | Runs PHPStan at level `max` |
| `composer cs:check` | Shows code-style problems without changing files |
| `composer cs:fix` | Fixes code style |

## What exists today

| Feature | Status |
| --- | --- |
| Composer package, PSR-4 autoloading | Implemented |
| `Application` with version and base path | Implemented |
| Path-traversal-safe `basePath()` helper | Implemented |
| Test suite, static analysis, code style, CI | Implemented |
| Dependency injection container (PSR-11) | Implemented ([docs](docs/getting-started/container.md)) |
| Configuration loader with typed getters, `.env` support | Implemented ([docs](docs/getting-started/configuration.md)) |
| Application lifecycle and service providers | Implemented ([docs](docs/getting-started/application.md)) |
| PSR-3 file logger with redaction, global error handling | Implemented ([docs](docs/getting-started/logging-and-errors.md)) |
| HTTP kernel, PSR-15 middleware, error responses | Implemented ([docs](docs/getting-started/http.md)) |
| Router with constraints, groups, names, URL generation | Implemented ([docs](docs/getting-started/routing.md)) |
| PHP view templates with escaping, layouts, sections, components, stacks | Implemented ([docs](docs/getting-started/views.md)) |
| Starter application with welcome page | Implemented (`../veyrophp-app`) |
| Database connections, query builder, transactions (MySQL, PostgreSQL, SQLite) | Implemented ([docs](docs/database/README.md)) |
| ULID public identifiers | Implemented |
| Query profiler, slow-query log, N+1 detection, `explain()` | Implemented ([docs](docs/database/diagnostics.md)) |
| Benchmark suite and baseline | Implemented ([report](docs/performance/baseline-2026-10-25.md)) |
| Cache (PSR-16: array, file, Redis) with stampede protection | Implemented ([docs](docs/getting-started/cache.md)) |
| Schema builder and migrations | Implemented ([docs](docs/database/migrations.md)) |
| ORM with explicit eager loading and route model binding | Implemented ([docs](docs/database/orm.md)) |
| Validation, sessions, CSRF, login, roles and permissions (RBAC) | Implemented ([docs](docs/security/authentication.md)) |
| Authorization policies and gate (record-level checks, `make:policy`) | Implemented ([docs](docs/security/authentication.md#policies-and-the-gate-record-level-checks)) |
| Remember me, log out other devices, login throttle | Implemented ([docs](docs/security/authentication.md#remember-me)) |
| Form requests; conditional, date, digit, UUID and JSON validation rules | Implemented ([docs](docs/security/authentication.md#form-requests)) |
| Mail over HTTP APIs (Mailgun, Amazon SES, Brevo) and SMS notifications (MSG91, Twilio) | Implemented, not yet tried against real accounts ([mail](docs/getting-started/mail.md#http-api-transports-mailgun-amazon-ses-brevo), [SMS](docs/getting-started/notifications.md#sms)) |
| Rate limiting, CORS, security headers, uploads | Implemented ([docs](docs/security/hardening.md), [threat model](docs/security/threat-model.md)) |
| Console: migrations, `route:list`, `make:*`, `serve`, custom commands | Implemented ([docs](docs/getting-started/console.md)) |
| Coming from Laravel/CodeIgniter: guides and a read-only porting analyzer (`port:analyze`) | Implemented ([docs](docs/migrating/porting-assistant.md)) |
| Dependency security audit: composer audit + OSV.dev, JSON reports, policy, CI | Implemented ([docs](docs/packages/dependency-audit.md)) |
| Package ecosystem: auto-discovery, package service providers, config publishing, test kit | Implemented ([docs](docs/packages/creating-packages.md)) |
| Notifications (mail, database/in-app, custom channels such as SMS; queued) | Implemented ([docs](docs/getting-started/notifications.md)) |
| Translation (PHP/JSON files, plurals incl. Hindi rules, SetLocale, translated validation) | Implemented ([docs](docs/getting-started/translation.md)) |
| File storage (local disks, S3-compatible with own SigV4 signer, safe downloads) | Implemented ([docs](docs/getting-started/storage.md)) |
| Model factories, seeders, fake data | Implemented ([docs](docs/database/factories-and-seeders.md)) |
| API tokens (Bearer, abilities, expiry, CLI) | Implemented ([docs](docs/security/api-tokens.md)) |
| Task scheduler (cron expressions, time zones, no-overlap locks) | Implemented ([docs](docs/getting-started/scheduling.md)) |
| Password reset, email verification, signed URLs | Implemented ([docs](docs/security/authentication.md#forgot-password)) |
| Queues and background jobs (database, Redis, sync; retries, failed jobs, queued listeners and mail) | Implemented ([docs](docs/getting-started/queues.md)) |
| Mail (own SMTP client with STARTTLS/TLS, MIME, views, attachments, log and array mailers) | Implemented ([docs](docs/getting-started/mail.md)) |
| Encryption (AES-256-GCM, purposes, key rotation, `key:generate`) | Implemented ([docs](docs/getting-started/encryption.md)) |
| Events (PSR-14) with lazy listeners, auth events | Implemented ([docs](docs/getting-started/events.md)) |
| AI (Claude via the official SDK; text, chat, JSON, streaming, fakes for tests) | Implemented, separate package `veyrophp/ai` ([docs](docs/getting-started/ai.md)) |
| In-process HTTP test client | Implemented ([docs](docs/getting-started/console.md#testing-http-behavior)) |
| Starter project via `composer create-project` | Implemented, not yet published ([quickstart](docs/getting-started/quickstart.md)) |

## Documentation

- [Quickstart: from zero to a logged-in page](docs/getting-started/quickstart.md)
- [Deploying to production](docs/deployment.md)
- [AI features](docs/getting-started/ai.md)
- [Events](docs/getting-started/events.md)
- [Encryption](docs/getting-started/encryption.md)
- [Mail](docs/getting-started/mail.md)
- [Queues and background jobs](docs/getting-started/queues.md)
- [Scheduled tasks](docs/getting-started/scheduling.md)
- [API tokens](docs/security/api-tokens.md)
- [Factories and seeders](docs/database/factories-and-seeders.md)
- [File storage](docs/getting-started/storage.md)
- [Translation](docs/getting-started/translation.md)
- [Notifications](docs/getting-started/notifications.md)
- [Coming from Laravel](docs/migrating/from-laravel.md), [coming from CodeIgniter](docs/migrating/from-codeigniter.md), [porting assistant](docs/migrating/porting-assistant.md)
- [Creating packages](docs/packages/creating-packages.md), [package security](docs/packages/security.md) and [dependency audit](docs/packages/dependency-audit.md)
- [Using other Composer packages (including Laravel's)](docs/getting-started/packages.md)
- [Backward compatibility promise](docs/architecture/backward-compatibility.md)
- [Security overview](docs/security/overview.md)
- [Architecture overview](docs/architecture/overview.md)
- [Scope and requirements](docs/architecture/scope.md)
- [Architecture decision records](docs/architecture/adr/)
- [Contributing](CONTRIBUTING.md)
- [Security policy](SECURITY.md)

## License

MIT. See [LICENSE](LICENSE).
