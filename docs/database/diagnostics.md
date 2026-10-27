# Database Diagnostics

Status: **implemented** (Phase 4). Design decisions are in [ADR 0011](../architecture/adr/0011-query-profiling-and-benchmarks.md).

## Enabling profiling

```php
// config/database.php
'profiling' => [
    'enabled' => $env->bool('DB_PROFILING', false),
    'slow_query_ms' => 100,          // queries at or above this are "slow"
    'log_slow_queries' => true,      // warning in the log for each slow query
    'log_queries' => false,          // every query at debug level (noisy)
    'n_plus_one_threshold' => 5,     // same SQL this often in one request => warning
    'expose_headers' => false,       // X-Query-Count / X-Query-Time-Ms; development only
],
```

Add the middleware as the **outermost** global middleware, so it sees every query of the request:

```php
new HttpServiceProvider(
    routeFiles: ['routes/web.php'],
    middleware: [ProfileQueriesMiddleware::class, JsonBodyParser::class],
);
```

The starter app already does this. Set `DB_PROFILING=true` in `.env` to turn it on.

## What gets logged

Slow query:

```
app.WARNING: Slow query (240.512 ms): select * from "orders" where "total" > ? {"sql":"select * from \"orders\" where \"total\" > ?","binding_count":1,"duration_ms":240.512,"connection":"mysql"}
```

Possible N+1:

```
app.WARNING: Possible N+1 queries on GET /authors: 1 statement(s) repeated, 11 queries in total {"repeated":{"select * from \"books\" where \"author_id\" = ?":10}, ...}
```

**Bound values are never logged.** Only the SQL with `?` placeholders, the number of bindings, the duration and the connection name appear.

## Fixing an N+1 problem

```php
// N+1: one query for the authors, then one per author (11 queries for 10 authors)
foreach ($db->table('authors')->get() as $author) {
    $books = $db->table('books')->where('author_id', $author['id'])->get();
}

// Fixed: two queries in total
$authors = $db->table('authors')->get();
$books = $db->table('books')->whereIn('author_id', array_column($authors, 'id'))->get();
```

Both versions are covered by tests that assert the exact query count.

## Using the profiler directly

```php
$profiler = $container->make(QueryProfiler::class);

$profiler->count();             // queries since the last reset
$profiler->totalTimeMs();
$profiler->slowQueries();       // list of QueryExecuted
$profiler->repeatedQueries(3);  // ['select ... where id = ?' => 12, ...]
$profiler->reset();
```

Or attach your own listener:

```php
$manager->listen(function (QueryExecuted $query): void {
    // $query->sql, ->bindingCount, ->durationMs, ->connection, ->failed
});
```

Listeners run synchronously after every statement. An exception thrown by a listener reaches the code that ran the query.

## Execution plans with `explain()`

```php
$plan = $db->table('users')->where('email', $email)->explain();
```

The rows depend on the database:

| Database | Statement | What to look for |
| --- | --- | --- |
| MySQL / MariaDB | `explain select ...` | `type` = `ALL` means a full table scan; `key` shows the index used; `rows` estimates rows examined |
| PostgreSQL | `explain select ...` | One `QUERY PLAN` column per line; `Seq Scan` on a large table usually means a missing index; `Index Scan` is good |
| SQLite | `explain query plan select ...` | `detail` column: `SCAN users` is a full scan; `SEARCH users USING INDEX ...` uses an index |

For timing with real execution, run `explain analyze` (PostgreSQL, MySQL 8.0.18+) yourself on a copy of production data. It executes the query, so never run it with writes on production.
