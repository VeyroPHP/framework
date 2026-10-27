<?php

declare(strict_types=1);

namespace VeyroPHP\Console\Make;

use VeyroPHP\Console\Argument;
use VeyroPHP\Console\AsCommand;
use VeyroPHP\Console\Command;
use VeyroPHP\Console\Input;
use VeyroPHP\Console\Output;

#[AsCommand('make:middleware', 'Create a PSR-15 middleware class in app/Http/Middleware')]
final class MakeMiddlewareCommand extends Command
{
    public function __construct(private readonly Generator $generator) {}

    public function definition(): array
    {
        return [Argument::required('name', 'Class name, e.g. EnsureUserIsActive or Admin/RequireTwoFactor')];
    }

    public function execute(Input $input, Output $output): int
    {
        [$namespace, $class, $path] = Generator::target((string) $input->argument('name'), 'App\\Http\\Middleware', 'app/Http/Middleware');

        $contents = <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace};

            use Psr\\Http\\Message\\ResponseInterface;
            use Psr\\Http\\Message\\ServerRequestInterface;
            use Psr\\Http\\Server\\MiddlewareInterface;
            use Psr\\Http\\Server\\RequestHandlerInterface;

            /**
             * Use it in one of three places:
             *
             * - every request: the middleware list in bootstrap/app.php
             * - one route: \$router->get('/x', ...)->middleware(\\{$namespace}\\{$class}::class)
             * - a group: \$router->group('/admin', ..., middleware: [\\{$namespace}\\{$class}::class])
             *
             * Constructor parameters are injected by the container.
             */
            final class {$class} implements MiddlewareInterface
            {
                public function process(ServerRequestInterface \$request, RequestHandlerInterface \$handler): ResponseInterface
                {
                    // Before the route: inspect or change \$request, or return a response early.

                    \$response = \$handler->handle(\$request);

                    // After the route: inspect or change \$response.

                    return \$response;
                }
            }

            PHP;

        $output->success('Created ' . $this->generator->write($path, $contents));

        return self::SUCCESS;
    }
}
