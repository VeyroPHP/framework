# Threat Model

Scope: the VeyroPHP framework (0.1.x) and the starter app `veyrophp-app`, as deployed on a single PHP server (Apache/nginx + PHP-FPM or mod_php) with a SQL database and a file or Redis cache. Tests for the listed attacks are in `tests/Security` and the unit suites named below.

Last reviewed: 2026-10-26 (Phase 8 release review; console and starter project added).

## Assets

| Asset | Why it matters |
| --- | --- |
| User accounts and password hashes | Account takeover; reused passwords elsewhere |
| Session ids and CSRF tokens | Acting as a logged-in user |
| Roles and permissions | Privilege escalation |
| Application data in the database | Confidentiality and integrity |
| Secrets: `.env`, database credentials, `config/` | Full compromise |
| Uploaded files | Code execution if executed, malware hosting |
| Logs | Contain IPs, emails and errors |
| Availability | Login and pages must stay usable |

## Trust boundaries and entry points

1. **Browser → web server → `public/index.php`**: everything in the request is untrusted: path, query, body, cookies, headers (including `Host`, `Origin`, `X-Forwarded-For`), uploaded files.
2. **Reverse proxy → app**: `X-Forwarded-*` is trusted only from configured proxies.
3. **App → database / cache**: trusted services, but values from users must never become SQL or serialized objects.
4. **Filesystem**: `config/`, `.env`, `vendor/`, `storage/` must not be served.
5. **CLI** (`php veyro`): trusted operator; runs with full access. Arguments can still contain mistakes or copied text, so generators validate them.
6. **PHP runtime**: the interpreter itself is part of the attack surface; its security releases must be applied.

## Threats and mitigations

