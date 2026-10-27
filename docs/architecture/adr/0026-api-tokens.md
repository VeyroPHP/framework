# ADR 0026: API tokens

- Status: Accepted
- Date: 2026-10-26

## Context

Laravel parity item 7. APIs need authentication without cookies; Laravel Sanctum stores `id|token` pairs with a SHA-256 hash and offers token abilities.

## Decisions

- **Token format `vyr_<id>_<secret>`:**
  - The prefix makes leaked tokens recognisable to secret scanners. Sanctum added prefixes later as an option; here it is the default.
  - The id finds the row by primary key, and the secret (32 random bytes) is compared as SHA-256 with `hash_equals`. A slow hash is pointless for 256-bit random secrets.
- **Abilities are restrictions, not permissions.** Exact names or `*`, and no wildcards (`orders.*`), so a token's reach is visible in its list. RBAC still applies to the user.
- **Request-only login:**
  - `Auth::loginForRequest()` sets the user without writing the session or dispatching `Login`.
  - A token never creates a session, and session routes ignore tokens.
  - API routes are excepted from CSRF by path; the guide forbids session authentication there.
- **`last_used_at` is written at most once a minute**, so a busy API does not write on every request.
- **Errors:** 401 with `WWW-Authenticate: Bearer` for every authentication failure, so callers cannot tell a wrong token from an expired one. 403 for a missing ability.
- **Commands** `token:create`, `token:list` (never secrets), `token:revoke`, `token:prune`.
- Not done:
  - a self-service token page in the starter app
  - SPA cookie authentication (Sanctum's stateful mode)
  - OAuth2

## Verified

- `ApiTokensTest` (9 tests):
  - the format, and that only the hash is stored
  - lookup
  - six kinds of wrong token, expiry, pruning and revocation
  - users only managing their own tokens
  - the once-a-minute `last_used_at`
  - invalid names, abilities and expiries
  - over HTTP: 401 with `WWW-Authenticate`, the current user from the token, POST without CSRF under `/api`, 403 for a missing ability, `*`
  - no session created, session pages ignoring tokens, a deleted user getting 401
  - all four commands
- A starter feature test covers `/api/me`. The same endpoint was called through Apache with a real token (200, and 401 without it).

Found while building: Apache drops the `Authorization` header under PHP-FPM/CGI. The starter's `.htaccess` now passes it on (mod_php on XAMPP passes it either way).
