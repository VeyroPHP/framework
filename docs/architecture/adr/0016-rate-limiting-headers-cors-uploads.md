# ADR 0016: Rate limiting, client IP, security headers, CORS and uploads

- Status: Accepted
- Date: 2026-10-26

## Decisions

### Rate limiting

- `RateLimiter` counts attempts in **fixed windows** stored in the PSR-16 cache (`ratelimit.<sha256(key)>`). Keys are hashed, so emails and IPv6 addresses can be used as-is.
- Each increment runs under `Cache::lock()`, which was added for this, so concurrent requests are not lost. ~~If the lock cannot be acquired within the wait time, the increment runs anyway.~~ **Changed 2026-10-27 (VQ-057, approved by the owner):** when the lock cannot be acquired within the wait time, `Cache::lock()` throws `LockTimeoutException` and the callback does not run. `RateLimiter::attempt()` (and so `ThrottleRequests`) then refuses the request; `hit()` throws. Running without the lock let parallel requests past the limit exactly when the counter was busiest. A lock left by a crashed process expires after the lock time (10 s by default), so the refusal is short.
- Fixed windows allow up to 2 × the limit around a window boundary. Sliding windows were rejected for now because they need a sorted set or one entry per request. Limits should be chosen with the 2× in mind.
- **If the cache fails, limits are not enforced**, so a cache outage does not take the site down. This is a deliberate availability-over-strictness choice, listed in the threat model.
- `ThrottleRequests::limit(max, minutes, bucket)` is route middleware, keyed by bucket (or path) and client IP. Over the limit it returns 429 with `Retry-After`; other responses get `X-RateLimit-Limit` and `X-RateLimit-Remaining`.
- **Login throttling is two layers** in the starter app: failed attempts per email + IP (5 per minute, cleared on success), and a per-IP ceiling on the login route (20 per minute). The first slows down guessing one account without locking out other users on the same network. The second stops one IP from trying many emails.
- CSRF runs before route middleware, so requests without a valid token never reach the login check and do not use up the limit.

### Client IP

- `ClientIp` uses `REMOTE_ADDR`. `X-Forwarded-For` is honored **only when the direct peer is a configured trusted proxy** (IPs or CIDR, IPv4 and IPv6). Otherwise any client could choose its own IP and get a fresh rate limit per request; a test sends rotating headers to check this.
- The forwarded chain is read from the right; the first address that is not a trusted proxy is the client. A malformed entry makes the whole header ignored.

### Security headers

- `SecurityHeaders` adds `nosniff`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy`, `Permissions-Policy`, `Cross-Origin-Opener-Policy` and a CSP. The CSP allows only same-origin resources, inline styles (the starter views use them), **no inline scripts**, and `frame-ancestors 'self'`.
- HSTS is off by default and only sent on HTTPS requests. Turning it on for a site that is not fully HTTPS can lock users out, so it is opt-in.
- Headers a handler set are never overridden. Defaults can be replaced or disabled (null) through config.
- **Error responses get the headers too.** The first version only decorated successful responses: a CSRF 403 or a 500 thrown further down passed by the middleware. The new security test suite found this. With an `HttpErrorResponder`, which the provider passes, exceptions are turned into responses inside the middleware.
- `ResponseEmitter` removes PHP's `X-Powered-By` version header unless the application sets one.

### CORS

- Off unless origins are configured. Origins must be exact `scheme://host[:port]` values; `"*"` with credentials is rejected at construction, because browsers forbid it and it usually means a misconfiguration.
- Preflights are answered with 204 without reaching the router. `Vary: Origin` is always added. Errors are converted inside the middleware so browsers can read error responses.
- CORS does not replace CSRF: a credentialed POST from an allowed origin still needs the token (tested).

### Uploads

- Types are detected from content with `finfo`, never from the client's filename or `Content-Type`.
- Validator rules: `file`, `max_size:KB`, `mimes:jpg,png,...` (by detected type) and `image`. A file input left empty arrives as `UPLOAD_ERR_NO_FILE` and counts as empty, so optional uploads use `nullable`.
- `UploadStore` names files with a ULID and the extension of the detected type (`bin` when unknown). Client filenames are never used, which rules out traversal, overwrites and `.php` names. Subdirectories are restricted to `[a-z0-9_-]` segments. The default directory is `storage/uploads`, outside `public/`.

## Not done

- SSRF protection: the framework has no outbound HTTP client yet. It is needed when one is added.
- Image re-encoding (stripping metadata or polyglot content) and virus scanning: application concerns, listed in the threat model.
- Sliding-window or token-bucket rate limiting.
