<?php

declare(strict_types=1);

namespace VeyroPHP\Queue\Console;

use VeyroPHP\Console\AsCommand;
use VeyroPHP\Console\Command;
use VeyroPHP\Console\Input;
use VeyroPHP\Console\Option;
use VeyroPHP\Console\Output;
use VeyroPHP\Queue\Failed\FailedJobStore;

#[AsCommand('queue:failed', 'List failed jobs (newest first)')]
final class FailedCommand extends Command
{
    public function __construct(private readonly FailedJobStore $failed) {}

    public function definition(): array
    {
        return [
            Option::value('limit', 'How many to show', '50'),
            Option::value('id', 'Show one failed job in full, with its error'),
        ];
    }

    public function execute(Input $input, Output $output): int
    {
        $id = $input->option('id');

        if ($id !== null) {
            $job = $this->failed->find($id);

            if ($job === null) {
                $output->error(sprintf('No failed job with id %s.', $id));

                return self::FAILURE;
            }

            $output->line(sprintf("Job:        %s\nQueue:      %s (%s)\nFailed at:  %s UTC\nPayload:    %s\n\n%s", $job->jobClass(), $job->queue, $job->connection, $job->failedAt, $job->payload, $job->exception));

            return self::SUCCESS;
        }

        $jobs = $this->failed->all($input->positiveInt('limit') ?? 50);

        if ($jobs === []) {
            $output->line('No failed jobs.');

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($jobs as $job) {
            $firstLine = strtok($job->exception, "\n");
            $rows[] = [$job->id, $job->jobClass(), $job->queue, $job->failedAt, mb_strimwidth($firstLine === false ? '' : $firstLine, 0, 80, '...')];
        }

        $output->table(['ID', 'Job', 'Queue', 'Failed at (UTC)', 'Error'], $rows);
        $output->line('Retry with "php veyro queue:retry <id>" or "queue:retry all"; details with "queue:failed --id=<id>".');

        return self::SUCCESS;
    }
}
