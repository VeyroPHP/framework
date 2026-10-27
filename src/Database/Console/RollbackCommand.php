<?php

declare(strict_types=1);

namespace VeyroPHP\Database\Console;

use VeyroPHP\Config\Config;
use VeyroPHP\Console\AsCommand;
use VeyroPHP\Console\Command;
use VeyroPHP\Console\Input;
use VeyroPHP\Console\Option;
use VeyroPHP\Console\Output;
use VeyroPHP\Database\Migration\Migrator;

#[AsCommand('migrate:rollback', 'Roll back the last batch(es) of migrations')]
final class RollbackCommand extends Command
{
    public function __construct(
        private readonly Migrator $migrator,
        private readonly Config $config,
    ) {}

    public function definition(): array
    {
        return [Option::value('step', 'Number of batches to roll back', '1'), ProductionGuard::option()];
    }

    public function execute(Input $input, Output $output): int
    {
        $step = $input->positiveInt('step') ?? 1;

        if (ProductionGuard::blocks($this->config, $input, $output)) {
            return self::FAILURE;
        }

        $rolledBack = $this->migrator->rollback($step);

        if ($rolledBack === []) {
            $output->line('Nothing to roll back.');
        }

        foreach ($rolledBack as $migration) {
            $output->line($output->style('Rolled back:', 'warning') . '  ' . $migration);
        }

        return self::SUCCESS;
    }
}
