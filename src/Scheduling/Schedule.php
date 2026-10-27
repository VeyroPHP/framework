<?php

declare(strict_types=1);

namespace VeyroPHP\Scheduling;

use Closure;
use DateTimeImmutable;
use Psr\Container\ContainerInterface;
use ReflectionFunction;
use ReflectionNamedType;
use VeyroPHP\Console\Console;
use VeyroPHP\Console\Output;
use VeyroPHP\Queue\Job;
use VeyroPHP\Queue\Queue;

/**
 * The list of scheduled tasks, defined in routes/schedule.php:
 *
 *     $schedule->command('cache:prune')->everyThirtyMinutes();
 *     $schedule->command('auth:clear-resets')->dailyAt('03:00');
 *     $schedule->job(new SendWeeklyReport())->weeklyOn(1, '08:00')->timezone('Asia/Kolkata');
 *     $schedule->call(static function (Orm $orm): void { ... })->hourly()->name('Clean carts');
 *
 * A system cron entry runs "php veyro schedule:run" every minute; it runs
 * the tasks that are due, one after another.
 */
final class Schedule
{
    /** @var list<Event> */
    private array $events = [];

    public function __construct(private readonly ContainerInterface $container) {}

    /**
     * A console command, run in the same process. A non-zero exit code counts as a failure.
     *
     * @param list<string> $arguments e.g. ['--force']
     */
    public function command(string $name, array $arguments = []): Event
    {
        $container = $this->container;

        return $this->events[] = new Event(static function (Output $output) use ($container, $name, $arguments): void {
            $console = $container->get(Console::class);
            \assert($console instanceof Console);
            $code = $console->run([$name, ...$arguments], $output);

            if ($code !== 0) {
                throw new ScheduleException(sprintf('Command "%s" exited with code %d.', $name, $code));
            }
        }, trim('php veyro ' . $name . ' ' . implode(' ', $arguments)));
    }

    /**
     * Queues a job when due; a queue worker runs it.
     */
    public function job(Job $job, ?string $queue = null, ?string $connection = null): Event
    {
        $container = $this->container;

        return $this->events[] = new Event(static function () use ($container, $job, $queue, $connection): void {
            $queues = $container->get(Queue::class);
            \assert($queues instanceof Queue);
            $queues->push($job, $queue, $connection);
        }, 'job ' . $job::class);
    }

    /**
     * A closure. Parameters with a class or interface type are injected; an
     * Output parameter receives the schedule's output.
     */
    public function call(Closure $callback, ?string $description = null): Event
    {
        $container = $this->container;
        $reflection = new ReflectionFunction($callback);
        $description ??= sprintf('closure at %s:%d', basename((string) $reflection->getFileName()), (int) $reflection->getStartLine());

        return $this->events[] = new Event(static function (Output $output) use ($container, $callback, $reflection): void {
            $arguments = [];

            foreach ($reflection->getParameters() as $parameter) {
                $type = $parameter->getType();

                if ($type instanceof ReflectionNamedType && $type->getName() === Output::class) {
                    $arguments[] = $output;
                } elseif ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                    $arguments[] = $container->get($type->getName());
                } elseif ($parameter->isDefaultValueAvailable()) {
                    $arguments[] = $parameter->getDefaultValue();
                } else {
                    throw new ScheduleException(sprintf('Scheduled closure parameter $%s cannot be injected.', $parameter->getName()));
                }
            }

            $callback(...$arguments);
        }, $description);
    }

    /**
     * @return list<Event>
     */
    public function events(): array
    {
        return $this->events;
    }

    /**
     * @return list<Event>
     */
    public function dueEvents(DateTimeImmutable $now, string $environment): array
    {
        return array_values(array_filter($this->events, static fn(Event $event): bool => $event->isDue($now, $environment)));
    }
}
