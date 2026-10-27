<?php

declare(strict_types=1);

namespace VeyroPHP\Http\Console;

use VeyroPHP\Console\AsCommand;
use VeyroPHP\Console\Command;
use VeyroPHP\Console\Input;
use VeyroPHP\Console\Output;
use VeyroPHP\Http\Health\HealthCheck;

/**
 * Runs the health checks with times and error messages (the web endpoint hides the
 * messages). Exit code 1 when a check fails, so it can run from cron or a deploy script.
 */
#[AsCommand('health', 'Run the health checks and show the results')]
final class HealthCommand extends Command
{
    public function __construct(private readonly HealthCheck $health) {}

    public function execute(Input $input, Output $output): int
    {
        $report = $this->health->run();
        $rows = [];

        foreach ($report->results() as $name => $result) {
            $rows[] = [$name, $result['ok'] ? 'ok' : 'FAIL', $result['ms'] . ' ms', $result['error'] ?? ''];
        }

        if ($rows === []) {
            $output->info('No health checks are registered.');

            return self::SUCCESS;
        }

        $output->table(['Check', 'Result', 'Time', 'Error'], $rows);

        if (!$report->healthy()) {
            $output->error('At least one health check failed.');

            return self::FAILURE;
        }

        $output->success('All health checks passed.');

        return self::SUCCESS;
    }
}
