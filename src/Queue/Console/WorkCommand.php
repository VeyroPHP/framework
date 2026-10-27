<?php

declare(strict_types=1);

namespace VeyroPHP\Queue\Console;

use VeyroPHP\Console\Argument;
use VeyroPHP\Console\AsCommand;
use VeyroPHP\Console\Command;
use VeyroPHP\Console\Input;
use VeyroPHP\Console\Option;
use VeyroPHP\Console\Output;
use VeyroPHP\Console\UsageException;
use VeyroPHP\Queue\Queue;
use VeyroPHP\Queue\Worker;
use VeyroPHP\Queue\WorkerOptions;

#[AsCommand('queue:work', 'Run queued jobs')]
final class WorkCommand extends Command
{
    public function __construct(
        private readonly Worker $worker,
        private readonly Queue $queue,
    ) {}

    public function definition(): array
    {
        return [
            Argument::optional('connection', 'Queue connection from config/queue.php (default: queue.default)'),
            Option::value('queue', 'Queues to work, in priority order, e.g. emails,default'),
            Option::flag('once', 'Run one job (or none) and stop'),
            Option::flag('stop-when-empty', 'Stop when every queue is empty'),
            Option::value('max-jobs', 'Stop after this many jobs', '0'),
            Option::value('max-time', 'Stop after this many seconds', '0'),
            Option::value('sleep', 'Seconds to wait when the queues are empty', '3'),
            Option::value('memory', 'Stop when memory use passes this many MB (0: no limit)', '128'),
        ];
    }

    public function execute(Input $input, Output $output): int
    {
        $connection = $input->argument('connection');
        $connection = is_string($connection) && $connection !== '' ? $connection : $this->queue->defaultConnection();
        $queues = array_values(array_filter(array_map('trim', explode(',', $input->option('queue') ?? $this->queue->defaultQueue($connection)))));

        if ($queues === []) {
            $output->error('--queue needs at least one queue name.');

            return self::USAGE;
        }

        if ($input->flag('once')) {
            $processed = $this->worker->runNextJob($connection, $queues);
            $output->line($processed ? 'Processed one job.' : 'No job was waiting.');

            return self::SUCCESS;
        }

        $output->info(sprintf('Working %s on connection "%s". Stop with Ctrl+C.', implode(', ', $queues), $connection));

        $reason = $this->worker->daemon($connection, $queues, new WorkerOptions(
            sleep: self::whole($input, 'sleep'),
            maxJobs: self::whole($input, 'max-jobs'),
            maxTime: self::whole($input, 'max-time'),
            memoryMb: self::whole($input, 'memory'),
            stopWhenEmpty: $input->flag('stop-when-empty'),
        ));

        $output->line('Worker stopped: ' . $reason . '.');

        return self::SUCCESS;
    }

    private static function whole(Input $input, string $name): int
    {
        $value = (string) $input->option($name);

        return ctype_digit($value)
            ? (int) $value
            : throw new UsageException(sprintf('The --%s option must be a whole number (0 or more).', $name));
    }
}
