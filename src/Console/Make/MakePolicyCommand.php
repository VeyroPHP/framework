<?php

declare(strict_types=1);

namespace VeyroPHP\Console\Make;

use VeyroPHP\Console\Argument;
use VeyroPHP\Console\AsCommand;
use VeyroPHP\Console\Command;
use VeyroPHP\Console\Input;
use VeyroPHP\Console\Option;
use VeyroPHP\Console\Output;
use VeyroPHP\Console\UsageException;

#[AsCommand('make:policy', 'Create an authorization policy in app/Policies')]
final class MakePolicyCommand extends Command
{
    public function __construct(private readonly Generator $generator) {}

    public function definition(): array
    {
        return [
            Argument::required('name', 'Class name, e.g. PostPolicy'),
            Option::value('model', 'Model class, e.g. Post or App\\Models\\Post (default: the name without "Policy")'),
        ];
    }

    public function execute(Input $input, Output $output): int
    {
        [$namespace, $class, $path] = Generator::target((string) $input->argument('name'), 'App\\Policies', 'app/Policies');

        $model = $input->option('model') ?? (str_ends_with($class, 'Policy') && $class !== 'Policy' ? substr($class, 0, -6) : $class);
        $model = ltrim($model, '\\');

        if (!str_contains($model, '\\')) {
            $model = 'App\\Models\\' . $model;
        }

        if (preg_match('/^[A-Z][A-Za-z0-9_]*(\\\\[A-Z][A-Za-z0-9_]*)+$/D', $model) !== 1) {
            throw new UsageException(sprintf('Invalid model class name "%s".', $model));
        }

        $short = substr($model, (int) strrpos($model, '\\') + 1);
        $variable = lcfirst($short);

        $contents = <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace};

            use {$model};

            /**
             * Who may do what with a {$short}. Register it in a provider's boot():
             *
             *     \$container->make(AccessRules::class)->policy({$short}::class, {$class}::class);
             *
             * Check with \$gate->authorize('update', \${$variable}). Each method returns true or false;
             * a method whose \$user is not nullable is never called for guests (they are denied).
             * A missing method denies.
             */
            final class {$class}
            {
                public function view(?\\App\\Models\\User \$user, {$short} \${$variable}): bool
                {
                    return true;
                }

                public function create(\\App\\Models\\User \$user): bool
                {
                    return true;
                }

                public function update(\\App\\Models\\User \$user, {$short} \${$variable}): bool
                {
                    return \${$variable}->get('user_id') === \$user->getKey();
                }

                public function delete(\\App\\Models\\User \$user, {$short} \${$variable}): bool
                {
                    return \${$variable}->get('user_id') === \$user->getKey();
                }
            }

            PHP;

        $output->success('Created ' . $this->generator->write($path, $contents));

        return self::SUCCESS;
    }
}
