<?php

declare(strict_types=1);

namespace VeyroPHP\Queue;

use Closure;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;
use VeyroPHP\Queue\Driver\Driver;
use VeyroPHP\Queue\Driver\ReservedJob;
use VeyroPHP\Queue\Events\JobFailed;
use VeyroPHP\Queue\Events\JobProcessed;
use VeyroPHP\Queue\Failed\FailedJobStore;

/**
 * Takes jobs from queues and runs them.
 *
 * - A job that throws is retried after its backoff until its tries are used
 *   up; then it is deleted, stored as failed, its failed() method is called and
 *   JobFailed is dispatched. A job that can never run (UnrunnableJobException:
 *   deleted model or class, changed constructor, invalid payload) fails at once.
 * - A job whose attempts already exceed its tries (its worker crashed or was
 *   killed mid-job, for example by the timeout) fails without running again.
 * - Where pcntl exists (Linux, macOS), a job running longer than its timeout
 *   ends the worker process; the job becomes available after retry_after and
 *   counts the attempt. SIGTERM and SIGINT let the current job finish first.
 * - Per-request (scoped) services are reset after each job.
 * - "queue:restart" makes running workers stop after their current job, so a
 *   process manager starts them again with new code.
 */
final class Worker
{
    public const RESTART_KEY = 'veyrophp.queue.restart';

    /** Seconds before a failed job whose failure could not be recorded is tried again. */
    public const RECORD_RETRY_DELAY = 60;

    private bool $shouldQuit = false;

    /** @var Closure(int): void */
    private readonly Closure $sleep;

    /** @var Closure(): int */
    private readonly Closure $clock;

    /**
     * @param (Closure(): void)|null $afterJob called after every job (resets scoped services)
     * @param (Closure(int): void)|null $sleep
     * @param (Closure(): int)|null $clock
     */
    public function __construct(
        private readonly Queue $queue,
        private readonly JobRunner $runner,
        private readonly ?FailedJobStore $failed = null,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?EventDispatcherInterface $events = null,
        private readonly ?CacheInterface $cache = null,
        private readonly ?Closure $afterJob = null,
        ?Closure $sleep = null,
        ?Closure $clock = null,
    ) {
        $this->sleep = $sleep ?? static function (int $seconds): void {
            sleep($seconds);
        };
        $this->clock = $clock ?? static fn(): int => time();
    }

    /**
     * Runs jobs until a stop condition is met.
     *
     * @param list<string> $queues checked in this order (the first has priority)
     *
     * @return string why the worker stopped
     */
    public function daemon(string $connection, array $queues, WorkerOptions $options): string
    {
        if (!function_exists('pcntl_alarm') || !function_exists('pcntl_signal')) {
            $this->logger->warning('Job timeouts are not enforced: pcntl is not available (Windows, or PHP built without pcntl). A job that hangs keeps this worker busy, and after retry_after another worker may run it again while it still runs. Set retry_after above your longest job, and run production workers where pcntl exists.', ['connection' => $connection]);
        }

        $this->listenForSignals();
        $started = ($this->clock)();
        $restartAt = $this->restartSignal();
        $jobs = 0;

        while (true) {
            $processed = $this->runNextJob($connection, $queues);
            $jobs += $processed ? 1 : 0;

            $reason = match (true) {
                $this->shouldQuit => 'stop signal',
                $restartAt !== $this->restartSignal() => 'restart requested (queue:restart)',
                $options->maxJobs > 0 && $jobs >= $options->maxJobs => sprintf('processed %d jobs (--max-jobs)', $jobs),
                $options->maxTime > 0 && ($this->clock)() - $started >= $options->maxTime => sprintf('ran %d seconds (--max-time)', $options->maxTime),
                $options->memoryMb > 0 && memory_get_usage(true) / 1048576 >= $options->memoryMb => sprintf('memory above %d MB (--memory)', $options->memoryMb),
                !$processed && $options->stopWhenEmpty => 'queue empty (--stop-when-empty)',
                default => null,
            };

            if ($reason !== null) {
                return $reason;
            }

            if (!$processed) {
                ($this->sleep)($options->sleep);
            }
        }
    }

    /**
     * Runs one job from the first non-empty queue; false when all are empty.
     *
     * @param list<string> $queues
     */
    public function runNextJob(string $connection, array $queues): bool
    {
        $driver = $this->queue->driver($connection);

        foreach ($queues as $queue) {
            $job = $driver->pop($queue);

            if ($job !== null) {
                $this->process($connection, $driver, $job);

                return true;
            }
        }

        return false;
    }

