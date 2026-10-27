# ADR 0015: Validation, sessions, authentication and RBAC

- Status: Accepted
- Date: 2026-10-26

## Decisions

### Validation

- String or array rules (`'required|email|max:255'`), plus `Rule` objects for custom and database checks (`Unique`, `Exists`).
- **Only fields with rules are returned**, so extra input such as `is_admin` never passes through.
- A present but empty value without `nullable` fails. Empty strings never reach code expecting numbers or emails.
- `ValidationException` is a 422 with field errors, rendered as JSON (`errors`) or an escaped HTML list through the new `HasErrorDetails` interface.

### Sessions

- **No native PHP sessions** (`session_start`, `$_SESSION`). Data lives in the PSR-16 cache (file or Redis), and `StartSession` loads and saves it.
- **Session fixation:** malformed or unknown session ids from clients are never adopted; a new id is issued. `regenerate()` on login and `invalidate()` on logout delete the old id's data.
- Cookie: `HttpOnly`, `SameSite=Lax`, `Secure` automatically on HTTPS, `Max-Age` = lifetime. Sessions never written to are not stored and set no cookie.

### CSRF

- Unsafe methods need a token (`_token` field or `X-CSRF-Token` header), compared with `hash_equals`.
- **The token is created lazily**, when a page reads it. Pages without forms create no session. This was found by a test: the first version created a session for every visitor.
- **Failure status is 403, not Laravel's 419.** 419 is not a registered HTTP status, and Apache with mod_php turned it into 500. Unit tests did not show this; the end-to-end check through Apache did.

### Passwords and authentication

- PHP's password API only. bcrypt passwords longer than 72 bytes are **rejected** rather than silently truncated; argon2id is selectable.
- `Auth::attempt()` verifies against a dummy hash made with the configured algorithm and cost when the user does not exist, so timing does not reveal accounts. A test compares both timings.
- Hashes are upgraded on login when the cost or algorithm changes.
- `Authenticate`: 401 for JSON clients, redirect to the login path for browsers.

### RBAC (owner request)

- Tables: `roles`, `permissions`, `permission_role`, `role_user` and `permission_user` (`allowed` true = grant, false = deny).
- Effective permissions = role permissions + user grants − user denials. The `super-admin` role passes every permission check.
- Checks are memoized per request (3 queries per user, whatever the number of checks).
- Route middleware `RequirePermission::for(...)` / `RequireRole::for(...)` gives 401 for guests and 403 without permission. Arguments are passed with the new `ParameterizedMiddleware` (`"Class:arg1,arg2"`).
- `seed()` is idempotent and never deletes anything. Role and permission names are validated.

### Redirects and subdirectory installs

- `ResponseFactory::redirect()` accepts only local paths unless `allowExternal` is set (open-redirect protection).
- The kernel adds the base path to local `Location` headers. Without this, `redirect('/login')` broke subdirectory installs; this was found by the end-to-end check.
- The starter app's root `.htaccess` rewrites every request into `public/`, so URLs have no `/public` segment. The kernel detects this base path. Files outside `public/` cannot be served (verified: 404 without content).

## Not included yet (Phase 6, part 2)

Rate limiting, security headers, CORS, upload validation, threat model, and an admin UI for roles (Phase 9).
