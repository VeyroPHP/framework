<?php

declare(strict_types=1);

namespace VeyroPHP\Database\Console;

use VeyroPHP\Config\Config;
use VeyroPHP\Console\AsCommand;
use VeyroPHP\Console\Command;
use VeyroPHP\Console\Input;
use VeyroPHP\Console\Option;
use VeyroPHP\Console\Output;
use VeyroPHP\Database\Seeding\Seeder;
use VeyroPHP\Database\Seeding\SeederRunner;

#[AsCommand('db:seed', 'Fill the database with data from seeders')]
final class SeedCommand extends Command
{
    public const DEFAULT_SEEDER = 'Database\\Seeders\\DatabaseSeeder';

    public function __construct(
        private readonly SeederRunner $runner,
        private readonly Config $config,
    ) {}

    public function definition(): array
    {
        return [
            Option::value('class', 'Seeder class to run', self::DEFAULT_SEEDER),
            ProductionGuard::option(),
        ];
    }

    public function execute(Input $input, Output $output): int
    {
        if (ProductionGuard::blocks($this->config, $input, $output)) {
            return self::FAILURE;
        }

        $class = ltrim((string) $input->option('class'), '\\');

        if (!class_exists($class) || !is_subclass_of($class, Seeder::class)) {
            $output->error(sprintf('Seeder %s not found (it must extend %s; create one with "php veyro make:seeder").', $class, Seeder::class));

            return self::USAGE;
        }

        $this->runner->run($class, static function (string $seeder) use ($output): void {
            $output->line($output->style('Seeding:', 'success') . ' ' . $seeder);
        });
        $output->success('Database seeded.');

        return self::SUCCESS;
    }
}
