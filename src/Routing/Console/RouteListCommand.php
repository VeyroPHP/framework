<?php

declare(strict_types=1);

namespace VeyroPHP\Routing\Console;

use Closure;
use Psr\Http\Server\MiddlewareInterface;
use ReflectionFunction;
use VeyroPHP\Application\Application;
use VeyroPHP\Console\AsCommand;
use VeyroPHP\Console\Command;
use VeyroPHP\Console\Input;
use VeyroPHP\Console\Option;
use VeyroPHP\Console\Output;
use VeyroPHP\Routing\Route;
use VeyroPHP\Routing\Router;

#[AsCommand('route:list', 'List the registered routes')]
final class RouteListCommand extends Command
{
    public function __construct(
        private readonly Router $router,
        private readonly Application $app,
    ) {}

    public function definition(): array
    {
        return [
            Option::value('method', 'Only routes that accept this HTTP method, e.g. POST'),
            Option::value('path', 'Only routes whose path contains this text, e.g. /admin'),
        ];
    }

    public function execute(Input $input, Output $output): int
    {
        $method = $input->option('method');
        $path = $input->option('path');
        $rows = [];

        foreach ($this->router->routes() as $route) {
            if ($method !== null && !$route->allowsMethod(strtoupper($method))) {
                continue;
            }
            if ($path !== null && !str_contains($route->path(), $path)) {
                continue;
            }

            $rows[] = [
                implode('|', $route->methods()),
                $route->path(),
                $route->getName() ?? '',
                $this->handler($route),
                implode(', ', array_map(self::middleware(...), $route->getMiddleware())),
            ];
        }

        if ($rows === []) {
            $output->line($method === null && $path === null ? 'No routes are registered.' : 'No routes match.');

            return self::SUCCESS;
        }

        $output->table(['Method', 'Path', 'Name', 'Handler', 'Middleware'], $rows);

        return self::SUCCESS;
    }

    private function handler(Route $route): string
    {
        $handler = $route->handler();

        if ($handler instanceof Closure) {
            $function = new ReflectionFunction($handler);
            $file = (string) $function->getFileName();
            $base = $this->app->basePath() . DIRECTORY_SEPARATOR;
            $relative = str_starts_with($file, $base) ? substr($file, strlen($base)) : $file;

            return sprintf('Closure (%s:%d)', str_replace('\\', '/', $relative), (int) $function->getStartLine());
        }

        return is_array($handler) ? $handler[0] . '::' . $handler[1] : $handler;
    }

    private static function middleware(MiddlewareInterface|string $middleware): string
    {
        $name = is_string($middleware) ? $middleware : $middleware::class;
        $separator = strrpos(explode(':', $name, 2)[0], '\\');

        // "RequirePermission:users.view" instead of the full namespace.
        return $separator === false ? $name : substr($name, $separator + 1);
    }
}
