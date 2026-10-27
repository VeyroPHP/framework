<?php

declare(strict_types=1);

namespace VeyroPHP\Console\Make;

use VeyroPHP\Console\Argument;
use VeyroPHP\Console\AsCommand;
use VeyroPHP\Console\Command;
use VeyroPHP\Console\Input;
use VeyroPHP\Console\Option;
use VeyroPHP\Console\Output;

#[AsCommand('make:listener', 'Create an event listener class in app/Listeners')]
final class MakeListenerCommand extends Command
{
    public function __construct(private readonly Generator $generator) {}

    public function definition(): array
    {
        return [
            Argument::required('name', 'Class name, e.g. SendWelcomeMail'),
            Option::value('event', 'Event class it handles, e.g. UserRegistered or App\\Events\\UserRegistered'),
        ];
    }

    public function execute(Input $input, Output $output): int
    {
        [$namespace, $class, $path] = Generator::target((string) $input->argument('name'), 'App\\Listeners', 'app/Listeners');

        $event = $input->option('event');
        $eventClass = $event !== null && $event !== '' ? ltrim($event, '\\') : 'object';

        if ($eventClass !== 'object' && !str_contains($eventClass, '\\')) {
            $eventClass = 'App\\Events\\' . $eventClass;
        }

        if ($eventClass !== 'object' && preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/D', $eventClass) !== 1) {
            $output->error('Invalid event class name: ' . $eventClass);

            return self::USAGE;
        }

        $short = $eventClass === 'object' ? 'object' : substr($eventClass, (int) strrpos($eventClass, '\\') + 1);
        $use = $eventClass === 'object' ? '' : "\nuse {$eventClass};\n";
        $register = $eventClass === 'object' ? 'EventClass' : $short;

        $contents = <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace};
            {$use}
            /**
             * Register in config/events.php:
             *
             *     {$register}::class => [\\{$namespace}\\{$class}::class],
             *
             * Constructor parameters are injected by the container.
             */
            final class {$class}
            {
                public function handle({$short} \$event): void
                {
                }
            }

            PHP;

        $output->success('Created ' . $this->generator->write($path, $contents));

        return self::SUCCESS;
    }
}
