# ADR 0008: Logging and error handling

- Status: Accepted
- Date: 2026-10-25

## Context

Phase 1 needs a logging contract, a default logger, secret redaction and global error handling. The plan requires that production errors reveal no internal details, that secrets are redacted from logs, and that exceptions are never silently swallowed.

## Decision

### Logging

- **PSR-3** (`psr/log` ^3.0) is the logging contract. Framework code depends only on `Psr\Log\LoggerInterface`.
- **`FileLogger`** is the built-in implementation: one line per entry, minimum level, PSR-3 placeholder interpolation and JSON context. It does not depend on Monolog. Applications that need rotation, remote handlers and similar features can bind any PSR-3 logger, such as Monolog.
- **Redaction by key name** (`Redactor`) is applied to the context before interpolation and encoding. Matching is broad on purpose: normalized keys containing `password`, `token`, `secret`, `apikey`, `authorization`, `cookie`, `session` and similar words. `RedactingLogger` adds the same behavior to any PSR-3 logger.
- **Log injection:** CR and LF in the message are escaped, so user input cannot forge additional log entries.
- **Traces never include function arguments.** `ThrowableFormatter` builds frames from file, line, class and function only. XAMPP's default `zend.exception_ignore_args=Off` would otherwise leak arguments into `getTraceAsString()`.
- **Write failures throw `LoggingException`.** A logger that silently drops entries hides problems.

### Error handling

- `ErrorHandler` converts PHP errors that are included in `error_reporting()` into `ErrorException`. Errors silenced with `@` or excluded by `error_reporting()` are left to PHP.
- **Deprecations are logged at `notice` level instead of thrown**, so a deprecation in a dependency does not take the application down.
- Uncaught exceptions and fatal errors (caught in a shutdown function, with a 32 KB memory reserve for out-of-memory errors) are logged at `critical` level and rendered as a 500 response.
- **Without debug, output contains no message, class, path or trace.** The HTML renderer escapes every dynamic value, even in debug mode.
- **If the logger itself fails**, both the logging failure and the original error are written to PHP's `error_log()`, and the error page is still rendered.
- **On the CLI, the process exits with status 255** after an uncaught exception. PHP exits with 0 once a user exception handler has run, which would make failing cron and CI jobs look successful. This was found by the integration test.

## Consequences

- Rotation of `FileLogger` files must be handled by the operating system (for example logrotate) or by switching to another PSR-3 logger.
- Redaction can hide harmless values whose key contains a sensitive word (for example `session_count`).
- Secrets written directly into a log message, rather than the context, cannot be detected.
