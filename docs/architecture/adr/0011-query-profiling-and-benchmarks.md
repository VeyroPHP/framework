# ADR 0011: Query profiling and benchmarks

- Status: Accepted
- Date: 2026-10-25

## Context

Phase 4 asks for measurable database performance (query count, duration, slow queries, N+1 detection, redacted logging) and a reproducible performance baseline. The plan forbids invented numbers and premature optimization.

## Decisions

### Query events

- `Connection::listen()` and `DatabaseManager::listen()` register listeners that receive a `QueryExecuted` event after every statement, including failed ones and savepoint statements.
- The event carries SQL with placeholders, the binding count, the duration (`hrtime`) and the connection name. **It never carries bound values**, so any listener can log it safely.
- Connection time is not included in the first query's duration.
- Listeners run synchronously, and their exceptions propagate. A broken profiler should be visible, not silently ignored.
- With no listeners, the only overhead is two `hrtime()` calls per query.

### Profiler, logger and middleware

- `QueryProfiler` keeps exact counts and totals, but stores at most 500 individual queries, so long-running loops cannot exhaust memory.
- **N+1 detection** counts identical SQL strings. With placeholders, the same statement with different values has the same SQL, which is exactly the N+1 pattern. The default threshold is 5 per request.
- `QueryLogger` logs slow queries as warnings and optionally every query at debug level. Failed queries are not logged here, because the `QueryException` is reported by error handling.
- `ProfileQueriesMiddleware` resets the profiler per request and logs N+1 warnings even when the handler throws. It adds `X-Query-*` headers only when explicitly enabled, because they reveal internals.
- Profiling is **off by default** and configured under `database.profiling`.

### Benchmarks

- An in-repository runner (`bench/`) without dependencies: warm-up, several rounds, per-operation timing, median throughput with spread, and p50/p95/p99. Raw JSON is committed under `bench/results/`.
- Reports name the hardware, OS, PHP SAPI and opcache status, and list limitations. Comparisons with other frameworks are published only when they have been measured under the same conditions.

## Consequences

- The first baseline found a real bug: HTTP/1.0 clients waited 5 s per request. It is fixed and covered by a regression test.
- The baseline shows that cold-start cost (file loading) dominates per-request time without opcache. Optimizations such as a config cache, container compilation, route regex chunking and statement caching are deferred until opcache-enabled measurements exist.
