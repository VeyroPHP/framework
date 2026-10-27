<?php

declare(strict_types=1);

namespace VeyroPHP\Console\Make;

use VeyroPHP\Console\Argument;
use VeyroPHP\Console\AsCommand;
use VeyroPHP\Console\Command;
use VeyroPHP\Console\Input;
use VeyroPHP\Console\Output;

#[AsCommand('make:event', 'Create an event class in app/Events')]
final class MakeEventCommand extends Command
{
    public function __construct(private readonly Generator $generator) {}

    public function definition(): array
    {
        return [Argument::required('name', 'Class name, e.g. UserRegistered')];
    }

    public function execute(Input $input, Output $output): int
    {
        [$namespace, $class, $path] = Generator::target((string) $input->argument('name'), 'App\\Events', 'app/Events');

        $contents = <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace};

            /**
             * Send with \$events->dispatch(new {$class}(...)).
             * Listeners are registered in config/events.php.
             */
            final class {$class}
            {
                public function __construct()
                {
                }
            }

            PHP;

        $output->success('Created ' . $this->generator->write($path, $contents));

        return self::SUCCESS;
    }
}
