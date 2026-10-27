# ADR 0010: Database layer

- Status: Accepted
- Date: 2026-10-25

## Context

Phase 3 needs connections, prepared statements, a query builder for MySQL, PostgreSQL and SQLite, and transactions. The plan requires that user values are never interpolated, that identifiers are validated, that exceptions are predictable, and that SQL injection is covered by regression tests.

## Decisions

### Connections

- **PDO only, no extra dependency.** `ConnectionFactory` forces `ERRMODE_EXCEPTION`, disables prepare emulation, returns native types and fetches associative arrays.
- **Lazy:** a `Connection` opens PDO on the first query. Building queries never connects.
- **MySQL strict mode** (`STRICT_TRANS_TABLES` and related flags) is set by default; `strict: false` turns it off. **SQLite** enables foreign keys.
- **Secrets:** the password is a `#[SensitiveParameter]` and hidden from `var_dump()`/`print_r()`. Config values containing `;` or null bytes are rejected, which prevents DSN injection.

### Statements and bindings

- **Positional `?` bindings only**, passed as a list. Bindings are typed: null, bool (PARAM_BOOL on PostgreSQL, 0/1 elsewhere), int, float, string, `DateTimeInterface` (`Y-m-d H:i:s` in its own time zone), `BackedEnum` and `Stringable`. Other types are rejected with the position.
- **`QueryException`** includes the SQL with placeholders, the number of bindings and the SQLSTATE, but **never the bound values**. Driver messages themselves may quote data (for example MySQL's duplicate-entry message); this is documented.

### Query builder

- **Identifiers** (tables, columns, aliases) must be `[A-Za-z_][A-Za-z0-9_]*` segments joined by `.`, with an optional ` as alias`. Anything else, such as functions or expressions, must be an explicit `Expression` (`$db->raw()`). This blocks injection through column names, including `orderBy()`.
- **Operators** are whitelisted: `= != <> < > <= >= like` and `not like`. Order direction must be `asc` or `desc`.
- **Explicit null handling:** `where('col', null)` throws, because `= NULL` never matches. Use `whereNull()`.
- **Safe writes:** `update()` and `delete()` without a where clause throw; `updateAll()` and `deleteAll()` exist for intentional full-table writes. Joins, order and limit are rejected in update/delete because their support differs between databases.
- **Empty `whereIn()`** matches nothing (`1 = 0`); empty `whereNotIn()` matches everything.
- **Upsert:** `on conflict ... do update` for PostgreSQL and SQLite; `on duplicate key update ... values()` for MySQL and MariaDB. MySQL ignores `$uniqueBy` and uses the table's unique indexes; this is documented.
- **Counting** a grouped or distinct query uses a subquery, so the count is the number of result rows.

### Transactions

- `transaction(Closure)` commits on return and rolls back on exception, rethrowing the original exception.
- **Nested transactions use savepoints**, so an inner failure rolls back only the inner part, and an outer rollback discards committed inner work.
- A callback that commits or rolls back the transaction it was given is detected (`TransactionException`).
- On MySQL, DDL causes an implicit commit; a later `commit()` reports this instead of failing obscurely.
- A failing rollback is reported together with the original exception.

### Public identifiers

`VeyroPHP\Support\Ulid` generates 26-character ULIDs (48-bit millisecond time plus 80 bits from `random_bytes()`) for public ids in URLs. See the roadmap section "Public identifiers".

## Not included yet

Migrations, schema builder, ORM, query profiling, N+1 detection and keyset pagination belong to Phases 4 and 5.

## Consequences

- Queries that need SQL functions in identifiers must use `raw()` explicitly, which makes them easy to find in review.
- `where('col', null)` code ported from other frameworks must be changed to `whereNull()`.
- Tests: SQL compilation is tested per dialect without a database; behavior is tested against SQLite always, MySQL/MariaDB when configured locally, and MySQL 8 plus PostgreSQL 16 in CI.
