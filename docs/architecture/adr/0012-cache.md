# ADR 0012: Cache

- Status: Accepted
- Date: 2026-10-26

## Context

Phase 5 asks for a common cache interface (get, set, delete, has, TTL, remember, prefixes) with array, file and optional Redis drivers, and defined behavior for failures, stampedes, concurrent writes and stale data. Database queries must not be cached automatically.

## Decisions

1. **PSR-16** (`psr/simple-cache` ^3.0, a new runtime dependency allowed by ADR 0003) is the public contract. `VeyroPHP\Cache\Cache` implements it and adds `remember()` / `rememberForever()`.
2. **Two layers:** `Cache` handles key validation, prefixes and TTL. A small internal `Store` interface handles storage. `LockingStore` adds locks for stampede protection.
3. **Prefix namespaces:** every key is stored as `prefix:key`. `clear()` removes only that prefix and never flushes a shared backend; the Redis store uses SCAN and refuses an empty prefix.
4. **Failures never throw**, except for invalid keys (as PSR-16 requires). A missing, expired or corrupt entry is a miss; a failed write returns `false`; corrupt files are deleted. A broken cache degrades to "always compute", not to an error page.
5. **Stampedes:** `remember()` takes a short lock on a miss. Waiting callers poll until the value appears; if the wait (default 3 s) ends first, they compute the value themselves rather than fail. Locks expire (default 10 s) so a crashed process cannot block others. A multi-process test shows the callback runs once for five concurrent callers.
6. **Concurrent writes:** the file store writes to a temporary file and renames it, so readers never see partial data and the last writer wins. Redis writes are atomic.
7. **Stale data:** entries live until their TTL ends or they are deleted. Nothing is invalidated automatically, and nothing (including database results) is cached unless code asks for it.
8. **Safe deserialization:** file and Redis stores use `unserialize()` with `allowed_classes` defaulting to none. Objects come back as `__PHP_Incomplete_Class` unless their classes are listed. This prevents object injection from a writable cache directory or a shared Redis.
9. **Cached `null` is a hit:** `has()` returns true and `remember()` does not recompute it.

## Consequences

- Applications that cache objects must list their classes in `cache.allowed_classes`, or cache arrays.
- The Redis driver needs `ext-redis`; it is tested in CI, not on the development machine.
- The file store's `clear()` reads every entry to check its prefix, which is slow for very large caches. Use separate directories or Redis for those.
