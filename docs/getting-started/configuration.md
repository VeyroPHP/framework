# Configuration

Status: **implemented** (Phase 1). Design decisions are in [ADR 0006](../architecture/adr/0006-configuration.md).

## Config files

Put one PHP file per area in a `config/` directory. Each file returns an array:

```php
<?php // config/database.php

return [
    'default' => 'mysql',
    'connections' => [
        'mysql' => [
            'host' => '127.0.0.1',
            'port' => 3306,
        ],
    ],
];
```

Rules:

- The file name becomes the top-level key: `database.php` → `database.*`.
- Only `*.php` files directly inside the directory are loaded, in alphabetical order. Subdirectories, hidden files (`.something.php`) and other files are ignored.
- A file name with another dot, such as `app.local.php`, is rejected.
- Every file must return an array. If a file throws, loading fails with a `ConfigException` that names the file.
- Config files are executed as PHP. Only load files you trust.

## Loading

```php
use VeyroPHP\Config\ConfigLoader;

$config = (new ConfigLoader())->load(__DIR__ . '/config');
```

## Reading values

Use dot notation to reach nested values. List items are addressed by index (`servers.0`).

```php
$config->string('database.connections.mysql.host'); // '127.0.0.1'
$config->int('database.connections.mysql.port');    // 3306
$config->has('database.connections.pgsql');         // false
$config->get('mail.from', 'noreply@example.com');   // default for a missing key
```

| Method | Returns | Missing key | Wrong type |
| --- | --- | --- | --- |
| `get($key, $default = null)` | the value, or `$default` | returns `$default` | not checked |
| `has($key)` | `bool` | `false` | not checked |
| `string($key)` | `string` | throws | throws |
| `int($key)` | `int` | throws | throws |
| `float($key)` | `float` (also accepts int) | throws | throws |
| `bool($key)` | `bool` | throws | throws |
| `array($key)` | `array` | throws | throws |
| `all()` | the full array | | |

The typed getters never convert values: `'8080'` is not an int and `'false'` is not a bool. Write typed values in your config files.

A key that exists with the value `null` counts as defined: `has()` returns `true` and `get()` returns `null`, not the default.

## Errors

`ConfigException` implements `VeyroPHP\Exception\VeyroPHPException`. Messages contain the key and the type, never the value:

```
Config key "database.connections.mysql.port" must be of type int, string given.
```

## Environment variables and `.env`

Design decisions are in [ADR 0007](../architecture/adr/0007-environment-variables.md).

```dotenv
# .env
APP_NAME=VeyroPHP
APP_DEBUG=true
DB_PORT=3306
DB_PASSWORD='p@ss # with hash'
GREETING="Hello\nWorld"
```

Load it and pass it to the config loader:

```php
use VeyroPHP\Config\ConfigLoader;
use VeyroPHP\Config\Env\EnvLoader;

$env = (new EnvLoader())->load(__DIR__ . '/.env');
$config = (new ConfigLoader())->load(__DIR__ . '/config', $env);
```

Inside config files, `$env` is available:

```php
<?php // config/app.php

return [
    'name' => $env->string('APP_NAME', 'VeyroPHP'),
    'debug' => $env->bool('APP_DEBUG', false),
];
```

| Method | Accepts | Unset, with default | Unset, no default |
| --- | --- | --- | --- |
| `string($name, $default)` | anything | `$default` | throws |
| `int($name, $default)` | `-?[0-9]+` within int range | `$default` | throws |
| `float($name, $default)` | `1`, `-0.5`, `.5`, `2.5e3` | `$default` | throws |
| `bool($name, $default)` | `true`, `false`, `1`, `0` (any case) | `$default` | throws |
| `has($name)`, `get($name)` | raw access; `get()` returns `null` when unset | | |

The default applies only when the variable is **not set**. A variable that is set but empty or invalid (for example `APP_DEBUG=yes`) throws `EnvException`.

Rules:

- Real environment variables override `.env`.
- A missing `.env` file is fine; a broken one is an error with the line number.
- Not supported: `${VAR}` interpolation and multi-line values. Both fail with a clear message.
- Nothing is written to `putenv()`, `$_ENV` or `$_SERVER`.

## Config cache (production)

```bash
php veyro config:cache    # .env + config/*.php -> bootstrap/cache/config.php
php veyro config:clear    # back to reading .env and config/ on every request
```

With the cache, a request loads one PHP file (kept in memory by opcache) instead of `.env` and every config file. On the starter app this gave about 23–31 % more requests per second.

- While the cache exists, **`.env` and `config/` are not read**. Run `config:cache` again after every change (part of each deploy). `$env` in config files only works when the cache is built, so read environment variables only inside config files, never in application code.
- Only strings, numbers, booleans, null and arrays can be cached; a closure or object in a config file makes `config:cache` fail and names the key.
- The file holds every config value, including secrets from `.env`. Keep `bootstrap/cache/` outside the web root and out of version control (the starter app ignores it).
- Do not use it during development. Tests ignore it when the environment variable `VEYROPHP_CONFIG_CACHE=0` is set, as in the starter app's `phpunit.xml`.

## Immutability

`Config` has no `set()` method. To use different values in tests, create a new instance:

```php
$config = new Config(['app' => ['debug' => true]]);
```
