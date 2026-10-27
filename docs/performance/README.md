# Performance

Rules (from the engineering plan): measure before optimizing, publish raw results with hardware and software details, and never claim results that were not measured.

## Benchmarks

```bash
php bench/run.php --label=<machine-name>   # full run, about one minute
php bench/run.php --quick                  # smoke test
```

The runner (`bench/Benchmark.php`) does a warm-up, then several rounds, timing every operation with `hrtime()`. It reports median throughput with min/max across rounds and p50/p95/p99 per operation. Results are written to `bench/results/<date>-<label>.json` together with PHP version, OS, opcache and Xdebug status.

Cases: container autowiring, static and dynamic route matching, kernel (minimal, with middleware, JSON), PSR-7 objects, view rendering, and database reads and writes (query builder against raw PDO).

Large tables: `php bench/scale.php --rows=1000000` creates a throwaway SQLite file and measures lookups, pagination, chunked reads and bulk inserts (time and memory).

For HTTP numbers, run a load generator such as `ab` or `wrk` against the starter app and record the server setup (web server, PHP SAPI, opcache).

## Results

| Date | Machine | Report |
| --- | --- | --- |
| 2026-10-25 | i7-7700HQ laptop, Windows, XAMPP, opcache off | [baseline-2026-10-25.md](baseline-2026-10-25.md) |
| 2026-10-26 | same, opcache on in Apache | [baseline-2026-10-25.md](baseline-2026-10-25.md#with-opcache-enabled-2026-10-26) |
| 2026-10-26 | same, Phase 8 recheck under memory pressure (inconclusive) | [recheck-2026-10-26.md](recheck-2026-10-26.md) |
| 2026-10-27 | same, opcache on; release smoke test of the starter (`/`, `/business`, `/sitemap.xml`, a CSS file), one run each, other programs open | [results/2026-10-27-http-apache-i7-7700HQ-opcache-on-starter.txt](results/2026-10-27-http-apache-i7-7700HQ-opcache-on-starter.txt) |

## Runtime profiling

See [database diagnostics](../database/diagnostics.md) for query counts, slow-query logging, N+1 detection and `explain()`.
