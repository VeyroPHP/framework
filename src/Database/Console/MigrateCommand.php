<?php

declare(strict_types=1);

namespace VeyroPHP\Database\Console;

use VeyroPHP\Config\Config;
use VeyroPHP\Console\AsCommand;
use VeyroPHP\Console\Command;
use VeyroPHP\Console\Input;
use VeyroPHP\Console\Output;
use VeyroPHP\Database\Migration\Migrator;

#[AsCommand('migrate', 'Run pending migrations')]
final class MigrateCommand extends Command
{
    public function __construct(
        private readonly Migrator $migrator,
        private readonly Config $config,
    ) {}

    public function definition(): array
    {
        return [ProductionGuard::option()];
    }

    public function execute(Input $input, Output $output): int
    {
        if (ProductionGuard::blocks($this->config, $input, $output)) {
            return self::FAILURE;
        }

        $ran = $this->migrator->run();

        if ($ran === []) {
            $output->line('Nothing to migrate.');
        }

        foreach ($ran as $migration) {
            $output->line($output->style('Migrated:', 'success') . '  ' . $migration);
        }

        return self::SUCCESS;
    }
}
