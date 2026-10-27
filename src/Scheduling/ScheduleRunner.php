<?php

declare(strict_types=1);

namespace VeyroPHP\Scheduling;

use Closure;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use VeyroPHP\Cache\Cache;
use VeyroPHP\Console\Output;

/**
 * Runs the tasks that are due in one minute ("schedule:run").
 *
 * - Tasks run one after another, in the order they were defined.
 * - A failing task is logged and reported; the others still run.
 * - withoutOverlapping() tasks are skipped while their lock is held.
 */
final class ScheduleRunner
{
    /** @var Closure(): DateTimeImmutable */
    private readonly Closure $clock;

    /**
     * @param (Closure(): DateTimeImmutable)|null $clock
     */
    public function __construct(
        private readonly Schedule $schedule,
        private readonly string $environment,
        private readonly ?Cache $cache = null,
        private readonly LoggerInterface $logger = new NullLogger(),
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn(): DateTimeImmutable => new DateTimeImmutable();
    }

    public function now(): DateTimeImmutable
    {
        return ($this->clock)();
    }

    /**
     * @return array{ran: int, skipped: int, failed: int}
     */
    public function run(Output $output, ?DateTimeImmutable $now = null): array
    {
        $now ??= $this->now();
        $result = ['ran' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($this->schedule->dueEvents($now, $this->environment) as $event) {
            $output->line(sprintf('[%s] Running %s', $now->format('Y-m-d H:i'), $event->description()));
            $start = hrtime(true);

            try {
                $ran = $this->runEvent($event, $output);
            } catch (\Throwable $e) {
                $result['failed']++;
                $output->error(sprintf('Failed: %s: %s', $event->description(), $e->getMessage()));
                $this->logger->error('Scheduled task failed', ['task' => $event->description(), 'error' => $e::class . ': ' . $e->getMessage()]);

                continue;
            }

            if (!$ran) {
                $result['skipped']++;
                $output->line('  skipped: still running from an earlier minute (withoutOverlapping)');
                $this->logger->info('Scheduled task skipped (still running)', ['task' => $event->description()]);

                continue;
            }

            $result['ran']++;
            $ms = round((hrtime(true) - $start) / 1e6, 1);
            $output->line(sprintf('  done in %s ms', $ms));
            $this->logger->info('Scheduled task ran', ['task' => $event->description(), 'ms' => $ms]);
        }

        return $result;
    }

    private function runEvent(Event $event, Output $output): bool
    {
        $minutes = $event->overlapMinutes();

        if ($minutes === null || $this->cache === null) {
            $event->run($output);

            return true;
        }

        return $this->cache->tryLock($event->id(), $minutes * 60, static fn() => $event->run($output));
    }
}
