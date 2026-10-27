<?php

declare(strict_types=1);

namespace VeyroPHP\Scheduling;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use VeyroPHP\Application\Application;
use VeyroPHP\Application\ServiceProvider;
use VeyroPHP\Cache\Cache;
use VeyroPHP\Config\Config;
use VeyroPHP\Container\Container;

/**
 * Registers Schedule (filled from routes/schedule.php, or config "schedule.file")
 * and ScheduleRunner.
 *
 * The schedule file is plain PHP with a $schedule variable:
 *
 *     <?php
 *     /** @var VeyroPHP\Scheduling\Schedule $schedule *\/
 *     $schedule->command('cache:prune')->everyThirtyMinutes();
 */
final class SchedulingServiceProvider implements ServiceProvider
{
    /**
     * The schedule commands, for ConsoleServiceProvider.
     *
     * @var list<class-string<\VeyroPHP\Console\Command>>
     */
    public const COMMANDS = [
        Console\RunCommand::class,
        Console\ListCommand::class,
        Console\WorkCommand::class,
    ];

    public function register(Application $app): void
    {
        $container = $app->container();

        $container->singleton(Schedule::class, static function (Container $c) use ($app): Schedule {
            $schedule = new Schedule($c);
            $file = $c->make(Config::class)->get('schedule.file', 'routes/schedule.php');
            $path = $app->basePath(is_string($file) ? $file : 'routes/schedule.php');

            if (is_file($path)) {
                (static function (Schedule $schedule, string $path): void {
                    require $path;
                })($schedule, $path);
            }

            return $schedule;
        });

        $container->singleton(ScheduleRunner::class, static function (Container $c): ScheduleRunner {
            $env = $c->make(Config::class)->get('app.env', 'production');

            return new ScheduleRunner(
                $c->make(Schedule::class),
                is_string($env) ? $env : 'production',
                $c->bound(Cache::class) ? $c->make(Cache::class) : null,
                $c->bound(LoggerInterface::class) ? $c->make(LoggerInterface::class) : new NullLogger(),
            );
        });
    }
}
