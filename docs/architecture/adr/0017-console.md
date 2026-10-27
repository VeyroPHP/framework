# ADR 0017: Console kernel, developer commands and the starter project

- Status: Accepted
- Date: 2026-10-26

## Context

The starter app had a provisional `veyro` script with a `switch` over five commands and hand-written option parsing. Phase 7 replaces it with a framework console and adds developer commands and an installable starter project.

## Decisions

### No Symfony Console

The framework keeps its small dependency list (ADR 0003). The console needs commands, long options, arguments, help, exit codes and testable output; that is a few hundred lines. Symfony Console offers much more (questions, progress bars, shell completion) that is not needed yet. This can be revisited if those features are requested.

### Commands

- A command is a class extending `Command`, named with the `#[AsCommand(name, description)]` attribute. **The kernel reads the attribute with reflection**, so `php veyro list` constructs no command and opens no database connection. Commands are built by the container when they run, with autowired constructors.
- Definitions are value objects: `Argument::required/optional`, `Option::flag/value`. Short options are not supported except `-h` and `-V`, which keeps parsing unambiguous.
- Registration fails early: missing attribute, invalid names, the reserved names `help` and `list`, and duplicates throw `ConsoleException`.

### Exit codes and errors

- `0` success, `1` failure, `2` usage error. Return codes outside 0–255 become `1` with a message.
- Uncaught exceptions are logged and print `Error: <message>`. With `app.debug` the class, location and trace are added, except for validation errors, which are the user's input rather than a bug.
- Output goes through `Output`, whose streams are injected. Tests capture them with `php://memory` instead of a real terminal. Colors only on a TTY and without `NO_COLOR`.

### Built-in commands

- `migrate`, `migrate:rollback`, `migrate:status` moved from the starter script into the framework, keeping the production `--force` guard.
- `rbac:seed` lives in the RBAC module and is registered by the application, because RBAC is optional.
- `route:list` shows closures as `Closure (routes/web.php:26)` and middleware without namespaces.
- `make:model`, `make:controller`, `make:migration` **never overwrite**: they check first and then open with mode `x`, which fails if the file appeared in between. Names are validated (StudlyCase, no PHP reserved words, snake_case migrations), so input like `../Evil` cannot write outside the target folder.
- `serve` runs `php -S` with a router script that serves existing files under `public/` (never `.php` files) and sends the rest to `index.php`. The process is started with an **array command** (no shell), so the host and port cannot inject shell syntax; both are validated anyway.

### Starter project

- `composer create-project veyrophp/app` creates `.env`, runs migrations and seeds roles through Composer scripts, and prints the next steps.
- `archive.exclude` keeps `.env`, `vendor/`, `composer.lock`, the SQLite file, logs and cache files out of releases. Verified by building an archive and installing from it.
- **No app key is generated.** The framework has no encryption or signing feature that needs one yet (sessions use random ids stored server-side). A key will be added together with the first feature that needs it.
- `user:create` is an application command (`app/Console/Commands`), because it depends on the app's `User` model. It now creates the user and assigns the role in one transaction: an unknown role no longer leaves a user without a role.

### Testing support

- `VeyroPHP\Testing\TestClient` sends requests through the kernel in-process, keeps cookies and reuses the last form's CSRF token. It has no PHPUnit dependency.
- The starter app's tests force `DB_DATABASE=:memory:` and `CACHE_STORE=array` through PHPUnit `<env>` values, which win over `.env` (ADR 0007). The base test case asserts the in-memory database before running migrations, so a misconfiguration cannot touch a real database.

## Not done

- Publishing to Packagist: Phase 8. Until then `create-project` works from a local archive (documented in the quickstart).
- Interactive questions, progress bars and shell completion.
- Scheduling (cron) and queues.
