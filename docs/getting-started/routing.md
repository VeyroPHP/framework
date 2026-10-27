# Routing

Status: **implemented** (Phase 2). Design decisions are in [ADR 0009](../architecture/adr/0009-http-kernel-and-routing.md).

Routes are defined in route files loaded by `HttpServiceProvider`. Each route file receives `$router` and `$app`.

```php
// routes/web.php
use App\Http\UserController;

$router->get('/', fn () => 'Hello');
$router->get('/users/{id:\d+}', [UserController::class, 'show'])->name('users.show');
$router->post('/users', [UserController::class, 'store']);
```

## Methods

`get`, `post`, `put`, `patch`, `delete`, `options`, and `map(['GET', 'POST'], ...)`. HEAD requests are answered by GET routes, without a body.

## Parameters and constraints

| Pattern | Matches |
| --- | --- |
| `/users/{id}` | one segment: `/users/42`, `/users/ravi` |
| `/users/{id:\d+}` | digits only |
| `/posts/{year}` + `->where('year', '\d{4}')` | four digits |
| `/download/{name}.{ext:[a-z]+}` | `/download/report.pdf` |

- Parameter values are URL-decoded: `/search/caf%C3%A9` gives `café`.
- An encoded slash stays in the parameter: `/files/a%2Fb` gives `a/b`.
- Constraints are regular expressions without delimiters or anchors. They are checked against the encoded value.
- Optional parameters are not supported; define a second route.

## Matching rules

1. Static routes (no parameters) are checked first. `/users/me` wins over `/users/{id}` in any order.
2. Routes with parameters are checked in registration order.
3. Trailing slashes matter: `/users/` does not match `/users`.
4. If the path matches but the method does not, the response is **405** with an `Allow` header. Otherwise it is **404**.

## Handlers

```php
$router->get('/a', fn () => 'closure');
$router->get('/b', [UserController::class, 'index']);   // public method
$router->get('/c', ShowDashboard::class);              // class with __invoke()
```

Handlers are validated when the route is defined. Controllers are created by the container, so constructor dependencies are injected.

### Handler arguments

Each parameter of the handler is filled in this order:

1. **Route parameter with the same name.** `string` or untyped gets the string; `int` and `float` get a converted value. A value that is not a valid number gives **404**.
2. **`ServerRequestInterface`**: the current request.
3. **Any other class or interface**: resolved from the container.
4. **Default value**, if any. Otherwise the request fails with a `RoutingException` (500).

```php
public function show(int $id, ServerRequestInterface $request, UserRepository $users): array
```

### Return values

| Return | Response |
| --- | --- |
| `ResponseInterface` | as is |
| `string` | HTML, 200 |
| `array` or `JsonSerializable` | JSON, 200 |
| `null` | 204 No Content |
| anything else | error (500) |

Use `VeyroPHP\Http\ResponseFactory` (injectable) for other status codes and types: `html()`, `text()`, `json()`, `empty()`.

## Names and URL generation

```php
$router->get('/users/{id}', ...)->name('users.show');

$router->path('users.show', ['id' => 5]);                   // "/users/5"
$router->path('users.show', ['id' => 5, 'tab' => 'posts']); // "/users/5?tab=posts"
```

Values are URL-encoded and checked against constraints. Extra parameters become the query string. The returned path does not include the application's base path.

## Groups

```php
$router->group('/admin', function (Router $router): void {
    $router->get('/users', [AdminUserController::class, 'index'])->name('users');   // name: admin.users
}, middleware: [RequireAdmin::class], namePrefix: 'admin.');
```

Groups can be nested. Prefixes and name prefixes are concatenated; middleware runs outer group first, then inner group, then route middleware.

## Errors in definitions

These throw `RoutingException`:

- invalid path, method, constraint or handler (when the route is defined),
- the same method and pattern defined twice, or a name used twice (when the first request is matched),
- adding or changing routes after matching has started.
