<?php

declare(strict_types=1);

namespace VeyroPHP\Queue\Console;

use VeyroPHP\Console\Argument;
use VeyroPHP\Console\AsCommand;
use VeyroPHP\Console\Command;
use VeyroPHP\Console\Input;
use VeyroPHP\Console\Output;
use VeyroPHP\Queue\Failed\FailedJob;
use VeyroPHP\Queue\Failed\FailedJobStore;
use VeyroPHP\Queue\Queue;
use VeyroPHP\Queue\QueueException;

#[AsCommand('queue:retry', 'Put failed jobs back on their queue')]
final class RetryCommand extends Command
{
    public function __construct(
        private readonly FailedJobStore $failed,
        private readonly Queue $queue,
    ) {}

    public function definition(): array
    {
        return [Argument::required('id', 'Failed job id, or "all"')];
    }

    public function execute(Input $input, Output $output): int
    {
        $id = (string) $input->argument('id');
        $jobs = $id === 'all' ? $this->failed->all(PHP_INT_MAX) : array_filter([$this->failed->find($id)]);

        if ($jobs === []) {
            $output->error($id === 'all' ? 'No failed jobs.' : sprintf('No failed job with id %s.', $id));

            return self::FAILURE;
        }

        $errors = 0;

        foreach ($jobs as $job) {
            try {
                $this->retry($job);
                $output->success(sprintf('Queued failed job %s (%s) again.', $job->id, $job->jobClass()));
            } catch (QueueException $e) {
                $errors++;
                $output->error(sprintf('Failed job %s: %s', $job->id, $e->getMessage()));
            }
        }

        return $errors === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * The original payload goes back unchanged, with a fresh attempt count.
     */
    private function retry(FailedJob $job): void
    {
        $this->queue->driver($job->connection)->push($job->queue, $job->payload);
        $this->failed->forget($job->id);
    }
}
