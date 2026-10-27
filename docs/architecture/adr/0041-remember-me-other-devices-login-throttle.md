# ADR 0041: Remember me, logging out other devices, and the login throttle

- Status: Accepted
- Date: 2026-10-27

## Context

Three gaps from the feature review:

- Users are logged out when the browser closes; there is no "remember me".
- A user who lost a phone cannot end its session.
- The login limits of VQ-020 lived in the starter's route file, so existing applications never received fixes and the framework did not test them (audit MISC-9).

Sessions already carry a fingerprint of the password hash (VQ-011): a password change ends every session.

## Decisions

### Remember me: selector and validator, hashed, bound to the password

- One row per remembered browser in `remember_tokens` (`RememberSchema`). The cookie is `selector:validator`: 16 and 32 random bytes, base64url.
- The table stores the selector and a SHA-256 of the validator, compared with `hash_equals`. A leaked table logs nobody in, and the lookup by selector does not leak timing about the validator.
- Each row stores the **password fingerprint** of the login. A remembered browser is refused after a password change or reset, exactly like a session, without a separate "delete all tokens" call that a reset path could forget.
- A wrong validator for a known selector, an expired row or an unknown selector deletes the row (if any) and the cookie.
- **Tokens are not rotated on use.** Rotation detects a stolen cookie only when the thief and the user race, and it logs out users whose browser sends two requests at once with the old cookie. The cookie is HttpOnly, `Secure` follows the session cookie, and every remembered login starts a fresh session.
- `Auth::user()` logs a remembered user in when the session has none: new session id, `Login` event. `Auth::attempt(..., remember: true)` issues a token; `logout()` deletes it.
- The `RememberMe` middleware passes the request cookie in and adds the resulting `Set-Cookie`. Like `StartSession` (VQ-046), it renders an error thrown further down itself when it has a cookie to set or remove. Otherwise the 401 from `Authenticate` would lose the deletion, and a bad cookie would be looked up on every request.
- Expired rows are deleted by `auth:prune-remember` (daily in the starter).

### Log out other devices: hash the password again

`Auth::logoutOtherDevices($password)` confirms the password, hashes it again (a new salt gives a new hash), and saves it. Every other session and remembered browser then holds a stale fingerprint and is refused. The current session's fingerprint and this browser's token are updated. It sends `OtherDevicesLoggedOut`.

- Not chosen: a per-user session list. Sessions live in a cache store that may not support listing (Redis keys, files); the fingerprint mechanism already exists and is tested.
- Requiring the password stops someone at an unlocked computer from locking the owner out of the other devices.

### Login throttle in the framework

`LoginThrottle` keeps the two limits of VQ-020 with the same cache keys as the starter, so counters running during an upgrade carry over:

- per identifier and client address, with IPv6 grouped by its /64;
- per identifier across all addresses.

It reads `security.login.*`, with defaults when the keys are missing. The starter now calls it. Identifiers are lower-cased and trimmed, so changing case or adding spaces gives no extra guesses.

## Consequences

- Applications need the `remember_tokens` migration to offer "remember me". Without the `RememberMe` middleware, `attempt(..., remember: true)` still stores a token, but no cookie is set. The guide says to add both.
- `Auth` gains an optional constructor parameter (`?RememberTokens`), and `attempt()` and `login()` gain an optional `$remember`: additive.
- The starter's login route is shorter. The login form has a "remember me" checkbox, and the dashboard has "log out other devices".
