# HTTP Kernel, Middleware and Responses

Status: **implemented** (Phase 2). Design decisions are in [ADR 0004](../architecture/adr/0004-http-message-standard.md) and [ADR 0009](../architecture/adr/0009-http-kernel-and-routing.md).

VeyroPHP uses PSR-7 requests and responses (`nyholm/psr7`) and PSR-15 middleware.

## Request lifecycle

```php
// public/index.php
$app = require dirname(__DIR__) . '/bootstrap/app.php';

$response = $app->container()->make(HttpKernel::class)->handle(ServerRequestFactory::fromGlobals());
(new ResponseEmitter())->emit($response);
```

1. `ServerRequestFactory::fromGlobals()` builds the request. This is the only place that reads `$_SERVER`, `$_GET`, `$_POST`, `$_COOKIE` and `$_FILES`.
2. `HttpKernel::handle()` runs global middleware, matches the route, runs route middleware and calls the handler.
3. `ResponseEmitter::emit()` sends the status line, headers and body. It fails if output has already started.

`handle()` always returns a response; exceptions are converted into error responses.

## Registering the HTTP layer

```php
$app->register(new HttpServiceProvider(
    routeFiles: ['routes/web.php'],
    middleware: [JsonBodyParser::class],   // global, outermost first
));
```

This registers PSR-17 factories, `ResponseFactory`, `Router`, `HandlerInvoker`, `HttpErrorResponder` and `HttpKernel`.

## Middleware

Any PSR-15 `MiddlewareInterface` works. Pass an instance or a class name; class names are resolved from the container.

```php
final class RequireApiKey implements MiddlewareInterface
{
    public function __construct(private ResponseFactory $responses) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getHeaderLine('X-Api-Key') !== 'expected') {
            return $this->responses->json(['error' => 'Unauthorized'], 401);
        }

        return $handler->handle($request);
    }
}

$router->get('/reports', ...)->middleware(RequireApiKey::class);
```

Order: global middleware, then group middleware (outer to inner), then route middleware. The first middleware sees the request first and the response last.

### Built-in middleware

| Middleware | Behavior |
| --- | --- |
| `JsonBodyParser` | For `application/json` and `*+json` bodies, sets the parsed body to an array. Invalid JSON, scalar JSON or nesting deeper than 64 levels gives **400**. Big integers stay strings. |
| `ConditionalGet` | ETag and **304 Not Modified** for GET/HEAD; optional `Cache-Control`. See below. |

### ETags and 304 Not Modified

```php
use VeyroPHP\Http\Middleware\ConditionalGet;

$router->get('/about', ...)->middleware(ConditionalGet::class);              // ETag only
$router->get('/pricing', ...)->middleware(ConditionalGet::cache(300));       // + Cache-Control: private, max-age=300
$router->get('/sitemap.xml', ...)->middleware(ConditionalGet::cache(3600, true)); // + public, max-age=3600
```

- A 200 response to GET or HEAD gets a strong `ETag` from a hash of its body. When the browser sends that tag back in `If-None-Match` (a list, `W/` tags and `*` are understood), the response becomes **304** with an empty body.
- Other statuses, other methods, streamed bodies and bodies over 1 MB are passed through unchanged. An `ETag` or `Cache-Control` set by the handler is kept.
- `cache()` adds `Cache-Control` only to 200 responses. A response that sets a cookie gets `private, no-cache` instead, so a shared cache never keeps a personal page. This includes the session cookie: `StartSession` adds it after the route has run, and turns `public` or `s-maxage` into `private, no-cache` itself (`private, no-store` if `no-store` was set). Use `public` only for pages that look the same for everyone. Visitors without a session still get the public header.
- The handler still runs; the saving is bandwidth and browser work. Do not use it to hide slow pages.

## Sitemaps

`VeyroPHP\Http\Sitemap` builds a `sitemap.xml` with correct escaping:

