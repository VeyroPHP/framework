# Porting assistant

`php veyro port:analyze` reads an existing Laravel or CodeIgniter project and reports, area by area, how it maps to VeyroPHP. It is the first step of a port: it tells you how much work there is and where, before you change anything. Design: [ADR 0036](../architecture/adr/0036-porting-assistant.md).

```bash
php veyro port:analyze ../my-laravel-app                       # framework detected from composer.json
php veyro port:analyze ../my-ci-app --from=codeigniter
php veyro port:analyze ../my-laravel-app --format=json --output=build/port-report.json
```

## Safety

- **The project is only read.**
  - `composer.json` and `composer.lock` are decoded as JSON, and PHP files are split into tokens (`token_get_all`). Nothing is included, required or executed, so code at the top of a file cannot run.
  - The tests prove this with a fixture file that would create a marker if it ran.
- **Nothing in the project is changed.**
  - `--output` refuses any path inside the analyzed project. It checks the path before creating any folder.
- **Secrets are never opened.**
  - `.env`, `.env.*` (except `.env.example`), key files and `auth.json` are skipped. Environment keys are read from `.env.example` only.
- **Dependencies and generated folders are skipped,** and symlinks and junctions are not followed. Skipped folders are `vendor`, `node_modules`, `storage`, `writable`, `bootstrap/cache` and `.git`. At most 20,000 files are read, each up to 2 MB, and the report warns when it stopped early.

## Statuses

| Status | Meaning |
| --- | --- |
| `direct` | VeyroPHP has the same concept with the same shape: mostly renaming. Examples: route definitions, `hasMany`, `@extends`, `.env` keys with the same name. |
| `manual` | The concept exists, but the code must be rewritten. Examples: Eloquent models to `Model` constants plus `Orm`, facades to injected services, Blade to PHP templates, soft deletes, query scopes, model events, morph relations. |
| `unsupported` | No equivalent yet; it needs another design or a third-party package. Examples: accessors and mutators, the `Http::` client, Livewire, `$table->enum()`. |

- **What VeyroPHP supports is read from the framework itself.** Schema-builder methods and ORM relation types come from reflection, so the report follows the installed VeyroPHP version.
- **Counts are estimates.** Static reading cannot see calls built at run time, for example a facade method name in a variable.

## What is analyzed

| Area | Laravel | CodeIgniter 4 |
| --- | --- | --- |
| Packages | each `composer.json` dependency; packages that need `illuminate/*` are flagged | the same, with `codeigniter4/*` |
| Routes | `Route::` calls, resource routes, optional parameters | `$routes->` calls, resource and presenter routes, auto routing |
| Models | Eloquent models, relation types, soft deletes, scopes, accessors, `$guarded`, model events | models, soft deletes, callbacks, validation rules |
| Database | migrations and every schema method without an equivalent | forge migrations |
| Views | Blade templates, each directive, `<x-...>` components | PHP views and their layouts |
| Code | facades and global helpers | helpers (`view`, `esc`, `site_url`, ...) |
| Structure | controllers, middleware, jobs, mail, notifications, commands, policies, tests, ... | controllers, filters, entities, libraries, commands, ... |
| Configuration | `.env.example` keys | |

CodeIgniter 3 projects are recognized: the report lists their folders and marks everything as a manual rewrite.

## JSON report (schema 1)

```json
{
    "schema": 1,
    "framework": "laravel",
    "version": "v12.1.0",
    "root": "D:/projects/shop",
    "notice": "Analysis only: the project was read, not run or changed, and nothing was converted. ...",
    "summary": { "direct": 9, "manual": 21, "unsupported": 8 },
    "warnings": [],
    "findings": [
        {
            "area": "Facades", "item": "Http::", "status": "unsupported", "count": 4,
            "note": "no HTTP client is built in: use Guzzle or any PSR-18 client",
            "files": ["app/Services/Feed.php"], "doc": "docs/migrating/from-laravel.md"
        }
    ]
}
```

## Converting (Laravel)

`php veyro port:convert` turns a Laravel project's **migrations, models, routes, Blade views, controllers and public files** into VeyroPHP files inside a **separate, new VeyroPHP application**:

```bash
composer create-project veyrophp/app ../my-app-veyro          # the new application
php veyro port:convert ../my-laravel-app ../my-app-veyro --dry-run
php veyro port:convert ../my-laravel-app ../my-app-veyro
cd ../my-app-veyro && git diff                                 # review everything
```

| Laravel | Written to | What happens |
| --- | --- | --- |
| `database/migrations/*.php` | the same path | `Schema::` → `$schema->`, Laravel `use` lines replaced, `up(Schema $schema)`/`down(Schema $schema)`, a missing `down()` generated from the tables `up()` creates, `constrained()` table names written out, `DB::table()`/`DB::raw()` → `$schema->connection()` and `Expression`. Unsupported column types are commented out; unsupported modifiers are removed. |
| Eloquent models | the same path (`app/Models/Admin/User.php` stays there, so the file still matches its namespace) | Two files that would get the same target are never both written: the first is kept and the other is reported. Skeleton with `TABLE`, `FILLABLE`, `HIDDEN`, `CASTS`, `TIMESTAMPS`, `PRIMARY_KEY` and `relations()` for hasMany/hasOne/belongsTo/belongsToMany. Table names, foreign keys and pivot tables Laravel infers are written out and flagged. Methods, scopes, accessors and traits are listed, not copied. |
| Blade views (`*.blade.php`) | the same path, `.php` | See below. |
| Controllers | `app/Http/Controllers/` | See below. |
| `public/` (CSS, JS, images, fonts) | `public/` | Copied as they are, except `index.php`, `.htaccess`, Filament/Livewire assets, and files the target already has. |
| `routes/web.php`, `routes/api.php` | `routes/ported/` | `Route::` → `$router->`. Covers verbs, match/any, view/redirect routes, resource and apiResource routes (expanded into their seven routes, honouring only/except), groups with prefix/middleware/name, `name`/`where`/`whereNumber`/`middleware` modifiers, and the auth/sanctum/verified/signed middleware. `api.php` goes under `/api`. The file is **not loaded** until you add it to `routeFiles`. |