| # | Threat | Mitigation | Verified by |
| --- | --- | --- | --- |
| T1 | SQL injection | Bound values everywhere; identifiers validated; operators whitelisted | Database unit tests |
| T2 | XSS | `$e()` escaping in views; CSP without inline scripts; `nosniff` | View tests, `SecurityHeadersTest` |
| T3 | CSRF | Token for unsafe methods, `hash_equals`, `SameSite=Lax` cookies | `SessionTest`, `AttackScenariosTest` |
| T4 | Session fixation / theft | Client-chosen ids never adopted; regenerate on login; HttpOnly, Secure on HTTPS | `SessionTest` |
| T5 | Password brute force / credential stuffing | Per email + IP failure limit (5/min); per-IP route ceiling (20/min); bcrypt/argon2id | `ThrottleRequestsTest`, `AttackScenariosTest`, Apache check |
| T6 | Rate-limit bypass with fake `X-Forwarded-For` | Header used only from trusted proxies; read right to left | `ClientIpTest`, `AttackScenariosTest` |
| T7 | Account enumeration | Same message and comparable timing for unknown email and wrong password | `AuthAndRbacTest` |
| T8 | Privilege escalation | RBAC middleware, per-user denials; mass assignment blocked by `FILLABLE` | RBAC and ORM tests |
| T9 | IDOR / id enumeration | ULID public ids in URLs. **Ownership checks are still the app's job.** | ORM binding tests |
| T10 | Clickjacking | `X-Frame-Options: SAMEORIGIN`, CSP `frame-ancestors 'self'` | `SecurityHeadersTest` |
| T11 | Cross-origin data theft | CORS off by default; exact origins; no `*` with credentials; `Vary: Origin` | `CorsTest` |
| T12 | Malicious upload (web shell, traversal, overwrite) | Type from content; ULID names; detected extension; dir outside `public/` | `UploadsTest` |
| T13 | Open redirect | `redirect()` allows local paths only by default | `ResponseFactory` tests |
| T14 | Information disclosure in errors | No details without debug; HTML-escaped with debug; no `X-Powered-By` | `HttpKernelTest`, `AttackScenariosTest` |
| T15 | Secret files served | Only `public/` is served; root `.htaccess` denies the rest | Apache check (404 for `.env`, `config/`, `vendor/`) |
| T16 | Object injection via cache | `unserialize()` with an allow-list of classes | Cache tests |
| T17 | Log injection / secrets in logs | Line breaks escaped; sensitive keys redacted; no bound SQL values | Logging tests |
| T18 | Header injection | PSR-7 rejects CR/LF; `SecurityHeaders` rejects them in config | `SecurityHeadersTest` |
| T19 | Path traversal (views, base path, uploads) | Validated names and paths | Application, View, Upload tests |
| T20 | Generators writing outside the project or overwriting code (`make:*`) | StudlyCase / snake_case names only; files opened with mode `x`, never overwritten | `DeveloperCommandsTest` |
| T21 | Development server exposed to the network (`serve`) | Binds to 127.0.0.1 by default; warning for 0.0.0.0; `.php` files other than `index.php` never served directly; no shell (array command) | `DeveloperCommandsTest`, manual check |
| T22 | Secrets in shell history (`user:create`) | Passwords generated or read from standard input, never from arguments | Starter app command |
| T23 | Tests touching a real database | Starter tests force `DB_DATABASE=:memory:` and assert it before migrating | Starter `TestCase` |
| T32 | Path traversal through stored file names; uploaded HTML/SVG served as a page (stored XSS); private files reachable by URL; storage credentials leaking | Paths validated before use (no `..`, backslashes, drive letters, control characters); `FileResponse` with `nosniff`, safe `Content-Disposition`, no inline HTML/SVG; private `local` disk served only through permission-checked routes; presigned URLs expire; credentials never in messages | `LocalDiskTest`, `S3DiskTest`, `S3SignerTest`, [storage guide](../getting-started/storage.md) |
| T31 | Stolen or leaked API tokens; tokens from a database copy; API routes without CSRF reached with a session cookie; tokens doing more than intended | Secrets stored as SHA-256, constant-time compare, `vyr_` prefix for secret scanners, expiry, revocation, abilities that only restrict; tokens never create sessions; guide forbids session auth on CSRF-excepted routes; uniform 401 | `ApiTokensTest`, starter `ApiTokenTest`, [API tokens guide](api-tokens.md) |
| T30 | Account takeover through password reset: guessed, leaked (database copy) or replayed tokens; reset links revealing which emails have accounts; email verification links used for another account; tampered signed links | 256-bit random tokens stored as SHA-256, constant-time compare, single use, 60-minute expiry, one link per minute per account, same answer for unknown accounts, per-IP throttling; verification links signed (HKDF key from APP_KEY), bound to the logged-in user and the current address | `PasswordResetAndVerificationTest`, `UrlSignerTest`, starter `PasswordResetTest`, [authentication guide](authentication.md#forgot-password) |
| T29 | Code execution through queue payloads (object injection) by someone who can write to the jobs table or Redis; secrets in payloads or failed-job records; a job taken by two workers | JSON payloads restored only through constructors of `Job` classes (no `serialize()`), type-tagged values, models by key; anonymous classes and objects rejected; atomic claims with reservation tokens; guide tells to pass ids, not secrets | `QueueTest`, `DatabaseQueueTestCase` (multi-process), `RedisQueueTest`, [queues guide](../getting-started/queues.md#security) |
| T28 | Mail header injection through form input; SMTP credentials or mail sent in plain text; downgrade by a server that hides STARTTLS; personal data in logs; tests sending real mail | Line breaks rejected in every address, name, subject, header and attachment name; reserved headers cannot be overridden; certificate verification on; no fallback when STARTTLS is missing; credentials only over TLS or to localhost; logs without addresses or content; starter tests force the `array` mailer | `MessageTest`, `SmtpTransportTest`, `MailerTest`, [mail guide](../getting-started/mail.md#security) |
| T27 | Tampered or forged tokens; encrypted values replayed in another feature; leaked `APP_KEY` | AES-256-GCM (authenticated), purpose bound as associated data, JSON instead of `serialize()` (no object injection even with a leaked key), rotation with `APP_PREVIOUS_KEYS`, key never in messages | `EncrypterTest`, `KeyGenerateTest`, [encryption guide](../getting-started/encryption.md) |
| T26 | Prompt injection and misuse of AI output; personal data sent to an AI provider; API costs | Instructions in `system`; guide rules (output is untrusted, permissions in code, escape output, rate-limit AI routes); prompts and answers never logged; key and prompt never in error messages | `AiTest`, `AnthropicProviderTest`, [AI guide](../getting-started/ai.md#security) |
| T25 | Disk exhaustion from sessions that are never read again (every new visitor to a form page leaves one) | `FileStore::prune()` / `php veyro cache:prune` from cron; Redis expires entries itself | `PruneTest`, `FrameworkCommandsTest` |
| T24 | Vulnerabilities in PHP itself (for example PHP2Shell, CVE-2026-109618/109619, which needs no application bug) | Not fixable in framework code: run a patched PHP (8.2.35, 8.3.36, 8.4.27, 8.5.12 or later) | Deployment guide checklist |

## Residual risks (accepted or open)

- **Rate limits fail open.** If the cache is down, no limits apply, so the site keeps working. A cache outage plus an attack at the same time allows faster guessing.
- **Fixed windows** allow up to 2 × the limit across a window boundary.
- **Distributed brute force** from many IPs is only slowed by the per-account limit. There is no global per-account lockout or CAPTCHA, because that would let anyone lock out any user.
- **Uploads:** a valid image can still carry a polyglot payload or metadata (location data). Re-encode images and scan files if users can download each other's uploads. Serve them with `Content-Disposition: attachment` or from a separate domain.
- **HSTS is off by default.** Enable it once the site is HTTPS-only.
- **The CSP allows inline styles** because the starter views use them.
- **SSRF:** not applicable yet; there is no outbound HTTP client. It must be addressed when one is added.
- **Denial of service** by large bodies or slow clients is left to the web server (`LimitRequestBody`, `client_max_body_size`, timeouts).
- **Session flooding:** a client can create many sessions by requesting form pages without cookies. Pruning removes them only after their lifetime (`session.lifetime`, default 120 minutes), so a determined flood can still use disk space for that long. Rate limiting form pages per IP, or the Redis store with a memory limit, reduces this.
- **Authorization of individual records** (does this user own this order?) is always application code.
- **Dependencies:** `composer audit` found no advisories for the framework or the starter app on 2026-10-26; CI, which runs it automatically, has not run on GitHub yet.
- **PHP runtime:** the framework cannot detect or block attacks on the interpreter. Operators must apply PHP security releases; the development machine (XAMPP PHP 8.2.12) was itself unpatched for PHP2Shell at the time of this review.
- **Not reviewed by a third party.** This model is the authors' own assessment.

## Deployment checklist (security)

The full guide is [deployment.md](../deployment.md).

- PHP at a patched release of a supported branch (not only a supported branch).
- `APP_DEBUG=false`, `expose_php=Off`, document root at `public/`.
- HTTPS everywhere, then set `security.hsts_seconds`.
- Set `security.trusted_proxies` only if behind a proxy or load balancer, and only to its addresses.
- Keep `storage/` outside the web root and writable only by PHP.
- Use the Redis or file cache (not `array`) so sessions and rate limits survive between requests.
