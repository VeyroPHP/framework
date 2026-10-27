<?php

declare(strict_types=1);

namespace VeyroPHP\Queue\Console;

use VeyroPHP\Console\AsCommand;
use VeyroPHP\Console\Command;
use VeyroPHP\Console\Input;
use VeyroPHP\Console\Output;
use VeyroPHP\Queue\Failed\FailedJobStore;

#[AsCommand('queue:flush', 'Delete all failed jobs')]
final class FlushCommand extends Command
{
    public function __construct(private readonly FailedJobStore $failed) {}

    public function definition(): array
    {
        return [];
    }

    public function execute(Input $input, Output $output): int
    {
        $output->success(sprintf('Deleted %d failed jobs.', $this->failed->flush()));

        return self::SUCCESS;
    }
}
