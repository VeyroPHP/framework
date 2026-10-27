<?php

declare(strict_types=1);

namespace VeyroPHP\Scheduling\Console;

use VeyroPHP\Console\AsCommand;
use VeyroPHP\Console\Command;
use VeyroPHP\Console\Input;
use VeyroPHP\Console\Output;
use VeyroPHP\Scheduling\ScheduleRunner;

#[AsCommand('schedule:run', 'Run the scheduled tasks that are due now (call it every minute from cron)')]
final class RunCommand extends Command
{
    public function __construct(private readonly ScheduleRunner $runner) {}

    public function definition(): array
    {
        return [];
    }

    public function execute(Input $input, Output $output): int
    {
        $result = $this->runner->run($output);

        if ($result['ran'] + $result['skipped'] + $result['failed'] === 0) {
            $output->line('No scheduled tasks are due.');
        }

        return $result['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