    public function process(string $connection, Driver $driver, ReservedJob $reserved): void
    {
        $start = hrtime(true);
        $class = 'unknown';
        $instance = null;
        $exhausted = null;

        try {
            $payload = JobRunner::decode($reserved->payload);
            $class = is_string($payload['job']['class'] ?? null) ? $payload['job']['class'] : 'unknown';

            if ($reserved->attempts > $payload['tries']) {
                $exhausted = new QueueException(sprintf(
                    '%s was attempted %d times (tries: %d); the worker probably crashed, hit the timeout, or its failure could not be recorded.',
                    $class,
                    $reserved->attempts,
                    $payload['tries'],
                ));

                // Built (not run) only so failed() can be called once the failure is final.
                try {
                    $instance = $this->runner->instantiate($payload['job']);
                } catch (\Throwable) {
                    $instance = null;
                }
            } else {
                $instance = $this->runner->instantiate($payload['job']);
                $this->withTimeout($payload['timeout'], $class, fn() => $this->runner->handle($instance));
            }
        } catch (\Throwable $e) {
            $this->finish(fn() => $this->handleFailure($connection, $driver, $reserved, $class, $instance, $e));

            return;
        }

        // Outside the try above: a problem while failing an exhausted job must not be
        // treated as one more failure of the job (it used to fail it twice).
        $this->finish($exhausted !== null
            ? fn() => $this->fail($connection, $driver, $reserved, $class, $instance, $exhausted)
            : fn() => $this->completed($connection, $driver, $reserved, $class, $start));
    }

    /**
     * @param Closure(): void $step
     */
    private function finish(Closure $step): void
    {
        try {
            $step();
        } finally {
            if ($this->afterJob !== null) {
                ($this->afterJob)();
            }
        }
    }

    /**
     * Bookkeeping after handle() returned. The job's work is done, so nothing here may
     * mark it failed or run it again: errors (the queue store, the log, a JobProcessed
     * listener) are only reported.
     *
     * Crash window: if the process dies, or delete() fails, after handle() returned
     * but before the job is removed, the reservation expires after retry_after and the
     * job runs again. Jobs with external side effects must therefore be idempotent.
     */
    private function completed(string $connection, Driver $driver, ReservedJob $reserved, string $class, int|float $start): void
    {
        try {
            $driver->delete($reserved);
        } catch (\Throwable $e) {
            $this->report('Job completed but could not be removed from the queue; it may run again after retry_after', $class, $reserved, $e);

            return;
        }

        try {
            $this->logger->info('Job processed', [
                'job' => $class,
                'id' => $reserved->id,
                'queue' => $reserved->queue,
                'attempt' => $reserved->attempts,
                'ms' => round((hrtime(true) - $start) / 1e6, 1),
            ]);
        } catch (\Throwable) {
            // A logging failure does not change the outcome of a finished job.
        }

        try {
            $this->events?->dispatch(new JobProcessed($connection, $reserved->queue, $class, $reserved->id, $reserved->attempts));
        } catch (\Throwable $e) {
            $this->report('A listener failed after the job completed; the job is not retried', $class, $reserved, $e);
        }
    }

    private function report(string $message, string $class, ReservedJob $reserved, \Throwable $e): void
    {
        try {
            $this->logger->error($message, ['job' => $class, 'id' => $reserved->id, 'queue' => $reserved->queue, 'error' => $e::class . ': ' . $e->getMessage()]);
        } catch (\Throwable) {
            // Nothing else can report it.
        }
    }

    private function handleFailure(string $connection, Driver $driver, ReservedJob $reserved, string $class, ?Job $instance, \Throwable $e): void
    {
        try {
            $payload = JobRunner::decode($reserved->payload);
        } catch (UnrunnableJobException) {
            $this->fail($connection, $driver, $reserved, $class, null, $e);

            return;
        }

        if ($e instanceof UnrunnableJobException || $reserved->attempts >= $payload['tries']) {
            $this->fail($connection, $driver, $reserved, $class, $instance, $e);

            return;
        }

        $backoff = $payload['backoff'] === [] ? 0 : $payload['backoff'][min($reserved->attempts, count($payload['backoff'])) - 1];
        $driver->release($reserved, $backoff);

        try {
            $this->logger->warning('Job failed, will retry', [
                'job' => $class,
                'id' => $reserved->id,
                'attempt' => $reserved->attempts,
                'tries' => $payload['tries'],
                'retry_in' => $backoff,
                'error' => $e::class . ': ' . $e->getMessage(),
            ]);
        } catch (\Throwable) {
            // The job is already released for its retry; a logging failure changes nothing.
        }
    }

