# ADR 0036: Developer adoption and the porting assistant

- Status: Accepted
- Date: 2026-10-26

## Context

The owner asked for familiar developer experience and an easy migration path from Laravel and CodeIgniter. The specifications require:

- never execute source code during analysis
- preserve originals and backups
- never modify production data
- never claim migration success just because code parses
- start once the core, the package contracts, the documentation and the test infrastructure are stable
- Laravel first

This ADR covers the first milestone: guides and a read-only analyzer.

## Decisions

- **Guides first.** `docs/migrating/from-laravel.md` and `from-codeigniter.md` map each concept to VeyroPHP and say plainly what does not exist:
  - soft deletes, scopes, accessors, model events
  - an HTTP client, Livewire/Inertia
  - optional route parameters, resource routes
- **Analyzer, not converter.** `php veyro port:analyze` reports `direct` / `manual` / `unsupported` findings per area. Automatic conversion is a later milestone, and only into a new project, never in place.
- **Command name `port:`.** The `migrate` group already means database migrations, so the word "migration" would be ambiguous.
- **Static reading only:**
  - Composer files are decoded as JSON and PHP files are tokenized (`token_get_all`, which does not execute).
  - `SourceTree` skips dependency and runtime folders, does not follow symlinks or junctions, refuses secret files (`.env`, keys, `auth.json`) even when asked directly, and limits the number and size of files.
- **The verdicts follow the framework.** `Capabilities` reads the schema builder's methods and the ORM's relation types by reflection, so a column type added to VeyroPHP stops being reported as unsupported without changing the analyzer.
- **Packages:**
  - Laravel- and CodeIgniter-ecosystem packages are unsupported, except where VeyroPHP has a built-in replacement (Sanctum → API tokens, Shield or Breeze → authentication).
  - A package that looks framework-independent but requires `illuminate/*` or `codeigniter4/*` in `composer.lock` is downgraded to manual.
- **Output safety.** `--output` cannot point inside the analyzed project. The check runs before any folder is created; the first version created the folder first and then refused.
- **Honest wording.** Every report carries the notice that nothing was run, changed or converted, and that a port is complete when tests and manual checks pass, not when code parses.

## Verified

- **Fixtures** (`tests/Fixtures/Port`): a Laravel 12 project, a CodeIgniter 4 project, a CodeIgniter 3 layout and an unknown project. They are excluded from Composer's classmap, PHPStan and php-cs-fixer, because they are fake third-party code.
  - Each contains `app/Evil.php`, which writes a marker file when run. Running it by hand created the marker; the analysis never does.
  - Each has an `.env` with a fake secret that must not appear in the report. A `.gitignore` exception keeps those files in the repository.
- **Tests (12):**
  - every Laravel area, including relation verdicts from reflection and missing schema methods by name
  - `vendor/` and commented-out code ignored
  - `illuminate/*` dependency detection
  - CodeIgniter 4 and 3
  - detection and the error cases
  - secret files refused
  - token counting: comments, chains, methods versus functions
  - the command: table, JSON plus file, refusal to write inside the project (no folder created), usage errors
- **A real application, read-only:** the owner's Laravel 12.69 project. It was analyzed without writing to it (an `--output` inside it was refused and left no folder). The report found:
  - 14 models, 15 migrations and 15 Blade views
  - Filament, soft deletes, scopes and model events
  - the `Http::` client and `@vite`
  - 63 schema-method uses without an equivalent (for example `longText` and `after`)

## Addendum (2026-10-26): converters

- **`port:convert <laravel-project> <veyrophp-app>`:**
  - Converts migrations, models (skeletons) and routes.
  - Writes only into a separate VeyroPHP application (`ProjectWriter`): the target must be a VeyroPHP application and may not contain the source or be inside it. Paths may not leave the target.
  - Existing files are never replaced. `--force` replaces only files carrying the converter's marker. `--dry-run` writes nothing.
- **What is converted and what is flagged:**
  - Everything converted carries `TODO(port)` comments; each converter returns its notes, and route notes also go above the route or into the header.
  - Names Laravel infers are written out explicitly and flagged: tables, foreign keys, pivot tables, `constrained()` tables.
  - A missing `down()` is generated, because VeyroPHP's `Migration` requires it.
- **Capabilities:**
  - They are split into table methods and column modifiers. A combined list had accepted `->primary()` on a column, which did not exist.
  - Column-level `primary()`, arrays in `dropColumn()` and arrays in `select()` were added, because Laravel code uses them.
- **Conflicts with the target are reported before anything runs:** tables created twice, and routes or route names defined twice. The route check is approximate.
- **Validation:**
  - `parses()` is only a syntax check, and the output says so.
  - The tests run the converted fixture migration up and down on SQLite and assert that the source tree's hashes are unchanged.
- **Real conversion of the owner's Laravel project into a throwaway application** (the source was only read):
  - The first run failed on the first migration, which is why "parses" is never called a success.
  - After the fixes above, all 15 migrations ran on an empty SQLite database.
  - All 13 models and 22 relations loaded.
  - The 12 routes registered once stub controllers and one stub application class existed. The stubs were for the test only.
  - The flagged leftovers were real: the shared `users` table, the `/` route and `home` name, a data migration using an application class, and the controllers.
- **Bugs found in the analyzer and converter along the way:**
  - `Route::match` (`match` is a keyword token) was neither read nor counted.
  - `{post:slug}` (binding by column) would have become a regex constraint.
  - A multi-line argument inside a TODO comment broke the file.

## Addendum (2026-10-26): views, controllers and URL helpers

- **Blade converter:**
  - `{{ }}` always escapes.
  - Directives become PHP alternative syntax or `$view` calls.
  - `<x-*>` components with `@props` become `$view->component()`.
  - `content` maps to the layout's `$content`, and other names are sanitized.
  - `$loop` is emulated per loop (own counters, so nesting is safe).
  - `@can` fails closed: the block is hidden until the check is ported, so porting never exposes guarded markup.
  - Unknown `@words` stay as text, as Blade leaves them.
- **Controller converter:**
  - Mechanical rewrites only, with constructor injection so private helpers can use the services too.
  - The `ModelQuery` methods that model calls may map onto are read by reflection.
  - Everything else keeps its code under a `TODO(port)` line, placed by markers so that notes in multi-line chains land on the right line.
- **`VeyroPHP\View\ShareUrlHelpers` and `Urls`:** templates get `$urls` with `route()` (named or positional parameters), `to()`, `current()`, `is()` and `routeIs()`, all base-path aware.
  - A first version shared `$route` and `$url` closures. A real template's own `$url` variable hid them, so the helpers now live on one object with an unusual name.
- **`make:middleware`:** Laravel developers expect it; the test runs the generated middleware on a real request.
- **Framework bug fixed:** `ResponseFactory::html()`, `text()` and `json()` let the default `Content-Type` win over the caller's (array union keeps the left key). A test failed before the fix.
- **Migrations:**
  - Laravel's own tables are flagged with what VeyroPHP does instead.
  - Table conflicts include tables created by schema classes, found by reflection and read from source, never executed.
- **Verified end to end** on the owner's project in a throwaway application: a ported page was served with 200 through the test client and Apache, and 404 for a missing page. See the porting-assistant page for the manual steps that remained.

## Next
- Form requests, middleware and Eloquent scopes (into query services).
- CodeIgniter 4 converters.
- A CodeIgniter 3 deep analysis, if needed.
