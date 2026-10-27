# ADR 0018: Lost database connections and connect retries

- Status: Accepted
- Date: 2026-10-26

## Context

The owner asked what happens when the database keeps disconnecting. Before this decision, a broken connection (MySQL "server has gone away" after an idle timeout, a database restart, a proxy closing connections) made the next query fail, and the broken PDO handle stayed in use. Connecting had no timeout setting and no retry.

## Decisions

### Connecting

- `timeout` (default 5 s) per connection: `PDO::ATTR_TIMEOUT` for MySQL, `connect_timeout` in the PostgreSQL DSN, and the busy timeout for SQLite.
- `connect_retries` (default 2): only errors that may go away by themselves are retried (connection refused, timed out, too many connections, server starting up), after 100 ms, 200 ms, 400 ms… Wrong passwords and unknown databases fail immediately, because retrying them only slows down the error.

### A connection that breaks during a query

Recognized by SQLSTATE class 08, PostgreSQL 57P01–57P03, MySQL client errors 2006/2013/2055/4031 and known messages (`LostConnection`). The broken handle is always dropped; the next statement opens a new connection.

Whether the failed statement is repeated depends on whether repeating it could apply it twice:

| Situation | Repeated once on a new connection? |
| --- | --- |
| Failed while being prepared (never reached the server; typical for idle timeouts) | Yes, reads and writes |
| Read failed while executing | Yes |
| Write failed while executing (it may have been applied) | **No**: `QueryException` with `isLostConnection()` true |
| Inside a transaction | **No**: the transaction is gone; the level is reset and the error is thrown |
| The repeat fails again | No further attempts |

- `BEGIN` on a broken connection is repeated (nothing has run yet).
- A broken connection during `COMMIT` is reported as an error: whether the commit reached the server cannot be known, and pretending either way could lose or duplicate data.
- A broken connection during `ROLLBACK` counts as rolled back, because the server discards uncommitted work when a connection closes; the original error that triggered the rollback is not hidden.

Laravel repeats any statement after a lost connection outside transactions. That can insert a row twice when the connection breaks after the server applied a write. VeyroPHP does not do that.

## Verification

- Unit tests with a SQLite PDO that fails on purpose at prepare, execute or begin (`ReconnectTest`).
- Integration tests on MySQL (MariaDB 10.4) and PostgreSQL 16 that end their own session (`KILL`, `pg_terminate_backend`) and check: reads still work, a write is never applied twice, and a transaction lost midway leaves no data.
- Mutation checks: allowing writes to repeat after execute, or retrying inside transactions, each fail a test.

## Not done

- Read replicas and fail-over between database hosts.
- Retrying deadlocks (SQLSTATE 40001) automatically; that needs the whole transaction to be repeatable and is left to application code.
