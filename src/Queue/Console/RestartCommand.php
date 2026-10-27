<?php

declare(strict_types=1);

namespace VeyroPHP\Queue\Console;

use Psr\SimpleCache\CacheInterface;
use VeyroPHP\Console\AsCommand;
use VeyroPHP\Console\Command;
use VeyroPHP\Console\Input;
use VeyroPHP\Console\Output;
use VeyroPHP\Queue\Worker;

#[AsCommand('queue:restart', 'Ask running workers to stop after their current job (run after deploying)')]
final class RestartCommand extends Command
{
    public function __construct(private readonly CacheInterface $cache) {}

    public function definition(): array
    {
        return [];
    }

    public function execute(Input $input, Output $output): int
    {
        $this->cache->set(Worker::RESTART_KEY, microtime(true) . ':' . bin2hex(random_bytes(4)));
        $output->success('Workers will stop after their current job; your process manager (systemd, Supervisor) starts them again.');

        return self::SUCCESS;
    }
}
