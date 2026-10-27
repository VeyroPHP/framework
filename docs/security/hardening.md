# Rate Limiting, Headers, CORS and Uploads

Register the provider after the cache and HTTP providers:

```php
$app->register(new SecurityServiceProvider());
```

All settings live in `config/security.php`; see the starter app for a commented copy. Design: [ADR 0016](../architecture/adr/0016-rate-limiting-headers-cors-uploads.md). Threats covered: [threat-model.md](threat-model.md).

## Rate limiting

Per route, by client IP:

```php
$router->post('/api/search', $handler)->middleware(ThrottleRequests::limit(60));        // 60 per minute
$router->post('/login', $handler)->middleware(ThrottleRequests::limit(20, 1, 'login')); // named bucket
```

Over the limit the response is 429 with `Retry-After`.

**What is counted together:**

- **The route, not the URL as typed:** without a bucket name, the key is the matched route (its name, or its methods and pattern).
  - Encoded spellings (`/l%6Fgin`) and every parameter value count against one limit.
  - For example, `/posts/1`, `/posts/2` and `/posts/01` share the limit of `/posts/{id}`.
  - **Upgrade note:** before 0.1, `/posts/1` and `/posts/2` had separate limits.
- **IPv6 by network:** IPv6 clients are keyed by their first 64 bits, because one client usually controls a whole `/64`. Change this with `security.throttle.ipv6_prefix` (128 keys every address separately).
- **Shared addresses share a limit:** clients behind one IPv4 address (NAT, an office) or in one `/64` share a limit. Size limits for that.

In your own code, use `attempt()`, which checks and counts in one locked step (the starter app's login does this):

```php
$key = 'login:' . mb_strtolower($email) . '|' . ClientIp::forRateLimit($clientIp->of($request));

if (!$limiter->attempt($key, 5, 60)) {
    // tell the user to wait $limiter->availableIn($key) seconds
} elseif ($auth->attempt($email, $password)) {
    $limiter->clear($key);
}
```

**Why `attempt()`:** `tooManyAttempts()` followed by `hit()` lets parallel requests all pass the check before any of them is counted.

**Login in the starter app** uses three limits. The first two come from `VeyroPHP\Auth\LoginThrottle` (ADR 0041), so every application can use them:

| Limit | Default | Stops |
| --- | --- | --- |
| per email + client address | 5 per minute (`security.login.max_attempts`, `decay_seconds`) | guessing one account from one address |
| per account, all addresses | 20 per 15 minutes (`security.login.account_max_attempts`, `account_decay_seconds`) | guessing spread over many addresses (VQ-020) |
| per client address, all emails | 20 per minute (`ThrottleRequests` on the route) | one address trying many accounts |

A successful login clears the first two. The per-account limit has a cost: anyone who knows an email can use it up, and that account cannot log in until the window ends. It does not stop slow guessing below 20 per 15 minutes (about 1,900 a day), so strong passwords still matter.

**Which cache to use:**

- Use a shared cache (file or Redis). The `array` store forgets everything after each request.
- `attempt()` is atomic where the store supports locks (array, file, Redis).
- **Fail closed:** if the lock cannot be acquired within the wait time (3 s by default), `attempt()` refuses the attempt (`ThrottleRequests` answers 429) and `hit()` throws `LockTimeoutException`. Nothing runs without the lock. A lock left by a crashed process expires after 10 s.
- If the cache itself is down, limits are not enforced (ADR 0016); monitor the cache.

## Honeypot fields (form spam)

`Honeypot` rejects form posts that fill a field people never see. Simple bots fill every field.

```php
use VeyroPHP\Security\Honeypot;

$router->post('/contact', $handler)->middleware(
    Honeypot::field('website'),
    ThrottleRequests::limit(5, 1, 'contact'),
);
```

```html
<div hidden aria-hidden="true">
    <label>Leave empty <input name="website" tabindex="-1" autocomplete="off"></label>
</div>
```

- A non-empty value (whitespace does not count) or an array gives **400** before the handler runs, so nothing is validated, stored or mailed. A form without the field passes.
- Name the field like a real one (`website`, `company_url`), not `honeypot`. Field names must match `[A-Za-z_][A-Za-z0-9_-]*`.
- `tabindex="-1"`, `autocomplete="off"` and `aria-hidden` keep keyboard users, password managers and screen readers away from it.
- It stops untargeted bots only. Keep CSRF and rate limiting on the same route.

## Client IP behind a proxy

```php
'trusted_proxies' => ['10.0.0.0/8', '2001:db8::/32'],
```

Only list your own proxies or load balancers. With an empty list, `X-Forwarded-For` is ignored. Each entry must be an IP address or CIDR range without spaces; anything else stops the application at boot with a `ConfigException` naming the entry's position (for example `security.trusted_proxies.1`). Before, a malformed entry silently matched nothing.

The security and session settings are checked at boot. A missing key or `null` means the default; a value of the wrong type (for example `'hsts_seconds' => '31536000'` as a string) is a `ConfigException` naming the key. Messages never show the value. A `same_site` of `none` needs `'secure' => true`.

When HTTPS ends at the proxy, also add `TrustProxies::class` as the first global middleware. It takes the scheme (and port) from `X-Forwarded-Proto`/`X-Forwarded-Port` of trusted proxies, so session cookies keep the `Secure` flag and HSTS is sent. `X-Forwarded-Host` is never used.

## Security headers

Add `SecurityHeaders::class` as one of the outermost global middleware. It also adds headers to error pages.

```php
'headers' => [
    'X-Frame-Options' => 'DENY',                 // replace a default
    'Content-Security-Policy' => null,           // remove a default
    'Cross-Origin-Resource-Policy' => 'same-origin', // add another
],
'hsts_seconds' => 31536000, // only once the whole site is HTTPS
```

The default CSP blocks inline `<script>` and `onclick=` attributes. Put scripts in files under `public/`.

## CORS

Only needed when JavaScript on **another** origin calls your app. Add `Cors::class` first in the global middleware and list the origins:

```php
'cors' => [
    'allowed_origins' => ['https://app.example.com'],
    'allow_credentials' => true, // cookies; not allowed together with '*'
],
```

CORS does not replace CSRF: cookie-authenticated requests still need the token.

## Uploads

```php
$data = $validator->validate($request->getUploadedFiles(), [
    'avatar' => 'required|file|image|mimes:jpg,png,webp|max_size:2048',
    'cv' => 'nullable|file|mimes:pdf|max_size:5120', // optional: an empty input is UPLOAD_ERR_NO_FILE
]);

$path = $uploads->store($data['avatar'], 'avatars'); // "avatars/01JB....png", relative to storage/uploads
```

- Types come from the file content, not the name. A PHP file renamed to `.jpg` fails `image` and `mimes:jpg`.
- Stored names are ULIDs. Save `$path` in the database and serve files through a route that checks access.
- Re-encode images and scan files if other users will download them (see the threat model).
