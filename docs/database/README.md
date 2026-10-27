# Database

Status: **implemented** (Phase 3): connections, query builder and transactions. Migrations and the ORM come in Phase 5. Design decisions are in [ADR 0010](../architecture/adr/0010-database-layer.md).

## Configuration (`config/database.php`)

```php
return [
    'default' => $env->string('DB_CONNECTION', 'sqlite'),
    'connections' => [
        'sqlite' => ['driver' => 'sqlite', 'database' => 'database/database.sqlite'],
        'mysql' => [
            'driver' => 'mysql',
            'host' => $env->string('DB_HOST', '127.0.0.1'),
            'port' => $env->int('DB_PORT', 3306),
            'database' => $env->string('DB_DATABASE', 'veyrophp'),
            'username' => $env->string('DB_USERNAME', 'root'),
            'password' => $env->string('DB_PASSWORD', ''),
            'charset' => 'utf8mb4',   // default
            'strict' => true,         // MySQL strict SQL mode, default
            'options' => [PDO::ATTR_TIMEOUT => 5],
        ],
    ],
];
```

Register `VeyroPHP\Database\DatabaseServiceProvider`. It binds `DatabaseManager` and the default `Connection`. Relative SQLite paths are resolved inside the project. **No connection is opened until the first query.**

```php
$router->get('/users', fn (Connection $db) => $db->table('users')->get());

$manager->connection('mysql')->table('orders')->count();   // another connection
```

## Query builder

```php
$db->table('users')
    ->select('id', 'name', 'email as address')
    ->where('active', true)
    ->where('age', '>=', 18)
    ->where(fn (Builder $q) => $q->where('role', 'admin')->orWhere('role', 'editor'))
    ->whereIn('city', ['Alwar', 'Jaipur'])
    ->whereNull('deleted_at')
    ->orderByDesc('created_at')
    ->forPage(2, 20)
    ->get();                 // list of associative arrays
```

