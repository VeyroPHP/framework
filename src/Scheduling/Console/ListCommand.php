<?php

declare(strict_types=1);

namespace VeyroPHP\Scheduling\Console;

use VeyroPHP\Console\AsCommand;
use VeyroPHP\Console\Command;
use VeyroPHP\Console\Input;
use VeyroPHP\Console\Output;
use VeyroPHP\Scheduling\Schedule;
use VeyroPHP\Scheduling\ScheduleRunner;

#[AsCommand('schedule:list', 'List the scheduled tasks and when they run next')]
final class ListCommand extends Command
{
    public function __construct(
        private readonly Schedule $schedule,
        private readonly ScheduleRunner $runner,
    ) {}

    public function definition(): array
    {
        return [];
    }

    public function execute(Input $input, Output $output): int
    {
        $events = $this->schedule->events();

        if ($events === []) {
            $output->line('No scheduled tasks. Define them in routes/schedule.php.');

            return self::SUCCESS;
        }

        $now = $this->runner->now();
        $rows = [];

        foreach ($events as $event) {
            $rows[] = [
                $event->expression(),
                $event->timezoneName() ?? date_default_timezone_get(),
                $event->nextRunDate($now)->format('Y-m-d H:i'),
                $event->description() . ($event->overlapMinutes() === null ? '' : ' (no overlap)'),
            ];
        }

        $output->table(['Cron', 'Time zone', 'Next run', 'Task'], $rows);

        return self::SUCCESS;
    }
}
