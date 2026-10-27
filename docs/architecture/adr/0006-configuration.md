# ADR 0006: Configuration

- Status: Accepted
- Date: 2026-10-25

## Context

Phase 1 needs configuration loading with dot-notation access and typed getters. Configuration often holds secrets, and silent type coercion (`"false"` read as `true`) is a common source of production bugs.

## Decision

1. **Format: PHP files returning arrays** in one directory, one top-level key per file (`config/app.php` → `app.*`). This needs no parser and no dependency, and opcache caches it. Files are trusted code. Subdirectories, hidden files and non-`.php` files are ignored. A file name with an extra dot (`app.local.php`) is an error, because its key could not be addressed.
2. **`Config` is immutable.** There is no `set()`. Runtime changes to configuration are hidden global state. Tests build a new `Config` instead.
3. **Typed getters are strict and have no default argument.** `string()`, `int()`, `float()`, `bool()` and `array()` throw `ConfigException` for a missing key or another type. The only widening is int to float. Defaults belong in the config file, where they are visible. `get($key, $default)` exists for optional values.
4. **Error messages name the key and the actual type, never the value**, so secrets do not end up in logs or error pages.
5. **Exceptions thrown inside a config file are wrapped** in `ConfigException` naming the file, with the original exception kept as `previous`. The original message is not copied into the new message, because it could contain a value.
6. **Environment variables are not read by `Config`.** Converting `.env` strings to typed values is a separate step (milestone 6), so `Config` only ever sees final PHP values.

## Not included yet

- A config cache (one merged file for production). It will be added once the bootstrap exists and the cost of loading many files is measured.
- Environment-specific override files.

## Consequences

- A wrong type fails loudly at the first read rather than behaving strangely later.
- Config files must contain correctly typed values (`'debug' => false`, not `'false'`).
- Keys containing dots inside a config array cannot be read with dot notation.
