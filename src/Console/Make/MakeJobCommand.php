<?php

declare(strict_types=1);

namespace VeyroPHP\Console\Make;

use VeyroPHP\Console\Argument;
use VeyroPHP\Console\AsCommand;
use VeyroPHP\Console\Command;
use VeyroPHP\Console\Input;
use VeyroPHP\Console\Output;

#[AsCommand('make:job', 'Create a queued job class in app/Jobs')]
final class MakeJobCommand extends Command
{
    public function __construct(private readonly Generator $generator) {}

    public function definition(): array
    {
        return [Argument::required('name', 'Class name, e.g. SendInvoice')];
    }

    public function execute(Input $input, Output $output): int
    {
        [$namespace, $class, $path] = Generator::target((string) $input->argument('name'), 'App\\Jobs', 'app/Jobs');

        $contents = <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace};

            use Psr\\Log\\LoggerInterface;
            use VeyroPHP\\Queue\\Job;

            /**
             * Queue with \$queue->push(new {$class}(...)); run by "php veyro queue:work".
             *
             * Constructor values are stored with the job: use JSON values, enums or saved
             * models, in promoted parameters. handle() parameters are injected.
             * The job may run more than once, so make it safe to repeat.
             */
            final class {$class} implements Job
            {
                public int \$tries = 3;

                /** @var list<int> seconds before retry 1, 2, ... */
                public array \$backoff = [10, 60, 300];

                public function __construct(public readonly int \$id)
                {
                }

                public function handle(LoggerInterface \$logger): void
                {
                    \$logger->info('{$class} ran', ['id' => \$this->id]);
                }

                public function failed(\\Throwable \$error): void
                {
                    // Called once after the last attempt failed.
                }
            }

            PHP;

        $output->success('Created ' . $this->generator->write($path, $contents));

        return self::SUCCESS;
    }
}
