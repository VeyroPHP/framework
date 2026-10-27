# Coming from Laravel

VeyroPHP keeps most of Laravel's ideas, such as routes files, controllers with dependency injection, migrations, an ORM, a console tool, queues, mail, notifications and a scheduler. It differs where explicitness or security was preferred over magic: there are no facades, no global helpers, and the ORM is an explicit service, not active-record statics. This page maps each concept. For an existing project, start with the [analyzer](porting-assistant.md): it lists what maps directly, what needs a manual rewrite and what has no equivalent yet.

## At a glance

| Laravel | VeyroPHP | Notes |
| --- | --- | --- |
| `php artisan` | `php veyro` | `veyro list`, `veyro help <command>` |
| `artisan make:controller/model/migration/...` | `veyro make:controller/model/migration/...` | [console](../getting-started/console.md) |
| `routes/web.php` with `Route::get()` | `routes/web.php` with `$router->get()` | no facades: the file receives `$router` |
| `Route::resource()` | separate `get/post/put/delete` routes | no resource helper |
| `{id?}` optional parameters | two routes | optional parameters are not supported |
| Controllers, constructor injection | same | handler arguments are resolved by name and type |
| `Request $request` | PSR-7 `ServerRequestInterface` | `getParsedBody()`, `getQueryParams()`, `getAttribute()` |
| `$request->validate([...])` | `$validator->validate($data, [...])` | same rule syntax, fewer rules |
| Eloquent `User::where()->get()` | `$orm->query(User::class)->where()->get()` | explicit `Orm` service |
| `$fillable`, `$hidden`, `$casts` | `FILLABLE`, `HIDDEN`, `CASTS` constants | |
| `hasMany()` methods | `relations()` returning `Relation::hasMany(...)` | |
| `use SoftDeletes` | `protected const SOFT_DELETES = 'deleted_at'` | `$orm->delete()`, `restore()`, `forceDelete()`; `withTrashed()`, `onlyTrashed()` |
| `scopeActive()`, `addGlobalScope()` | `Scope` classes: `->apply(new Active())`, `globalScopes()` | see [ORM scopes](../database/orm.md#scopes) |
| Observers, `booted()` model events | listeners for `ModelSaving`, `ModelSaved`, `ModelDeleting`, `ModelDeleted`, `ModelRestored` | sent by the `Orm`, not by models |
| `hasManyThrough()`, `morphMany()`, `morphTo()` | `Relation::hasManyThrough()`, `morphMany()`, `morphOne()`, `morphTo()` | morph types are an explicit alias map |
| Accessors/mutators | not available | use `CASTS` or explicit methods |
| Route model binding | same (`fn (User $user)`) | ULID `public_id` instead of ids in URLs |
| Blade `@extends`, `@section`, `@yield`, `@include`, `@push`, components | `$view->layout()`, `section()`, `yield()`, `render()`, `push()`/`stack()`, `component()` | plain PHP templates, `$e()` escapes |
| `{{ $x }}` | `<?= $e($x) ?>` | always escape |
| `{!! $x !!}` | `<?= $x ?>` with an `HtmlString` | only for trusted HTML |
| `@csrf` | the CSRF token field (see [authentication](../security/authentication.md)) | |
| `config('app.name')`, `env()` | inject `Config`; `$env` only inside `config/*.php` | no `env()` outside config |
| `session()`, `Session::` | inject `Session` | stored in the cache, not PHP files |
| `Auth::attempt()`, `auth()->user()` | inject `Auth`: `attempt()`, `user()`, `check()` | |
| Gates and policies | RBAC: roles and permissions, `RequirePermission` middleware | [authentication](../security/authentication.md) |
| `Hash::make()` | `PasswordHasher::hash()` | |
| Sanctum personal access tokens | built-in API tokens | [API tokens](../security/api-tokens.md) |
| `Cache::remember()` | PSR-16 cache, `CacheManager` | [cache](../getting-started/cache.md) |
| Jobs, `dispatch()` | `Job` classes, `$queue->push()` | JSON payloads, no serialized closures |
| Events and listeners | PSR-14 `EventDispatcher` | [events](../getting-started/events.md) |
| Mailables, notifications | `Mailable`, `Notification` | [mail](../getting-started/mail.md), [notifications](../getting-started/notifications.md) |
| `Schedule` in `routes/console.php` | `routes/schedule.php` | [scheduling](../getting-started/scheduling.md) |
| `Storage::disk('s3')` | `FilesystemManager` disks (local, S3) | [storage](../getting-started/storage.md) |
| `__('messages.x')` | translator, Hindi plural rules included | [translation](../getting-started/translation.md) |
| `Http::get()` client | none built in | use Guzzle or any PSR-18 client |
| Middleware classes | PSR-15 middleware | global list in `bootstrap/app.php`, per route, per group |
| Service providers | same idea: `register()` and `boot()` | [packages](../packages/creating-packages.md) |
| Package auto-discovery | `extra.veyrophp.providers` | Laravel packages do not work; framework-agnostic ones do |
| Feature tests | `TestClient` and the starter's PHPUnit setup | |

## Routes and controllers

```php
// routes/web.php
use App\Http\Controllers\UserController;

$router->get('/users/{id:\d+}', [UserController::class, 'show'])->name('users.show');
$router->post('/users', [UserController::class, 'store']);

$router->group('/admin', function (VeyroPHP\Routing\Router $router): void {
    $router->get('/users', [UserController::class, 'index'])->name('users');
}, middleware: [VeyroPHP\Auth\Authenticate::class], namePrefix: 'admin.');
```

A Laravel `store()` method that calls `$request->validate()` becomes:

```php
public function store(ServerRequestInterface $request, Validator $validator, Orm $orm): array
{
    $data = $validator->validate($request->getParsedBody() ?? [], [
        'name' => 'required|string|max:100',
        'email' => 'required|email',
    ]);

    return $orm->create(User::class, $data)->toArray();   // arrays become JSON
}
```

## Models

| Laravel | VeyroPHP |
| --- | --- |
| `class User extends Model { protected $fillable = ['name']; }` | `final class User extends Model { protected const FILLABLE = ['name']; }` |
| `public function posts() { return $this->hasMany(Post::class); }` | `public static function relations(): array { return ['posts' => Relation::hasMany(Post::class, 'user_id')]; }` |
| `User::find(5)` | `$orm->find(User::class, 5)` |
| `User::with('posts')->where('active', true)->get()` | `$orm->query(User::class)->with('posts')->where('active', true)->get()` |
| `$user->save()` | `$orm->save($user)` |
| `$user->roles()->sync([1, 2])` | `$orm->sync($user, 'roles', [1, 2])` |

See [ORM](../database/orm.md). Relations are loaded only with `with()` or `load()`; there is no lazy loading, so N+1 queries cannot happen by accident.

## Migrations

The schema builder follows Laravel's names, so most migrations only need the class and namespace lines changed:

```php
return new class extends Migration {
    public function up(Schema $schema): void
    {
        $schema->create('posts', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->timestamps();
        });
    }

    public function down(Schema $schema): void
    {
        $schema->drop('posts');
    }
};
```

The analyzer lists every column method a project uses that the schema builder does not have.

## Views

| Blade | VeyroPHP template |
| --- | --- |
| `@extends('layouts.app')` | `<?php $view->layout('layouts.app') ?>` |
| `@section('title', 'Orders')` | `<?php $view->section('title', 'Orders') ?>` |
| `@section('sidebar') ... @endsection` | `<?php $view->section('sidebar') ?> ... <?php $view->endSection() ?>` |
| `@yield('title')` | `<?= $view->yield('title') ?>` |
| `@include('partials.footer')` | `<?= $view->render('partials.footer') ?>` |
| `@foreach ($users as $user) ... @endforeach` | `<?php foreach ($users as $user): ?> ... <?php endforeach ?>` |
| `<x-alert type="error" />` | `<?= $view->component('alert', ['type' => 'error']) ?>` |
| Livewire, Inertia | no equivalent |

## Packages

- **Framework-agnostic packages work unchanged.** Examples: Guzzle, Carbon, `illuminate/collections`, league/*, Symfony components.
- **Laravel-specific packages do not work.** Examples: `spatie/laravel-*`, Horizon, Telescope, Livewire, Nova and Filament, because they need Laravel's container, facades and service providers.
- See [packages](../getting-started/packages.md).

## What the porting process looks like

1. **Analyze, without changing anything.** Run `php veyro port:analyze ../my-laravel-app --from=laravel`. Read the report; it never modifies the project.
2. **Create a new application** from the starter, next to the old one. The old project stays untouched as the reference and the backup.
   - Run `php veyro port:convert ../my-laravel-app .` in it to convert migrations, models and routes ([details](porting-assistant.md)).
3. **Move one area at a time,** in this order: configuration and `.env` keys, migrations (run them on a **copy** of the database, never on production), models, routes and controllers, views, then jobs, mail and the rest.
4. **Port or write tests for each area,** and compare behaviour with the old application. A project that parses or boots is not a working port: only passing tests and manual checks are.

## Next

- [Porting assistant](porting-assistant.md): the analyzer report and its statuses.
- [Coming from CodeIgniter](from-codeigniter.md).
