# Logging and Error Handling

Status: **implemented** (Phase 1). Design decisions are in [ADR 0008](../architecture/adr/0008-logging-and-error-handling.md).

## Logging

Framework code depends on the PSR-3 `Psr\Log\LoggerInterface`. The built-in implementation is `VeyroPHP\Logging\FileLogger`.

```php
$logger = $app->container()->make(Psr\Log\LoggerInterface::class);

$logger->info('Order {id} placed', ['id' => 42, 'total' => 9.5]);
$logger->error('Payment failed', ['exception' => $e, 'card_number' => $card]);
```

Output:

```
[2026-10-25T10:15:00.123456+05:30] app.INFO: Order 42 placed {"id":42,"total":9.5}
[2026-10-25T10:15:01.000000+05:30] app.ERROR: Payment failed {"exception":{"class":"RuntimeException",...},"card_number":"[REDACTED]"}
```

### Configuration (`config/logging.php`)

```php
return [
    'path' => 'storage/logs/veyrophp.log', // relative to the base path, or absolute
    'level' => $env->string('LOG_LEVEL', 'debug'),
    'channel' => 'app',
    'redact' => ['aadhaar', 'pan_number'], // extra sensitive key words
];
```

All keys are optional. Register `LoggingServiceProvider` (after `ConfigServiceProvider`) to bind the logger.

### What the logger does for you

| Behavior | Detail |
| --- | --- |
| Level filter | Entries below `level` are dropped |
| Placeholders | `{key}` is replaced with scalar, `Stringable` or date context values |
| Redaction | Context keys containing `password`, `passwd`, `passphrase`, `pwd`, `pass`, `secret`, `token`, `apikey`, `appkey`, `privatekey`, `accesskey`, `authorization`, `creditcard`, `cardnumber`, `cvv`, `cvc`, `otp`, `ssn`, `dsn`, `jwt`, `cookie`, `session` or `bindings` (case- and punctuation-insensitive, so `APP_KEY` and `X-Api-Key` match) become `[REDACTED]`, at any depth. In other string values, `Bearer <token>` and the rest of an `Authorization:` line are replaced. The same list (without the last three) keeps fields out of flashed form input |
| Log injection | Line breaks in the message are escaped as `\n` |
| Exceptions | Under the `exception` key: class, message, file, line, trace (without arguments) and previous exceptions |
| Failures | A file that cannot be written throws `LoggingException` |

Redaction only looks at context **keys**. Never put secrets directly into the message text.

To add redaction to another PSR-3 logger, wrap it: `new RedactingLogger($monolog)`.

## Error handling

Register the error handler as early as possible, usually in the front controller:

```php
use VeyroPHP\Exception\ErrorHandler;

$handler = new ErrorHandler(
    logger: $app->container()->make(Psr\Log\LoggerInterface::class),
    debug: $config->bool('app.debug'),
);
$handler->register();
```

| Situation | Behavior |
| --- | --- |
| Warning, notice or other reported error | Thrown as `ErrorException` |
| Error silenced with `@` or excluded by `error_reporting()` | Left to PHP |
| Deprecation | Logged at `notice` level; execution continues |
| Uncaught exception | Logged at `critical` level; error output with status 500 |
| Fatal error (out of memory, compile error) | Same as uncaught exception, as `FatalError` |
| Logger throws while reporting | Both errors go to PHP's `error_log()`; error output is still sent |

Output:

- **CLI:** plain text on STDERR, exit status 255.
- **Web:** an HTML page with status 500. Partial output that was already buffered is discarded.
- **`debug: false`** (production): a generic message only. No exception message, class, path or trace.
- **`debug: true`**: class, message, location, trace and previous exceptions. Every value is HTML-escaped.

Never enable debug in production.
