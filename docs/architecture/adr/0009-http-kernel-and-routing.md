# ADR 0009: HTTP kernel and routing

- Status: Accepted
- Date: 2026-10-25

## Context

Phase 2 needs request handling, routing, middleware, controllers and error responses. The plan asks for explicit behavior, correct 404/405 handling, safe error responses, and tests for encoded paths, trailing slashes and methods.

## Decisions

### Routing

1. **Syntax:** `{name}` matches one path segment; `{name:regex}` or `->where()` sets a constraint. No optional parameters; define two routes instead.
2. **Static routes win over routes with parameters**, regardless of registration order. Routes with parameters match in registration order. This makes `/users/me` and `/users/{id}` work without careful ordering.
3. **Percent-encoding is normalized (RFC 3986 section 6.2.2) before matching.** Routes are matched against the encoded path, and parameter values are decoded once afterwards. So `%2F` stays inside one parameter and `/files/..%2Fetc` cannot escape its segment. Constraints are checked against the encoded value, both when matching and when generating URLs.
4. **Trailing slashes are significant.** `/users/` does not match `/users`. Redirect behavior, if wanted, belongs in a middleware.
5. **HEAD is served by GET routes**, and the kernel strips the body.
6. **405 includes `Allow`**, built from every route whose path matches, plus HEAD when GET is allowed.
7. **Definitions are validated:** paths, methods, constraints and handlers are checked at registration; duplicate method + pattern pairs (ignoring parameter names) and duplicate names are checked when matching starts. After that the router is frozen.
8. **No route cache yet.** The plan allows it only with defined invalidation; it will be considered after Phase 4 measurements.

### Handlers

- Accepted: closures, `[Controller::class, 'method']` and invokable classes. Controllers are built by the container.
- Arguments: route parameters by name (with strict `int`/`float` conversion; an invalid number gives 404), `ServerRequestInterface`, container services, then default values. Anything else is a `RoutingException` (500).
- Return values: response, string (HTML), array or `JsonSerializable` (JSON), `null` (204). Other types are an error rather than being guessed.

### Kernel

- PSR-15 middleware pipeline: global middleware first, then group middleware, then route middleware.
- Every exception becomes a response through `HttpErrorResponder`. `HttpException` messages are shown to clients; other exceptions give a generic 500 unless debug is on. JSON is returned when `Accept` prefers it.
- Scoped container services are reset after every request.
- **Base path:** the prefix of a subdirectory installation (XAMPP: `/myapp/public`) is detected from `SCRIPT_NAME` and removed before routing, including the `/index.php/...` form.

### Views

- Plain PHP templates with an explicit `$e()` escaping helper and `$view` for partials. There is no template language yet; output is not escaped automatically.
- Template names are restricted to `[A-Za-z0-9_-]` segments separated by dots, so names cannot traverse directories.

### Starter application

- Lives in a separate directory (`veyrophp-app`) and uses a Composer path repository with a symlink. Keeping it inside the framework repository would make the symlink point at its own parent, which creates a directory loop.
- A root `.htaccess` denies everything; `public/.htaccess` re-allows only the front controller and static files. This protects `.env`, `config/` and `vendor/` when the project sits inside a web root.

## Consequences

- Routing behavior is predictable and documented, with tests for edge cases.
- Error responses produced by the kernel do not pass back through global middleware, so headers added by middleware (for example CORS) are missing on error responses. This will be revisited with CORS in Phase 6.
- Applications that rely on trailing-slash leniency must add their own redirect.
