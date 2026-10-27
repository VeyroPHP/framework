# Security Overview

This page lists the security-relevant behavior that exists **today**, and what applications must do themselves. It is updated as modules are added. Threats, mitigations and residual risks are in the [threat model](threat-model.md).

## Provided by the framework (implemented)

| Area | Protection | Where |
| --- | --- | --- |
| Paths | `Application::basePath()` rejects absolute paths, `..`, null bytes and stream wrappers | [application.md](../getting-started/application.md) |
| Config errors | Messages contain keys and types, never values | [configuration.md](../getting-started/configuration.md) |
| `.env` errors | Messages contain names and line numbers, never values | [configuration.md](../getting-started/configuration.md) |
| Global state | `.env` values are not copied to `putenv()`, `$_ENV` or `$_SERVER` | ADR 0007 |
| Log redaction | Sensitive context keys are replaced with `[REDACTED]` | [logging-and-errors.md](../getting-started/logging-and-errors.md) |
| Log injection | Line breaks in log messages are escaped | ADR 0008 |
| Stack traces | Never include function arguments | ADR 0008 |
| Error pages | Without debug: no message, class, path or trace. With debug: HTML-escaped | ADR 0008 |
| Exit codes | CLI scripts that crash exit with status 255 | ADR 0008 |
| HTTP errors | 500 responses hide details unless `app.debug`; 4xx messages come only from `HttpException` | [http.md](../getting-started/http.md) |
| Route matching | Encoded slashes and `..` cannot escape a parameter segment | ADR 0009 |
| Header injection | Header values with CR/LF are rejected by the PSR-7 implementation | ADR 0004 |
| JSON bodies | Invalid or too deeply nested JSON is rejected with 400 | [http.md](../getting-started/http.md) |
| Views | `$e()` escaping helper; template names cannot traverse directories | [views.md](../getting-started/views.md) |
| Starter app | Root `.htaccess` denies access to `.env`, `config/`, `vendor/`; only `public/` is served | ADR 0009 |
| SQL injection | Values are always bound; identifiers are validated; operators and sort directions are whitelisted | [database](../database/README.md) |
| Accidental mass writes | `update()`/`delete()` without where throw | ADR 0010 |
| DB secrets | Password hidden from dumps and traces; DSN injection rejected; query errors never include bound values | ADR 0010 |
| Enumerable ids | ULID generator for public ids in URLs (authorization still required) | ADR 0010 |
| Query logs | Query events and logs never contain bound values; `X-Query-*` headers are off by default | ADR 0011 |
| Cache deserialization | `unserialize()` restores no classes unless listed (`cache.allowed_classes`), preventing object injection | ADR 0012 |
| Shared cache backends | `clear()` only removes its prefix; Redis store refuses an empty prefix and never flushes the database | ADR 0012 |
| DDL defaults | Escaped literals (MySQL backslashes included), null bytes rejected, identifiers validated | ADR 0013 |
| Production migrations | Starter-app `migrate` refuses to change a production database without `--force` | ADR 0013 |
| Mass assignment | `fill()` throws for attributes outside `FILLABLE` | ADR 0014 |
| Data exposure | `HIDDEN` attributes (e.g. password) never appear in `toArray()`/JSON | ADR 0014 |
| Enumeration via URLs | Route model binding uses ULID public ids; malformed ids are 404 without a query | ADR 0014 |
| Input validation | Only validated fields are returned; empty values need `nullable` | ADR 0015 |
| CSRF | Token required for unsafe methods, `hash_equals`, 403 on failure | ADR 0015 |
| Session fixation | Unknown client session ids are never adopted; id regenerated on login, invalidated on logout | ADR 0015 |
| Cookies | HttpOnly, SameSite=Lax, Secure on HTTPS | ADR 0015 |
| Passwords | PHP password API; bcrypt 72-byte truncation prevented; automatic rehash | ADR 0015 |
| Account enumeration | Same response and comparable timing for unknown email and wrong password | ADR 0015 |
| Authorization | RBAC with per-user denials; 401/403 middleware | ADR 0015 |
| Open redirects | `redirect()` allows only local paths by default | ADR 0015 |
| Brute force | Cache-backed `RateLimiter`, `ThrottleRequests` (429 + `Retry-After`); `LoginThrottle` per email + IP and per email, plus a per-IP route limit in the starter | ADR 0016, ADR 0041 |
| IP spoofing | `X-Forwarded-For` only honored from configured trusted proxies | ADR 0016 |
| Browser hardening | `SecurityHeaders`: nosniff, frame options, referrer and permissions policy, COOP, CSP without inline scripts, opt-in HSTS; also on error pages; no `X-Powered-By` | ADR 0016 |
| CORS | Off by default; exact origins; `*` with credentials rejected; `Vary: Origin` | ADR 0016 |
| Uploads | Type detected from content; `file`/`image`/`mimes`/`max_size` rules; ULID file names outside `public/` | ADR 0016 |

## Application responsibilities

- Keep `app.debug` false in production.
- Treat `config/*.php` and `.env` as trusted files; anyone who can edit them can run code or change behavior.
- Do not put secrets directly into log message text. Pass them in context under a descriptive key, or not at all.
- Rotate and protect log files (permissions, retention).
- Keep dependencies updated; CI runs `composer audit`.
- Escape every value in templates with `$e()`; output is not escaped automatically.
- Never build `raw()` / `whereRaw()` SQL strings from user input. Check authorization for every record, even when URLs use ULIDs.
- Point the web server's document root at `public/`, or keep the starter app's `.htaccess` files. Set `expose_php=Off` in production.

- Set `security.trusted_proxies` only to your own proxies; enable HSTS once the site is HTTPS-only.
- Re-encode and scan uploads that other users can download; serve uploads through an access-checking route.

## Not yet provided

- SSRF protection: there is no outbound HTTP client yet.
- Sliding-window rate limiting, CAPTCHA, two-factor authentication.
- An external security review.

See the residual risks in the [threat model](threat-model.md).