    /**
     * Marks a job as finally failed, in this order:
     *
     * 1. Record it in the failed-jobs store. If that fails, the job is NOT deleted: it
     *    is released for RECORD_RETRY_DELAY seconds and comes back as exhausted, so the
     *    recording is tried again. Nothing else happens yet, so failed() and JobFailed
     *    never announce a failure that was not recorded.
     * 2. Remove it from the queue.
     * 3. Call the job's failed(), log, dispatch JobFailed. Errors in these are only
     *    reported: the failure is already final.
     *
     * Without a failed-jobs store the log entry is the only record (as before).
     *
     * Not absolute: if the process dies, or delete() fails, after step 1, the job
     * comes back after retry_after and is failed again. DatabaseFailedJobStore keeps
     * one entry for the same payload, but failed() and JobFailed can then run twice
     * (at least once, not exactly once).
     */
    private function fail(string $connection, Driver $driver, ReservedJob $reserved, string $class, ?Job $instance, \Throwable $e): void
    {
        $failedId = null;

        if ($this->failed !== null) {
            try {
                $failedId = $this->failed->record($connection, $reserved->queue, $reserved->payload, $e);
            } catch (\Throwable $storeError) {
                $this->report('Job failed, but the failure could not be recorded; it stays in the queue and recording is tried again', $class, $reserved, $storeError);

                try {
                    $driver->release($reserved, self::RECORD_RETRY_DELAY);
                } catch (\Throwable $releaseError) {
                    $this->report('The job could not be released either; it returns after retry_after', $class, $reserved, $releaseError);
                }

                return;
            }
        }

        try {
            $driver->delete($reserved);
        } catch (\Throwable $deleteError) {
            $this->report('Job failed and was recorded, but could not be removed from the queue; it comes back after retry_after', $class, $reserved, $deleteError);
        }

        if ($instance !== null && method_exists($instance, 'failed')) {
            try {
                $instance->failed($e);
            } catch (\Throwable $inner) {
                $this->report('failed() of a job threw', $class, $reserved, $inner);
            }
        }

        try {
            $this->logger->error('Job failed', [
                'job' => $class,
                'id' => $reserved->id,
                'queue' => $reserved->queue,
                'attempt' => $reserved->attempts,
                'failed_id' => $failedId,
                'error' => $e::class . ': ' . $e->getMessage(),
            ]);
        } catch (\Throwable) {
            // The failure is recorded; a logging failure changes nothing.
        }

        try {
            $this->events?->dispatch(new JobFailed($connection, $reserved->queue, $class, $reserved->id, $e));
        } catch (\Throwable $listenerError) {
            $this->report('A JobFailed listener threw; the job stays failed and the worker goes on', $class, $reserved, $listenerError);
        }
    }

    /**
     * @param Closure(): void $run
     */
    private function withTimeout(int $seconds, string $class, Closure $run): void
    {
        if ($seconds <= 0 || !function_exists('pcntl_alarm') || !function_exists('pcntl_signal')) {
            $run();

            return;
        }

        pcntl_async_signals(true);
        pcntl_signal(SIGALRM, function () use ($class, $seconds): void {
            $this->logger->error('Job timed out; stopping the worker', ['job' => $class, 'timeout' => $seconds]);

            exit(1);
        });
        pcntl_alarm($seconds);

        try {
            $run();
        } finally {
            pcntl_alarm(0);
        }
    }

    private function listenForSignals(): void
    {
        if (!function_exists('pcntl_signal') || !function_exists('pcntl_async_signals')) {
            return;
        }

        pcntl_async_signals(true);

        foreach ([SIGTERM, SIGINT, SIGQUIT] as $signal) {
            pcntl_signal($signal, function (): void {
                $this->shouldQuit = true;
            });
        }
    }

    private function restartSignal(): mixed
    {
        try {
            return $this->cache?->get(self::RESTART_KEY);
        } catch (\Throwable) {
            return null;
        }
    }
}