```php
use VeyroPHP\Http\Sitemap;

$router->get('/sitemap.xml', static function (ResponseFactory $responses, Config $config) {
    $base = rtrim((string) $config->get('app.url'), '/');

    $xml = (new Sitemap())
        ->add($base . '/', new DateTimeImmutable('2026-10-26'), 'weekly', 1.0)
        ->add($base . '/contact', changeFrequency: 'yearly')
        ->toXml();

    return $responses->text($xml, 200, ['Content-Type' => 'application/xml; charset=utf-8']);
})->middleware(ConditionalGet::cache(3600, true));
```

Locations must be absolute `http`/`https` URLs without control characters; a relative URL, `javascript:`, an unknown change frequency or a priority outside 0–1 throws `InvalidArgumentException`. One file holds at most `Sitemap::MAX_URLS` (50,000) URLs; more throws `LengthException`. In views, `$urls->absolute()` builds the same URLs from `APP_URL`.

## Single-page apps (SPA)

Serve a built front end (React, Vue, Svelte) from `public/` and let one route return its `index.html` for every client-side path. Keep the API under its own prefix so unknown API paths stay **404** JSON instead of returning HTML.

```php
// routes/web.php: API routes first
$router->group('/api', static function (Router $router): void {
    $router->get('/users', [UserController::class, 'index']);
});

// Then the catch-all. Matches "/", "/dashboard" and "/users/5", not "/api/...".
$router->get('/{path:(?!api/).*}', static function (ResponseFactory $responses, Application $app) {
    $html = file_get_contents($app->basePath('public/app/index.html'));

    return $responses->html($html === false ? '' : $html, $html === false ? 500 : 200, ['Cache-Control' => 'no-cache']);
})->name('spa');
```

- Build the front end into `public/app/` and link its files with paths relative to `/app/`. The web server serves those files directly; only unknown paths reach PHP.
- Send `index.html` with `html()` and `no-cache`, so a new deploy is picked up at once; the hashed JS and CSS files can be cached for a year (see [static asset caching](../deployment.md#static-assets-and-caching)). Do not use `FileResponse` for it: it refuses to show HTML inline and sends it as a download.
- The default Content-Security-Policy allows scripts from the same origin only. Bundles served from `public/` work; inline `<script>` blocks and scripts from a CDN need a changed policy (`security.headers` → `Content-Security-Policy`).
- For API calls from the same origin, session cookies and CSRF work as for forms: put the token (`$request->getAttribute('csrf_token')`) into the page, for example by rendering `index.html` as a view, and send it in the `X-CSRF-Token` header. A separate origin needs CORS and API tokens instead (see [hardening](../security/hardening.md#cors)).
- A route group such as `/api` is matched before the catch-all only if it is registered first, because routes with parameters are checked in registration order.

## Request attributes

Route parameters are available as request attributes (`$request->getAttribute('id')`), and the matched `Route` is under `HttpKernel::ROUTE_ATTRIBUTE`.

## Errors

| Thrown | Response | Logged |
| --- | --- | --- |
| `NotFoundHttpException` / no route | 404 | no |
| wrong method | 405 + `Allow` | no |
| `HttpException($status, $message, $headers)` | that status, message and headers | only 5xx, at `error` |
| any other exception | 500 | yes, at `critical`, with method and path |

- Clients that prefer JSON (`Accept: application/json`) get `{"error":{"status":404,"message":"Not Found"}}`. Others get a small HTML page.
- `HttpException` messages are shown to clients. Do not put internal details in them.
- With `app.debug` true, 500 responses include the exception class, message and trace (HTML-escaped). With debug off, they show only "Internal Server Error".

## Base path (subdirectory installs)

When the app is served from a subdirectory, such as `http://localhost/veyrophp-app/public/` under XAMPP, the kernel removes that prefix before routing. It is detected from `SCRIPT_NAME`, so `/veyrophp-app/public/users` and `/veyrophp-app/public/index.php/users` both route as `/users`. Pass `basePath` to `HttpKernel` to set it explicitly.

## Not yet included

Sessions, cookies helpers, CSRF, CORS, rate limiting, redirects with open-redirect protection and file uploads are planned for Phase 6.
