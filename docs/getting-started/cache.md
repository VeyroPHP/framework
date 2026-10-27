# Cache

Status: **implemented** (Phase 5). Design decisions are in [ADR 0012](../architecture/adr/0012-cache.md).

`VeyroPHP\Cache\Cache` implements PSR-16 (`Psr\SimpleCache\CacheInterface`). Register `CacheServiceProvider`, then inject `Cache` (or `CacheInterface`).

Expired entries in the file store are deleted when they are read. Entries that are never read again (for example sessions of visitors who do not return) stay on disk until `php veyro cache:prune` (or `$cache->prune()`) removes them, so run it from cron. The Redis store expires entries itself.

```php
$cache->set('settings', $settings, 3600);           // TTL in seconds or DateInterval
$cache->get('settings', []);                        // default on a miss
$cache->has('settings');
$cache->delete('settings');

$report = $cache->remember('report.daily', 600, fn () => $reports->buildDaily());
$countries = $cache->rememberForever('countries', fn () => $db->table('countries')->get());
```

## Configuration (`config/cache.php`)

```php
return [
    'default' => $env->string('CACHE_STORE', 'file'),
    'prefix' => 'app',              // namespace; clear() only removes this prefix
    'default_ttl' => null,          // seconds, or null for no expiry
    'allowed_classes' => false,     // classes cached values may contain (file/redis)
    'stores' => [
        'array' => ['driver' => 'array'],
        'file' => ['driver' => 'file', 'path' => 'storage/cache'],
        'redis' => ['driver' => 'redis', 'host' => '127.0.0.1', 'port' => 6379, 'password' => null, 'database' => 1],
    ],
];
```

Without a `cache` config, a file store in `storage/cache` with prefix `app` is used. Other stores: `$manager->store('redis')` via `CacheManager`.

Redis settings (the Redis queue connection takes the same ones): `host`, `port` (6379), `password` (`null` or `""` for none), `database` (0), `timeout` (connect, seconds; 1.0 for the cache, 2.0 for the queue) and `read_timeout` (seconds to wait for an answer, default 5.0).

- A wrong type is reported with the key's name, never its value.
- A wrong password, or a `database` the server cannot select, is an error: the store never falls back to database 0, where another application's data may be.

| Driver | Use | Notes |
| --- | --- | --- |
| `array` | tests, one request | lost when the process ends |
| `file` | single server | atomic writes; one file per key |
| `redis` | several servers | needs `ext-redis` |

## Rules

- **Keys:** non-empty, without `{}()/\@:` (PSR-16). Invalid keys throw `InvalidArgumentException`.
- **TTL:** `null` uses `default_ttl`; zero or negative deletes the entry.
- **`null` is a valid value:** `has()` is true and `remember()` does not recompute it.
- **Failures do not break the app:** unreadable or corrupt entries are misses; failed writes return `false`.
- **Objects:** with the default `allowed_classes => false`, cached objects come back as `__PHP_Incomplete_Class`. Cache arrays, or list the classes you need. This protects against object injection if someone can write to the cache.
- **Nothing is cached automatically.** Invalidate with `delete()`, `clear()` or a TTL.

## Stampede protection

When many requests miss the same key at once, `remember()` lets one of them compute the value while the others wait (up to 3 s) and then read it. If the wait times out, a waiting request computes the value itself. Locks expire after 10 s. A test with five concurrent PHP processes confirms the callback runs once.
