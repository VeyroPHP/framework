# API tokens

Mobile apps, scripts and other servers cannot use the login form and its session cookie. They send a personal API token instead:

```http
GET /api/me
Authorization: Bearer vyr_12_Q2xhdWRlIHdhcyBoZXJlIGJ1dCB0aGlzIGlzIG5vdCBh
```

Design: [ADR 0026](../architecture/adr/0026-api-tokens.md). Comparable to Laravel Sanctum's API tokens.

## Setup

- A migration with `ApiTokenSchema::up($schema)`. The starter app has it.
- API routes under `/api` with `AuthenticateToken` (the starter's `routes/api.php`).
- `/api/*` in `session.csrf_except`. API requests carry no CSRF token. They are safe because they authenticate with the header, which other websites cannot send for your users.

**Never use the session-based `Authenticate` on routes excepted from CSRF.** A cookie-authenticated route without CSRF protection can be triggered by any website the user visits.

## Creating tokens

From the command line:

```bash
php veyro token:create asha@example.com "Delivery app" --abilities=orders.read,orders.write --days=90
php veyro token:list asha@example.com
php veyro token:revoke asha@example.com 12      # or: all
```

In code, for example on a "create token" page:

```php
$new = $tokens->create($user, 'Delivery app', ['orders.read'], expiresAt: time() + 90 * 86400);
echo $new->plainText;   // show once: only its hash is stored
```

`ApiTokens` also has `tokensFor($user)`, `revoke($user, $id)`, `revokeAll($user)` and `pruneExpired()` (`php veyro token:prune`; the starter schedules it daily).

## Protecting routes

```php
$router->group('/api', static function (Router $router): void {
    $router->get('/orders', [OrderApi::class, 'index'])->middleware(RequireAbility::for('orders.read'));
    $router->post('/orders', [OrderApi::class, 'store'])->middleware(RequireAbility::for('orders.write'));
}, [AuthenticateToken::class, ThrottleRequests::limit(60, 1, 'api')]);
```

- `AuthenticateToken` makes the token's user the current user (`Auth::user()`) for this request only, without a session. It answers 401 with `WWW-Authenticate: Bearer` for missing, wrong, expired or revoked tokens.
- `RequireAbility::for('a', 'b')` answers 403 unless the token has all the listed abilities. A token with `*` has every ability. The token is available as the request attribute `AuthenticateToken::ATTRIBUTE`.
- Abilities only **limit** a token. They never give the user more than their roles allow, so keep RBAC checks (`RequirePermission`) where the role matters.

## Security

- Tokens look like `vyr_<id>_<secret>`. The 32-byte secret is stored only as SHA-256 and compared in constant time, so a copy of the database contains no usable tokens.
- The `vyr_` prefix lets secret scanners recognise tokens committed to code by mistake. Revoke a leaked token at once.
- Give each client its own token with the fewest abilities and an expiry, so one can be revoked without breaking the others.
- Consider revoking all tokens when a user resets their password (listen to `PasswordReset` and call `revokeAll()`).
- Use HTTPS: a token is a password.
- `last_used_at` is updated at most once a minute per token; use it to find unused tokens.
- **Apache with PHP-FPM or CGI drops the `Authorization` header.** The starter's `public/.htaccess` passes it on. Nginx passes it by default.