| Reading | Returns |
| --- | --- |
| `get()` | `list<array>` |
| `first()` | `array` or `null` |
| `value('name')` | first row's column, or `null` |
| `pluck('email')` | list of one column |
| `exists()` | `bool` |
| `count()`, `sum()`, `avg()`, `min()`, `max()` | aggregate |
| `paginate($perPage, $page)` | `Paginator` (`items`, `total`, `lastPage()`, JSON-serializable); runs `COUNT(*)` and `OFFSET`, see [large tables](#large-tables) |
| `cursorPaginate($perPage, $cursor)` | `CursorPaginator` (`items`, `nextCursor`); same speed on every page |
| `chunkById(500, fn ($rows) => ...)`, `lazyById()` | read a whole table in chunks with flat memory |

| Where | |
| --- | --- |
| `where('col', 'value')`, `where('col', '>', 5)` | operators: `= != <> < > <= >= like` and `not like` |
| `orWhere(...)`, `where(fn ($q) => ...)` | OR and nested groups |
| `whereIn`, `whereNotIn`, `orWhereIn` | an empty list matches nothing (`whereIn`) or everything (`whereNotIn`) |
| `whereNull`, `whereNotNull`, `orWhereNull` | `where('col', null)` **throws**; use these |
| `whereBetween`, `whereNotBetween` | two non-null bounds |
| `whereColumn('updated_at', '>', 'created_at')` | compares columns |
| `whereRaw('lower(email) = ?', [$email])` | raw SQL; never put user input into the SQL string |
| `whereJsonPath('meta', 'address.city', 'Jaipur')`, `whereJsonPath('meta', 'stock', '>', 5)`, `whereJsonPath('meta', 'tags[0]', 'sale')` | a value inside a JSON column; numbers compare as numbers. The path (keys of letters, digits and `_`, `[n]` for items) is validated and bound. Booleans and `null` are refused because databases read them differently. |
| `whereFullText(['title', 'body'], $search)` | full-text search on MySQL/MariaDB (natural language mode) and PostgreSQL (`plainto_tsquery`, `simple` configuration). It needs `$table->fullText(['title', 'body'])` over the same columns in the same order. SQLite throws instead of falling back to `LIKE`. |

Also: `join`, `leftJoin`, `groupBy`, `having`, `orderBy`, `limit`, `offset`, `distinct`, `toSql()` and `getBindings()`.

### Writing

```php
$id = $db->table('users')->insertGetId(['name' => 'Ravi', 'email' => 'ravi@example.com']);

$db->table('users')->insert([            // bulk insert, same columns in every row
    ['name' => 'Asha', 'email' => 'asha@example.com'],
    ['name' => 'Bharat', 'email' => 'bharat@example.com'],
]);

$db->table('users')->where('id', $id)->update(['name' => 'Ravi Kumar']);
$db->table('users')->where('id', $id)->delete();

$db->table('stock')->upsert($rows, uniqueBy: ['sku'], update: ['quantity']);
```

- `update()` and `delete()` **without a where clause throw**. Use `updateAll()` / `deleteAll()` when you really mean every row. A closure group that ended up empty (for example a filter loop with no filters) does not count as a where clause.
- Bulk `insert()` and `upsert()` of many rows are split into several statements below the database's limit on bound values (999 for SQLite, 65,535 for MySQL and PostgreSQL), inside one transaction: all rows are written or none. In `upsert()`, give each unique key only once: PostgreSQL refuses a key twice in one statement, and across statements the later row wins.
- Raw expressions in updates: `->update(['visits' => $db->raw('visits + 1')])`.

## Large tables

Measured with 1,000,000 rows (SQLite file, laptop; `php bench/scale.php`):

| Operation | Time | Memory |
| --- | ---: | ---: |
| Find by primary key, `public_id` or another indexed column | 0.1–0.3 ms | — |
| Find by a column **without** an index (full scan) | 94 ms | — |
| `paginate()` first page / last page | 48 ms / 180 ms | — |
| `cursorPaginate()` first page / last page | 0.7 ms / 0.5 ms | — |
| `get()` of 100,000 rows | 128 ms | 61 MB |
| `chunkById(1000)` over all 1,000,000 rows | 1.6 s | 1.2 MB |

What this means:

- **Index every column you search or sort by.** Indexed lookups stay under a millisecond at any size; unindexed ones read the whole table.
- **`paginate()` is for small and medium tables.** It runs `COUNT(*)` on every call, and deep pages use `OFFSET`, which reads and discards every earlier row. For large tables, infinite scrolling and APIs use `cursorPaginate()`:

```php
$page = $db->table('orders')->where('status', 'paid')->cursorPaginate(50, $request->getQueryParams()['cursor'] ?? null);
// JSON: {"data": [...], "per_page": 50, "next_cursor": "eyJrIjo1MH0" or null}
```

  The cursor is the last row's key (base64 JSON). A client may edit it, but only to start at another position of the same query; malformed cursors throw `InvalidCursorException` (400). There is no total and no "jump to page N": that is the trade-off for speed.
- **Never `get()` an unbounded table.** Memory grows with the rows (about 600 MB for a million). Process large tables in chunks:

```php
$db->table('users')->where('active', true)->chunkById(1000, function (array $rows): void {
    foreach ($rows as $row) { /* ... */ }
});

foreach ($db->table('users')->lazyById(1000) as $row) { /* one row at a time */ }
```

  Chunks are fetched with `where id > last` instead of `OFFSET`, so rows updated or deleted inside the callback are neither skipped nor read twice. Do not add your own `orderBy()`, `limit()` or `offset()`; these methods order by the key themselves.

## Identifiers and raw SQL

Table and column names must be plain identifiers (`users`, `users.email`, `email as address`, `users.*`). Anything else, such as `count(*)` or `lower(name)`, must be wrapped in `$db->raw()`:

```php
$db->table('posts')->select('user_id', $db->raw('count(*) as total'))->groupBy('user_id')->get();
```

This rule prevents SQL injection through column names (for example a user-controlled sort column). **Never build `raw()` strings from user input.**

## Bindings

Values are always sent as prepared-statement parameters. Supported types: `null`, `bool`, `int`, `float`, `string`, `DateTimeInterface` (stored as `Y-m-d H:i:s` in the object's own time zone; convert to UTC first if you store UTC), `BackedEnum` (its value) and `Stringable`.

## Transactions

```php
$orderId = $db->transaction(function (Connection $db) use ($cart) {
    $id = $db->table('orders')->insertGetId([...]);
    $db->table('order_items')->insert($cart->rows($id));

    return $id;
});
```

- The transaction commits when the callback returns and rolls back when it throws; the exception is rethrown.
- **Nested `transaction()` calls use savepoints**: an inner failure rolls back only the inner block.
- Do not call `commit()` or `rollBack()` inside the callback for the transaction it started; this is detected and throws.
- MySQL commits implicitly on DDL (`create table`, ...). A later `commit()` throws `TransactionException` explaining this.

**Row locks** keep a read-check-write from being overtaken by another transaction:

```php
$db->transaction(function (Connection $db) use ($id, $amount): void {
    $account = $db->table('accounts')->where('id', $id)->lockForUpdate()->first();
    // nobody else can change this row until the transaction ends
    $db->table('accounts')->where('id', $id)->update(['balance' => $account['balance'] - $amount]);
});
```

- `lockForUpdate()` ("for update") and `sharedLock()` (others may read, not change) hold rows until the transaction ends. Outside a transaction they **throw**, because the lock would end with the statement.
- `lockForUpdate(skipLocked: true)` leaves out rows another transaction holds, for work queues. It needs MySQL 8.0+, MariaDB 10.6+ or PostgreSQL. `lockForUpdate(noWait: true)` fails at once instead of waiting.
- SQLite has no row locks; it locks the whole database file while writing. There the methods add nothing to the SQL, but they still require a transaction, so code behaves the same on every database.

**Deadlocks:** `$db->transaction($callback, attempts: 3)` runs the whole transaction again after a deadlock or a serialization failure (`QueryException::isDeadlock()`: SQLSTATE 40001 or 40P01). It waits a short, growing, random time between attempts.

- Only the outermost transaction retries. Inside another transaction the error is rethrown, because the database may already have aborted the outer one.
- Lock wait timeouts are not retried.
- The callback must be safe to run again: send mail, call APIs and dispatch jobs after the transaction, not inside it.

## Lost connections and timeouts

Connections that break (database restart, idle timeout, network blip) are handled automatically; design in [ADR 0018](../architecture/adr/0018-connection-resilience.md).

```php
'mysql' => [
    // ...
    'timeout' => 5,          // seconds to wait when connecting (default 5)
    'connect_retries' => 2,  // retries if the server is briefly unreachable (default 2)
],
```

- Connecting is retried only for temporary errors (refused, timed out, too many connections), never for a wrong password.
- When a connection breaks during a query, it is dropped and the next statement opens a new one. The failed statement is repeated once **only when that cannot apply it twice**: reads, and statements that never reached the server.
- A write that may already have run, and anything inside a transaction, is **not** repeated: you get a `QueryException` whose `isLostConnection()` is true. Repeat the whole operation yourself if it is safe to do so:

```php
try {
    $orm->transaction(fn () => $checkout->complete($order));
} catch (QueryException $e) {
    if (!$e->isLostConnection()) {
        throw $e;
    }
    // Nothing of the transaction was committed; it is safe to try again (once).
    $orm->transaction(fn () => $checkout->complete($order));
}
```

## Errors

| Exception | When |
| --- | --- |
| `ConnectionException` | the connection cannot be opened |
| `QueryException` | the database rejects a query. `getSql()`, `getBindingCount()` and `getSqlState()` are available. The message never contains bound values. |
| `TransactionException` | commit or rollback without a transaction, callback level mismatch, implicit commit, failed rollback |
| `DatabaseException` | invalid configuration, identifier, operator, binding or query structure |

## Public ids for URLs

```php
use VeyroPHP\Support\Ulid;

$db->table('users')->insert(['public_id' => Ulid::generate(), ...]);
// URL: /users/01JB8Q3Z9X4V7K2M5N6P8R0T1W
$user = $db->table('users')->where('public_id', $publicId)->first();
```

ULIDs hide auto-increment ids but **do not replace authorization**: still check that the current user may see the record.

## Running the database tests

SQLite tests always run. MySQL and PostgreSQL tests need a dedicated, empty database:

```bash
VEYROPHP_TEST_MYSQL_HOST=127.0.0.1 VEYROPHP_TEST_MYSQL_DATABASE=veyrophp_test \
VEYROPHP_TEST_MYSQL_USERNAME=root vendor/bin/phpunit --filter MySqlDatabaseTest
```

PostgreSQL uses `VEYROPHP_TEST_PGSQL_*` and needs `pdo_pgsql`. CI runs both against MySQL 8 and PostgreSQL 16.
