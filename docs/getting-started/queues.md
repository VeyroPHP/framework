# Queues and background jobs

Slow work (sending mail, calling other services, building reports) should not make a visitor wait. Put it on a queue instead: the request only stores a small job, and a separate worker process runs it. Design: [ADR 0023](../architecture/adr/0023-queues.md).

## Setup

Register `VeyroPHP\Queue\QueueServiceProvider` and its commands (the starter app does):

```php
$app->register(new QueueServiceProvider());
$app->register(new ConsoleServiceProvider([...QueueServiceProvider::COMMANDS, /* your commands */]));
```

Choose the connection in `.env`:

| `QUEUE_CONNECTION` | Jobs are stored in | Use |
| --- | --- | --- |
| `database` | the `jobs` table (migration with `QueueSchema::up()`; the starter app ships it) | Default. Works on MySQL/MariaDB (any version), PostgreSQL and SQLite. |
| `redis` | Redis (needs ext-redis; settings as for the [Redis cache](cache.md), including `read_timeout`) | Many jobs per second. |
| `sync` | nowhere: the job runs at once, during the request | Trying things out; tests. |

Then start a worker:

```bash
php veyro queue:work
```

## Writing a job

```bash
php veyro make:job SendInvoice
```

```php
use VeyroPHP\Queue\Job;

final class SendInvoice implements Job
{
    public int $tries = 5;                  // attempts in total (default 3)
    public array $backoff = [10, 60, 300];  // seconds before retry 1, 2, 3, ... (default: at once)
    public int $timeout = 120;              // seconds; enforced on Linux/macOS (see below)

    public function __construct(public readonly Order $order, public readonly bool $copyToAdmin = false) {}

    public function handle(Mailer $mailer, LoggerInterface $logger): void   // injected
    {
        $mailer->send(new InvoiceMail($this->order->get('email'), $this->order->getKey()));
    }

    public function failed(\Throwable $error): void   // optional: after the last attempt
    {
    }
}
```

Queue it:

```php
public function checkout(Queue $queue /* , ... */)
{
    // ...
    $queue->push(new SendInvoice($order));
    $queue->push(new SendInvoice($order), 'emails');      // a named queue
    $queue->later(3600, new AskForReview($order));        // in an hour
}
```

### What a job may contain

The job is stored as JSON through its constructor, never with PHP's `serialize()`:

- Every constructor parameter needs a property of the same name. Promoted parameters (`public readonly int $id`) do this.
- Values may be `null`, booleans, numbers, strings, arrays of those, backed enums and **saved ORM models**.
- A model is stored as its class and key, and loaded again, fresh, when the job runs. If it was deleted in the meantime, the job fails at once (`ModelMissingException`).
- Anything else (a `DateTime`, a service, a closure, an unsaved model) is rejected when you push, with a message saying which parameter.
- Payloads larger than 60 KB are rejected: pass ids, not file contents.

### Jobs can run more than once

If a worker crashes after doing the work but before deleting the job, another worker runs it again. Write jobs so that a second run does no harm. For example, check "invoice already sent" first, or use a unique key in the database.

## Retries and failures

- A job that throws is retried after its `backoff` until `tries` are used up.
- Then it is stored in `failed_jobs`, removed from the queue, its `failed()` method is called, and `VeyroPHP\Queue\Events\JobFailed` is dispatched (listen to it to alert someone). The order matters:
  - **The failure is recorded first.** If `failed_jobs` cannot be written (the database is down), the job is not deleted: it goes back on the queue for 60 seconds and recording is tried again when it returns. It is not run again. `failed()` and `JobFailed` wait until the failure is recorded. Until then the error log shows "the failure could not be recorded".
  - **Errors after that are only logged:** a `JobFailed` listener that throws (for example an alerting service that is down), an error in `failed()`, or a failing log write do not stop the worker and do not fail the job a second time.
  - **A job that crashed or timed out on every try** ("attempted more times than its tries") is built again only to call its `failed()`; `handle()` does not run.
  - **At least once, not exactly once:** if the worker dies, or cannot delete the job, right after recording, the job comes back after `retry_after` and is failed again. `failed_jobs` keeps one row (the same payload is recognised), but `failed()` and `JobFailed` can run twice. Write them so that a repeat does no harm.
- Jobs that can never run fail at once, without retries (`UnrunnableJobException`): the class was renamed or deleted, the constructor changed, `handle()` asks for something that cannot be injected, or a model was deleted.

