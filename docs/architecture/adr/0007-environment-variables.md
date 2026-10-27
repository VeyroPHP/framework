# ADR 0007: Environment variables

- Status: Accepted
- Date: 2026-10-25

## Context

Applications keep deployment-specific values (database password, debug flag) in environment variables, usually with a `.env` file for local development. Options were to write a small parser or use `vlucas/phpdotenv`.

## Options

1. **`vlucas/phpdotenv`**: mature and widely used. It brings 3–4 transitive packages, supports interpolation and multi-line values, and by default writes into `$_ENV`, `$_SERVER` and optionally `putenv()`.
2. **Own parser**: about 150 lines for the subset applications need. No dependency, and full control over strictness.

## Decision

Option 2. ADR 0003 asks for strong justification for runtime dependencies, and the needed syntax is small.

- **Syntax:** `NAME=value`, `#` comments, `export ` prefix, single quotes (literal) and double quotes (escapes `\n \r \t \" \\ \$`). Unquoted values are trimmed, and ` #` starts a comment.
- **Rejected loudly instead of guessed:** multi-line values, `${VAR}` interpolation, duplicate names, quotes in the middle of unquoted values, unknown escapes, and invalid names.
- **No global state:** the loader never writes to `putenv()`, `$_ENV` or `$_SERVER`. It returns an immutable `Env` object. Config files receive it as `$env`; there is no global `env()` helper.
- **Precedence:** variables in the real process environment override `.env`, so servers and containers can override values without editing files.
- **A missing `.env` file is not an error**, because production often uses only real environment variables. A file that exists but cannot be read or parsed is an error.
- **Typed getters are strict:** `int()` accepts only optional `-` plus digits within the int range; `float()` accepts decimal notation; `bool()` accepts only `true`/`false`/`1`/`0` in any case. A default is used only when the variable is unset, not when it is empty or invalid.
- **Messages contain names and line numbers, never values.**

## Consequences

- `.env` files written for phpdotenv that use interpolation or multi-line values fail with a clear message and must be adjusted.
- Third-party code that reads `getenv()` does not see values from `.env`. This is intended; such values should be passed through config.
