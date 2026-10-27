<?php

declare(strict_types=1);

namespace VeyroPHP\Cache\Console;

use VeyroPHP\Cache\CacheManager;
use VeyroPHP\Console\AsCommand;
use VeyroPHP\Console\Command;
use VeyroPHP\Console\Input;
use VeyroPHP\Console\Option;
use VeyroPHP\Console\Output;

#[AsCommand('cache:prune', 'Delete expired cache entries and sessions from disk')]
final class PruneCommand extends Command
{
    public function __construct(private readonly CacheManager $caches) {}

    public function definition(): array
    {
        return [Option::value('store', 'Cache store name from config/cache.php (default: the default store)')];
    }

    public function help(): string
    {
        return "Sessions and cache entries that are never read again stay on disk until pruned.\n"
            . 'Run this regularly, for example every 30 minutes from cron: php veyro cache:prune';
    }

    public function execute(Input $input, Output $output): int
    {
        $name = $input->option('store') ?? $this->caches->defaultStore();
        $removed = $this->caches->store($name)->prune();

        $output->success(sprintf('Removed %d expired %s from the "%s" store.', $removed, $removed === 1 ? 'entry' : 'entries', $name));

        return self::SUCCESS;
    }
}
