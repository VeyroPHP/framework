<?php

declare(strict_types=1);

namespace VeyroPHP\Database\Console;

use VeyroPHP\Console\AsCommand;
use VeyroPHP\Console\Command;
use VeyroPHP\Console\Input;
use VeyroPHP\Console\Output;
use VeyroPHP\Database\Migration\Migrator;

#[AsCommand('migrate:status', 'Show which migrations have run')]
final class StatusCommand extends Command
{
    public function __construct(private readonly Migrator $migrator) {}

    public function execute(Input $input, Output $output): int
    {
        $rows = array_map(
            static fn(array $row): array => [$row['ran'] ? 'Ran' : 'Pending', $row['batch'] === null ? '-' : (string) $row['batch'], $row['migration']],
            $this->migrator->status(),
        );

        if ($rows === []) {
            $output->line('No migrations found.');

            return self::SUCCESS;
        }

        $output->table(['Status', 'Batch', 'Migration'], $rows);

        return self::SUCCESS;
    }
}
