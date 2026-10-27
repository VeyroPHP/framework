<?php

declare(strict_types=1);

namespace VeyroPHP\Scheduling\Console;

use Closure;
use VeyroPHP\Console\AsCommand;
use VeyroPHP\Console\Command;
use VeyroPHP\Console\Input;
use VeyroPHP\Console\Option;
use VeyroPHP\Console\Output;
use VeyroPHP\Scheduling\ScheduleRunner;

/**
 * Runs the schedule every minute in the foreground, for development and for
 * servers without cron (Windows). Production servers should use cron.
 */
#[AsCommand('schedule:work', 'Run the scheduler every minute in the foreground (development, Windows)')]
final class WorkCommand extends Command
{
    /** @var Closure(int): void */
    private readonly Closure $sleep;

    /**
     * @param (Closure(int): void)|null $sleep
     */
    public function __construct(private readonly ScheduleRunner $runner, ?Closure $sleep = null)
    {
        $this->sleep = $sleep ?? static function (int $seconds): void {
            sleep($seconds);
        };
    }

    public function definition(): array
    {
        return [Option::value('minutes', 'Stop after this many minutes (default: run until stopped)')];
    }

    public function execute(Input $input, Output $output): int
    {
        $limit = $input->positiveInt('minutes');
        $output->info('Running the schedule every minute. Stop with Ctrl+C.');
        $lastMinute = '';

        for ($minutes = 0; $limit === null || $minutes < $limit;) {
            $now = $this->runner->now();
            $minute = $now->format('Y-m-d H:i');

            if ($minute !== $lastMinute) {
                $this->runner->run($output, $now);
                $lastMinute = $minute;
                $minutes++;
            }

            if ($limit !== null && $minutes >= $limit) {
                break;
            }

            // Wake up just after the next minute starts.
            ($this->sleep)(max(1, 60 - (int) $now->format('s')));
        }

        return self::SUCCESS;
    }
}
