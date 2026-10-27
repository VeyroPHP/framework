<?php

declare(strict_types=1);

namespace VeyroPHP\Queue\Console;

use VeyroPHP\Console\Argument;
use VeyroPHP\Console\AsCommand;
use VeyroPHP\Console\Command;
use VeyroPHP\Console\Input;
use VeyroPHP\Console\Output;
use VeyroPHP\Queue\Failed\FailedJobStore;

#[AsCommand('queue:forget', 'Delete one failed job')]
final class ForgetCommand extends Command
{
    public function __construct(private readonly FailedJobStore $failed) {}

    public function definition(): array
    {
        return [Argument::required('id', 'Failed job id')];
    }

    public function execute(Input $input, Output $output): int
    {
        $id = (string) $input->argument('id');

        if (!$this->failed->forget($id)) {
            $output->error(sprintf('No failed job with id %s.', $id));

            return self::FAILURE;
        }

        $output->success(sprintf('Deleted failed job %s.', $id));

        return self::SUCCESS;
    }
}
