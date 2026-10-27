<?php

declare(strict_types=1);

namespace VeyroPHP\Console\Make;

use VeyroPHP\Console\Argument;
use VeyroPHP\Console\AsCommand;
use VeyroPHP\Console\Command;
use VeyroPHP\Console\Input;
use VeyroPHP\Console\Output;

#[AsCommand('make:seeder', 'Create a seeder class in database/seeders')]
final class MakeSeederCommand extends Command
{
    public function __construct(private readonly Generator $generator) {}

    public function definition(): array
    {
        return [Argument::required('name', 'Class name, e.g. ProductSeeder')];
    }

    public function execute(Input $input, Output $output): int
    {
        [$namespace, $class, $path] = Generator::target((string) $input->argument('name'), 'Database\\Seeders', 'database/seeders');

        $contents = <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace};

            use VeyroPHP\\Database\\Seeding\\Seeder;

            /**
             * Run with: php veyro db:seed --class="{$namespace}\\{$class}"
             * Constructor parameters (factories, Orm, Connection, ...) are injected.
             */
            final class {$class} extends Seeder
            {
                public function run(): void
                {
                }
            }

            PHP;

        $output->success('Created ' . $this->generator->write($path, $contents));

        return self::SUCCESS;
    }
}