```bash
php veyro queue:failed            # list
php veyro queue:failed --id=3     # one job with its error and stack trace
php veyro queue:retry 3           # back on its queue (also: queue:retry all)
php veyro queue:forget 3
php veyro queue:flush             # delete all failed jobs
```

## Running workers

`php veyro queue:work [connection] [options]`:

| Option | |
| --- | --- |
| `--queue=emails,default` | Queues in priority order: `default` is only worked when `emails` is empty. |
| `--once` | Run one job, then stop. |
| `--stop-when-empty` | Stop when every queue is empty (for cron-style batches). |
| `--max-jobs=1000`, `--max-time=3600` | Stop after this many jobs or seconds, so a process manager starts a fresh worker (avoids memory growth). |
| `--memory=128` | Stop when memory use passes this many MB. |
| `--sleep=3` | Seconds to wait when the queues are empty. |

A worker runs until it is stopped. On a server, let a process manager keep it running and restart it; see the [deployment guide](../deployment.md#queue-workers). After deploying new code, run `php veyro queue:restart`: workers finish their current job and exit, and the process manager starts them with the new code.

**Timeouts and crashes:**

- On Linux and macOS (PHP's `pcntl` extension), a job running longer than `$timeout` stops the worker; the job counts the attempt and is retried. `SIGTERM` lets the current job finish first.
- On Windows there is no `pcntl`, so a hanging job is not stopped, and `Ctrl+C` stops at once. The worker logs a warning about this when it starts.
- In every case a job whose worker disappeared is given to another worker after `retry_after` seconds (config, default 90). Keep it longer than your slowest job, or a slow job may run twice at the same time. VeyroPHP does not extend a reservation while a job runs.
- **`timeout` must be below `retry_after`:** on the database and Redis connections, pushing a job whose `$timeout` is not below the connection's `retry_after` throws a `QueueException`. Such a job could otherwise be started by a second worker while it still runs.
  - A job without `$timeout` (0) is not checked. A long-running job is allowed, but it is your job to keep it under `retry_after`.
  - The `sync` and `array` connections have no reservations and are not checked.
- **The timeout itself is only enforced where pcntl exists** (Linux, macOS): there, the worker stops a job that runs too long.
  - On Windows a job is not stopped, so `timeout < retry_after` is checked but not enforced while the job runs. Run production workers on Linux.

**After a job succeeds:** once `handle()` returns, the job counts as done. If removing it from the queue, writing the log or a `JobProcessed` listener fails afterwards, the error is logged. The job is not marked failed, `failed()` is not called, and it is not retried.

**Run a job twice safely:** a job can still run twice. This happens when the worker dies after `handle()` returned but before the job was removed from the queue, or when its reservation expires while it is still running. Neither the database nor the Redis driver can rule this out, so a job with external side effects (payments, mail, calls to other systems) should be idempotent. For example, record what was done under a unique key and skip work that is already recorded.

## Queued event listeners and mail

A listener that implements `VeyroPHP\Queue\ShouldQueue` runs in the worker instead of during the request. The event is then stored like a job, so its constructor must follow the same rules:

```php
final class SendWelcomeMail implements ShouldQueue
{
    public function __construct(private readonly Mailer $mailer) {}

    public function handle(UserRegistered $event): void { /* ... */ }
}
```

Mail classes can be queued directly:

```php
$mailer->queue(new OrderShipped($order->get('email')));   // instead of send()
```

A queued mail is tried 3 times (after 30 s, 2 min, 10 min).

## Testing

The starter app's `phpunit.xml` sets `QUEUE_CONNECTION=sync`, so queued jobs, listeners and mail run inside the test. To check *that* something was queued without running it, use an `array` connection (`'driver' => 'array'`) and look at its jobs:

```php
$driver = $container->make(Queue::class)->driver('array');
// ... run the code under test ...
$driver->jobs(SendInvoice::class);   // decoded payloads of queued SendInvoice jobs
```

## Security

- Payloads contain only what you passed to the constructor. Do not pass passwords, tokens or card numbers; pass an id and load the data in `handle()`.
- Payloads are JSON, and running one can only call the constructor of a class implementing `Job`, with JSON values, enums or models. Someone who can write to the `jobs` table or to Redis still cannot run arbitrary code the way PHP object injection allows. They can, however, queue your own jobs with chosen arguments, so protect the database and Redis like the rest of your data.
- Failed jobs keep their payload and the error message with a stack trace (without function arguments). Limit who can read `failed_jobs`.
