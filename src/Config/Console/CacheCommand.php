<?php

declare(strict_types=1);

namespace VeyroPHP\Config\Console;

use VeyroPHP\Application\Application;
use VeyroPHP\Config\ConfigCache;
use VeyroPHP\Config\ConfigServiceProvider;
use VeyroPHP\Console\AsCommand;
use VeyroPHP\Console\Command;
use VeyroPHP\Console\Input;
use VeyroPHP\Console\Output;

#[AsCommand('config:cache', 'Combine .env and config/*.php into one cached file for faster requests')]
final class CacheCommand extends Command
{
    public function __construct(private readonly Application $app) {}

    public function help(): string
    {
        return "Run it on every deploy, after changing .env or config files: the cached file is used\n"
            . "instead of them until \"php veyro config:clear\". Not recommended during development.\n"
            . 'The file contains every config value, including secrets from .env.';
    }

    public function execute(Input $input, Output $output): int
    {
        // Always from the source files, never from an existing cache.
        $config = ConfigServiceProvider::fresh($this->app);
        ConfigCache::write($config, $this->app->basePath(ConfigCache::RELATIVE_PATH));

        $output->success('Configuration cached: ' . ConfigCache::RELATIVE_PATH);
        $output->line('Changes to .env and config/ now need "php veyro config:cache" again.');

        return self::SUCCESS;
    }
}
