<?php

declare(strict_types=1);

namespace VeyroPHP\Config\Console;

use VeyroPHP\Application\Application;
use VeyroPHP\Config\ConfigCache;
use VeyroPHP\Console\AsCommand;
use VeyroPHP\Console\Command;
use VeyroPHP\Console\Input;
use VeyroPHP\Console\Output;

#[AsCommand('config:clear', 'Remove the cached configuration, so .env and config/ are read again')]
final class ClearCommand extends Command
{
    public function __construct(private readonly Application $app) {}

    public function execute(Input $input, Output $output): int
    {
        $removed = ConfigCache::clear($this->app->basePath(ConfigCache::RELATIVE_PATH));
        $output->success($removed ? 'Configuration cache cleared.' : 'There was no configuration cache.');

        return self::SUCCESS;
    }
}
