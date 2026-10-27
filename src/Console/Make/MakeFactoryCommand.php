<?php

declare(strict_types=1);

namespace VeyroPHP\Console\Make;

use VeyroPHP\Console\Argument;
use VeyroPHP\Console\AsCommand;
use VeyroPHP\Console\Command;
use VeyroPHP\Console\Input;
use VeyroPHP\Console\Option;
use VeyroPHP\Console\Output;

#[AsCommand('make:factory', 'Create a model factory in database/factories')]
final class MakeFactoryCommand extends Command
{
    public function __construct(private readonly Generator $generator) {}

    public function definition(): array
    {
        return [
            Argument::required('name', 'Class name, e.g. ProductFactory'),
            Option::value('model', 'Model class, e.g. Product or App\\Models\\Product (default: the name without "Factory")'),
        ];
    }

    public function execute(Input $input, Output $output): int
    {
        [$namespace, $class, $path] = Generator::target((string) $input->argument('name'), 'Database\\Factories', 'database/factories');

        $model = $input->option('model') ?? (str_ends_with($class, 'Factory') ? substr($class, 0, -7) : $class);
        $model = ltrim($model, '\\');

        if (!str_contains($model, '\\')) {
            $model = 'App\\Models\\' . $model;
        }

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)+$/D', $model) !== 1) {
            $output->error('Invalid model class name: ' . $model);

            return self::USAGE;
        }

        $short = substr($model, (int) strrpos($model, '\\') + 1);

        $contents = <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace};

            use {$model};
            use VeyroPHP\\Orm\\Factory\\Factory;

            /**
             * \$container->make({$class}::class)->count(10)->create();
             *
             * @extends Factory<{$short}>
             */
            final class {$class} extends Factory
            {
                public function model(): string
                {
                    return {$short}::class;
                }

                public function definition(): array
                {
                    return [
                        // 'name' => \$this->fake->name(),
                    ];
                }
            }

            PHP;

        $output->success('Created ' . $this->generator->write($path, $contents));

        return self::SUCCESS;
    }
}