**Blade views:**

- **Echoes:** `{{ }}` escapes through `$e()`; `{!! !!}` prints raw.
- **Control structures:** `@if`, `@foreach`, `@forelse`/`@empty`, `@unless`, `@isset`, `@for`, `@while`, `@php`, `@break`/`@continue`.
- **Layouts:** `@extends`, `@section`/`@yield` (`content` becomes the layout's `$content`; names with dashes become identifiers), `@include`, `@push`/`@stack`, `@component`/`@slot`, `<x-name>` components with `@props`.
- **Other directives:** `@csrf`, `@json`, `@class`, `@style`, `@selected`, `@checked`, `@disabled`.
- **`$loop` inside `@foreach`/`@forelse` is emulated:** index, iteration, first, last, count, remaining, even and odd.
- **Helpers:**
  - `__()` becomes `$t()`, and `trans_choice()` becomes `$tc()`.
  - `route()`, `url()`, `asset()`, `url()->current()`, `request()->is()` and `request()->routeIs()` become `$urls->...`.
  - `$__env->yieldContent()` becomes `$view->yield()`.
- **`@can` fails closed:** the block is hidden until the permission check is ported.
- **Unknown `@words` (CSS `@media`, e-mail addresses) are left alone,** as Blade does.

**Controllers:**

- **Services are injected through the constructor** (`$this->view`, `$this->orm`, `$this->response`, ...).
- **`Request` becomes the PSR-7 request.**
- **Calls with a fixed counterpart are rewritten:** `view()`, `response()`/`response()->json()`, `redirect('/path')`, `abort()`/`abort_if()`/`abort_unless()`, `config()`, `route()`, `$request->validate()`, `Cache::`/`Log::`/`Auth::`, and model calls that exist on VeyroPHP's query (`Page::where(...)->firstOrFail()`).
- **Eloquent scopes, `withCount()`, `paginate()`, Carbon and other Laravel APIs keep their code** with a `TODO(port)` line above.

**Every file carries `TODO(port)` comments** for what a developer must still decide or write, and the command prints them all (`--format=json` for a report).

**Safety:**

- The source project is only read.
- The target must be a VeyroPHP application, and neither project may contain the other.
- Existing files are never replaced. `--force` replaces only files the converter wrote before; they carry a marker line.

**Conflicts are reported before you run anything:**

- tables that the converted migrations and the target's own migrations both create (typically `users`)
- routes or route names that both define (typically `GET /` and `home`). This check is approximate.

**Not converted yet:** middleware, form requests, jobs, mail, notifications and console commands. Port them by hand; `port:analyze` lists them.

### What a real conversion showed

The owner's Laravel 12.69 project, converted into a throwaway application (the source was only read):

- **30 files, all syntactically valid.**
- **All 15 converted migrations ran on an empty SQLite database** after fixes found on the way:
  - column-level `primary()` was missing
  - arrays in `dropColumn()` and `select()` were not accepted
  - data changes through the `DB` facade needed `$schema->connection()`
  - `orderBy('id')->cursor()` needed `lazyById()`
- **Remaining work the converter correctly flagged:**
  - the `users` table that both the project and the starter create
  - the `/` route and the `home` name that both define
  - a data migration that uses an application class and `$row->id`
  - controllers and an application class (`App\Support\Sections`) the routes need
- **All 13 models loaded, and their 22 relations point to existing classes.**
- **The 12 routes registered correctly** once their controllers existed. Stubs were used, only for that test.

### Serving a converted page

A second conversion of the same project into a fresh throwaway application went one step further: serving a page.

- **48 files, all syntactically valid.**
- **Conflicts were found before running anything:** the `users`, `jobs`, `failed_jobs` and `password_reset_tokens` tables, the `/` route and the `home` name.
  - Tables created by schema classes such as `QueueSchema` are found by reading those classes' files.
  - The converter now also marks Laravel's own tables (`jobs`, `cache`, `sessions`, ...) for removal.
- **What a developer did by hand:**
  - kept the project's `users` table and removed the Laravel framework tables
  - copied `app/Support`
  - installed `illuminate/collections` and `illuminate/support`
  - replaced four Laravel helpers in the layout
  - replaced `Setting::get()` and an ad-slot query that the views call directly
- **Result:** 18 migrations ran, and **`GET /about-us` answered 200 with the page's title, body, navigation (`$urls->route()` with positional parameters) and breadcrumb schema**, both through the test client and through Apache under `/veyrophp-port-test`. A missing page answered 404 from the converted `firstOrFail()`.
- **Fixed in the framework and the converter along the way:**
  - `ResponseFactory` ignored a caller's `Content-Type`.
  - `content` is reserved as a section name.
  - URL helpers as `$url`/`$route` closures clashed with template variables, so they became `$urls->...`.
  - `@php` blocks were not checked.
  - `$loop` was missing in `@forelse`.
  - `request()->is()` had no counterpart.

## After the analysis

Follow the guide for your framework: [Laravel](from-laravel.md) or [CodeIgniter](from-codeigniter.md).

1. **Create a new application** next to the old one, and keep the old one untouched as the reference and the backup.
2. **Port one area at a time.**
3. **Run migrations against a copy of the database,** never against production.
4. **Run `port:convert`** for migrations, models and routes (Laravel), then review every `TODO(port)`. Everything else is ported by hand.
