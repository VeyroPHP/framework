<?php

declare(strict_types=1);

namespace VeyroPHP\Package;

use VeyroPHP\Application\Application;
use VeyroPHP\Application\BootableProvider;
use VeyroPHP\Config\Config;
use VeyroPHP\Console\Command;
use VeyroPHP\Console\CommandRegistry;
use VeyroPHP\Events\EventDispatcher;

/**
 * Base class for package service providers, with helpers for the usual
 * integration points. A provider must be constructible without arguments
 * (it is created by the framework during discovery).
 *
 *     final class GreetingServiceProvider extends PackageServiceProvider
 *     {
 *         public function register(Application $app): void
 *         {
 *             $this->mergeConfig($app, 'greeting', __DIR__ . '/../config/greeting.php');
 *             $this->commands($app, GreetCommand::class);
 *             $this->publishes($app, [__DIR__ . '/../config/greeting.php' => 'config/greeting.php'], 'greeting-config');
 *             $app->container()->singleton(Greeter::class, ...);
 *         }
 *
 *         public function boot(Application $app): void
 *         {
 *             $this->listen($app, Login::class, LogGreeting::class);
 *         }
 *     }
 *
 * Packages cannot add global middleware: they ship middleware classes and the
 * application adds them where it wants them.
 */
abstract class PackageServiceProvider implements BootableProvider
{
    public function register(Application $app): void {}

    public function boot(Application $app): void {}

    /**
     * Adds the package's default config under $key; the application's own values win.
     */
    protected function mergeConfig(Application $app, string $key, string $file): void
    {
        $container = $app->container();

        if (!$container->bound(Config::class)) {
            throw new PackageException(sprintf('%s needs the configuration to be loaded (ConfigServiceProvider) before packages are registered.', static::class));
        }

        $defaults = is_file($file) ? require $file : null;

        if (!is_array($defaults)) {
            throw new PackageException(sprintf('%s: config file %s must return an array.', static::class, $file));
        }

        $container->make(Config::class)->mergeDefaults($key, $defaults);
    }

    /**
     * @param class-string<Command> ...$commands
     */
    protected function commands(Application $app, string ...$commands): void
    {
        $container = $app->container();

        if (!$container->bound(CommandRegistry::class)) {
            $container->singleton(CommandRegistry::class);
        }

        $container->make(CommandRegistry::class)->add(...$commands);
    }

    /**
     * Offers files to "php veyro vendor:publish --tag=$tag".
     *
     * @param array<string, string> $paths absolute source => target relative to the application
     */
    protected function publishes(Application $app, array $paths, string $tag = 'default'): void
    {
        $container = $app->container();

        if (!$container->bound(Publishables::class)) {
            $container->singleton(Publishables::class);
        }

        $container->make(Publishables::class)->add(static::class, $tag, $paths);
    }

    /**
     * Adds an event listener; call from boot(). Does nothing without the event dispatcher.
     *
     * @param callable|string $listener a callable or a listener class name
     */
    protected function listen(Application $app, string $event, callable|string $listener): void
    {
        if ($app->container()->bound(EventDispatcher::class)) {
            $app->container()->make(EventDispatcher::class)->listen($event, $listener);
        }
    }
}
