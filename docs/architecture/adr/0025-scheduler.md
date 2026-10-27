# ADR 0025: Scheduler

- Status: Accepted
- Date: 2026-10-26

## Context

Laravel parity item 6. The deployment guide asked for one cron line per maintenance command (`cache:prune`, later `auth:clear-resets`), and applications have their own periodic work. Laravel uses `dragonmantank/cron-expression` and runs `schedule:run` every minute.

## Decisions

- **Own cron parser** (`CronExpression`):
  - The usual syntax: lists, ranges, steps, month and weekday names, 0/7 for Sunday, and `@daily`-style shortcuts.
  - Vixie-cron OR rule when both day fields are restricted.
  - `nextRunDate()` searches day by day, then hour, then minute (a few thousand steps at most), skips times that do not exist on DST days, and reports expressions that never match (31 February) instead of looping.
  - It is about 200 lines, so no dependency.
- **Tasks are defined in a PHP file** (`routes/schedule.php`) with a `$schedule` variable, like route files. There are three kinds: commands (in-process through the console kernel), jobs (queued) and closures (with injection).
- **Invalid schedules fail when loaded.** Every frequency helper re-parses the expression.
- **Sequential runs** inside one `schedule:run`; failures are logged and do not stop later tasks. `withoutOverlapping` uses a new non-blocking `Cache::tryLock()` (the existing `lock()` waits and then runs anyway, which is right for cache stampedes but wrong here).
- **`schedule:work`** for development and Windows; it wakes at the start of each minute.
- Not done:
  - `onOneServer()` (needs an atomic "add" across servers)
  - background or parallel runs
  - output to files
  - hooks (`before`, `after`, `onFailure`)

## Verified

- `CronExpressionTest` (42 cases) covers:
  - steps, ranges, lists and names
  - Sunday as 7
  - the day-field OR rule
  - shortcuts
  - next runs across days, weekdays, months and leap years
  - a skipped DST hour in New York
  - time zones
  - never-matching expressions
  - 11 kinds of invalid input
- `ScheduleTest` (9 tests) covers:
  - every frequency helper
  - time zone, environments, `when` and `skip`
  - order and continuing after a failure (exit code 1)
  - in-process commands and queued jobs
  - a failing command
  - `withoutOverlapping` skipping while another run holds the lock
  - an empty or missing schedule file
  - `schedule:list` next runs in the task's time zone
  - `schedule:work` waking at minute boundaries
- In the starter app, `schedule:list`, `schedule:run` and `schedule:work --minutes=1` ran for real.
