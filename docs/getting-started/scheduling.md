# Scheduled tasks

Instead of one cron line per task, list the tasks in `routes/schedule.php` and let one cron entry run them. Design: [ADR 0025](../architecture/adr/0025-scheduler.md).

## Setup

Register `VeyroPHP\Scheduling\SchedulingServiceProvider` and its commands (the starter app does):

```php
$app->register(new SchedulingServiceProvider());
$app->register(new ConsoleServiceProvider([...SchedulingServiceProvider::COMMANDS, /* ... */]));
```

On the server, one cron entry runs every minute:

```cron
* * * * * cd /var/www/app && php veyro schedule:run >> /dev/null 2>&1
```

On Windows, or while developing, run `php veyro schedule:work` in a terminal instead (or Task Scheduler every minute with `schedule:run`).

## Defining tasks

`routes/schedule.php`:

```php
/** @var VeyroPHP\Scheduling\Schedule $schedule */

$schedule->command('cache:prune')->everyThirtyMinutes()->withoutOverlapping(30);
$schedule->command('auth:clear-resets')->dailyAt('03:00');
$schedule->job(new SendWeeklyReport('sales'))->weeklyOn(1, '08:00')->timezone('Asia/Kolkata');
$schedule->call(static function (Orm $orm): void {
    // ...
})->hourly()->name('Delete abandoned carts');
```

| Task | Runs |
| --- | --- |
| `command('name', ['--option'])` | A console command, in the same process. A non-zero exit code counts as a failure. |
| `job(new SomeJob(...), 'queue')` | Puts the job on the queue; a [queue worker](queues.md) runs it. Best for anything slow. |
| `call(fn (...) => ...)` | A closure. Class-typed parameters are injected; an `Output` parameter receives the output. |

### When

| Method | Cron |
| --- | --- |
| `everyMinute()`, `everyTwoMinutes()`, `everyFiveMinutes()`, `everyTenMinutes()`, `everyFifteenMinutes()`, `everyThirtyMinutes()` | `* * * * *`, `*/2`, `*/5`, … |
| `hourly()`, `hourlyAt(17)`, `everyTwoHours()` | `0 * * * *`, `17 * * * *`, `0 */2 * * *` |
| `daily()`, `dailyAt('13:00')`, `twiceDaily(1, 13)` | `0 0 * * *`, … |
| `weekly()`, `weeklyOn(1, '08:00')` | Sunday 00:00; Monday 08:00 |
| `monthly()`, `monthlyOn(4, '15:00')`, `quarterly()`, `yearly()` | |
| `weekdays()`, `weekends()`, `mondays()`, `sundays()`, `days(2, 4)` | Combine with a time, e.g. `dailyAt('09:00')->weekdays()` |
| `cron('*/15 9-17 * * mon-fri')` | Any five-field expression (also `@daily`, `@hourly`, …) |

Mistakes (`dailyAt('25:00')`, an invalid cron expression, an unknown time zone) throw `ScheduleException` when the schedule is loaded, not at 3 a.m.

### Conditions

- `timezone('Asia/Kolkata')`: the times above are in this zone (default: PHP's time zone).
- `environments('production')`: only when `APP_ENV` matches.
- `when(fn () => ...)`, `skip(fn () => ...)`: decided each time.
- `withoutOverlapping($minutes = 1440)`: skipped while the previous run is still going. The lock lives in the cache and expires after `$minutes`, in case a run died. With several servers, use a shared cache (Redis).
- `name('...')`: the text shown in logs and `schedule:list`.

## Commands

```bash
php veyro schedule:list         # tasks, cron, time zone, next run
php veyro schedule:run          # run what is due now (from cron)
php veyro schedule:work         # run every minute in the foreground (--minutes=N to stop)
```

## Behaviour

- Due tasks run **one after another** within the same `schedule:run`. A task that takes several minutes delays the following ones; queue slow work with `job()` instead.
- A failing task is logged (`Scheduled task failed`) and printed. The other tasks still run, and `schedule:run` exits with code 1.
- Runs are logged with task and duration.
- Not supported yet: running a task on only one of several servers, and running tasks in parallel in the background.
