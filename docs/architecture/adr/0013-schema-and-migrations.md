# ADR 0013: Schema builder and migrations

- Status: Accepted
- Date: 2026-10-26

## Context

The owner asked for a Laravel-like `users` table created by a migration. The plan lists schema inspection and migrations in the database build order.

## Decisions

1. **Blueprint API close to Laravel's** (`id`, `ulid`, `string`, `foreignId()->constrained()`, `timestamps`, `unique`, `index`) so it is familiar, with portable types mapped per database. Columns are NOT NULL unless `nullable()` is called.
2. **Grammars per database** produce DDL. Identifiers go through the dialect's validated quoting. DDL cannot use bound parameters, so defaults are rendered as escaped literals: `'` is doubled everywhere, `\` is also escaped on MySQL, and null bytes are rejected.
3. **Unique and normal indexes are created as separate `create index` statements** on all databases, with deterministic names shortened to at most 60 characters.
4. **Foreign keys** are part of `create table`. On ALTER, MySQL and PostgreSQL add a constraint. SQLite cannot add a constraint to an existing column, so a foreign key is only possible together with a newly added column (written inline); otherwise a clear exception is thrown.
5. **Migrations are PHP files returning an anonymous class extending `Migration`**, named `YYYY_MM_DD_HHMMSS_description.php`, and run in name order. Each `run()` call is one batch; `rollback()` undoes whole batches in reverse order.
6. **Atomicity:** on PostgreSQL and SQLite (transactional DDL) each migration and its record run in one transaction. MySQL commits DDL implicitly, so a failure can leave earlier statements of that migration applied. The exception says so explicitly, and a failed migration is never recorded.
7. **No concurrent-run lock.** Migrations are expected to run from one process during deployment.
8. **Command runner:** until the console kernel (Phase 7), the starter app ships a small `veyro` script with `migrate`, `migrate:rollback` and `migrate:status`. It refuses to change the database when `app.env` is `production` unless `--force` is given.

## Consequences

- `renameColumn()` needs MySQL 8.0+ or MariaDB 10.5+. The local XAMPP MariaDB 10.4 does not support it, and the integration test skips that step there.
- Changing a column's type (Laravel's `change()`) is not supported yet.
