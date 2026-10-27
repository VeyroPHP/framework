# ADR 0023: Queues and background jobs

- Status: Accepted
- Date: 2026-10-26

## Context

Laravel parity item 4. Mail, listeners and other slow work should not run during the request. Laravel serializes job objects with `serialize()` and offers database, Redis, SQS and other drivers.

## Decisions

- **Payloads are JSON built through the constructor** (`Payload`), not `serialize()`:
  - Every constructor parameter must be stored in a property of the same name.
  - Values are JSON values, backed enums or saved models. A model is stored as a reference (class and key) and loaded fresh.
  - Restoring a payload can only call the constructor of a class implementing the expected interface. So write access to the jobs table or Redis does not give code execution through object injection (gadget chains).
  - Every value carries a type tag, so user arrays can never be mistaken for model references.
  - Anonymous classes, closures, objects, `INF`/`NAN` and payloads over 60 KB are rejected when pushed, not when run.
  - Cost: jobs must be written in this style. In return, a job that cannot be stored fails in the request that queues it, with a message naming the parameter.
- **Database driver without `SKIP LOCKED`.** A job is claimed with a conditional `UPDATE` that only matches while the row is still free (or its reservation has expired). The worker owns the job only if exactly one row changed. This works on MariaDB 10.4 (the owner's local server), MySQL 5.7+, PostgreSQL and SQLite.
  - Each reservation gets a random token. `delete()` and `release()` only act on their own token, so a worker that was presumed dead cannot remove a job another worker took over.
  - Times are Unix timestamps, so they do not depend on the database's time zone.
- **Redis driver with one Lua script per pop.** It moves due delayed and expired reserved jobs, takes one and reserves it atomically. The reservation is the sorted-set member itself.
- **sync** runs jobs at once (development and tests). **array** keeps them in memory for tests.
- **Retries:**
  - `tries` (default 3), `backoff` (seconds per retry) and `timeout` are public properties of the job, as in Laravel.
  - The attempt counter increases when a job is reserved, so a crash also counts. A job whose attempts exceed its tries fails without running again.
  - Jobs that can never run (`UnrunnableJobException`: invalid payload, missing class, changed constructor, uninjectable `handle()` parameter, deleted model) fail at once instead of wasting retries.
- **Failures** go to a `failed_jobs` table (class, message, file, line, trace without arguments, cut to 30 KB), call the job's `failed()`, dispatch `JobFailed` and are logged. Logs never contain payloads.
- **Worker:**
  - Priority order of queues; per-request (scoped) services reset after each job.
  - Stops on `--max-jobs`, `--max-time`, `--memory` and `--stop-when-empty`, on a restart signal in the cache (`queue:restart`), and gracefully on `SIGTERM`/`SIGINT` where pcntl exists.
  - The timeout is enforced with `pcntl_alarm` by ending the worker. Windows has no pcntl, so only `retry_after` protects against hanging jobs there.
- **Queued listeners** (`ShouldQueue`) and **queued mail** (`Mailer::queue()`) are ordinary jobs that carry the packed event or Mailable.
- Not built: job batches, chains, rate-limited jobs, unique jobs, SQS/Beanstalkd drivers, a dashboard (Horizon). The `Driver` interface allows more backends.

## Verified

- `QueueTest` (14 unit tests) with the in-memory driver covers:
  - JSON values, enums and Hindi text round trips
  - rejection of every kind of unstorable data at push time
  - retries with backoff, final failure with `failed()` and `JobFailed`
  - jobs whose worker crashed on every attempt
  - unrunnable jobs failing at once
  - logs without payload data
  - all daemon stop conditions, including `queue:restart`
  - the sync driver
  - queued listeners and queued mail
- `DatabaseQueueTestCase` on SQLite, MariaDB 10.4 and PostgreSQL 16 covers order, delays, release, takeover of abandoned reservations (the stale worker cannot delete or release), clearing, and the failed-job store.
  - On MariaDB and PostgreSQL, 4 worker processes take 200 jobs at the same time and every job is taken exactly once.
  - The concurrency test fails when the claim condition is removed, so it can detect the race.
- `RedisQueueTest` covers the same behaviour on Redis 8.
- `QueueAppTest` covers the configured providers end to end:
  - a model stored as a key and loaded fresh
  - a deleted model failing at once
  - `queue:failed`, `queue:retry`, `queue:forget`, `queue:flush`, `queue:restart`
  - the sync connection
  - option errors
- The starter app migrates the tables, `make:job` generates a job that a real `queue:work --once` ran, and a feature test queues mail through the sync connection.

Found while testing:

- The restart cache key contained `:`, which PSR-16 forbids. The worker swallowed the error, so `queue:restart` would silently never have worked. The key is now `veyrophp.queue.restart`.
- `--max-time` used the real clock, which the test's fake sleep could not advance (a 5-second busy loop in the test). The worker now takes a clock.
- Anonymous-class Mailables were accepted at push time but could never be restored. They are now rejected when pushed.

**pcntl verified on Linux** (2026-10-26, Ubuntu 24.04 in WSL2, PHP 8.3.6 with pcntl) with a standalone script, because WSL's PHP lacks the extensions PHPUnit needs:

- **Timeout:** a 5-second job with `timeout = 2` ended the worker after 2 seconds with exit code 1 and the log entry "Job timed out; stopping the worker".
- **SIGTERM:** sent 1 second into a 3-second job, it let the job finish ("Job processed", 3,052 ms); then the worker stopped with "stop signal" and exit code 0.

The full PHPUnit suite has not run on Linux yet; that belongs in CI.
