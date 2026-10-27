<?php

declare(strict_types=1);

namespace VeyroPHP\Console\Make;

use VeyroPHP\Console\Argument;
use VeyroPHP\Console\AsCommand;
use VeyroPHP\Console\Command;
use VeyroPHP\Console\Input;
use VeyroPHP\Console\Output;

#[AsCommand('make:controller', 'Create a controller class in app/Http/Controllers')]
final class MakeControllerCommand extends Command
{
    public function __construct(private readonly Generator $generator) {}

    public function definition(): array
    {
        return [Argument::required('name', 'Class name, e.g. PostController or Admin/UserController')];
    }

    public function execute(Input $input, Output $output): int
    {
        [$namespace, $class, $path] = Generator::target((string) $input->argument('name'), 'App\\Http\\Controllers', 'app/Http/Controllers');

        $contents = <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace};

            use Psr\\Http\\Message\\ServerRequestInterface;

            /**
             * Register in routes/web.php:
             *
             *     \$router->get('/example', [\\{$namespace}\\{$class}::class, 'index']);
             *
             * Constructor and method parameters are injected by the container.
             */
            final class {$class}
            {
                public function index(ServerRequestInterface \$request): string
                {
                    return '{$class}::index';
                }
            }

            PHP;

        $output->success('Created ' . $this->generator->write($path, $contents));

        return self::SUCCESS;
    }
}
