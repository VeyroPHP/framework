<?php

declare(strict_types=1);

namespace VeyroPHP\Logging;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use VeyroPHP\Application\Application;
use VeyroPHP\Application\ServiceProvider;
use VeyroPHP\Config\Config;
use VeyroPHP\Container\Container;

/**
 * Registers a FileLogger as the LoggerInterface singleton.
 *
 * Config keys (all optional):
 *
 * - logging.path     log file; relative paths are inside the base path (default "storage/logs/veyrophp.log")
 * - logging.level    minimum PSR-3 level (default "debug")
 * - logging.channel  channel name in each line (default "app")
 * - logging.redact   list of extra sensitive key words
 * - logging.days     0 (default): one file, rotate it externally; N: one file per day, N days kept
 *
 * Register ConfigServiceProvider first if you use these keys.
 */
final class LoggingServiceProvider implements ServiceProvider
{
    public function register(Application $app): void
    {
        $app->container()->singleton(LoggerInterface::class, static function (Container $container) use ($app): FileLogger {
            $config = $container->make(Config::class);

            $path = $config->has('logging.path') ? $config->string('logging.path') : 'storage/logs/veyrophp.log';

            $extraWords = [];
            foreach ($config->has('logging.redact') ? $config->array('logging.redact') : [] as $word) {
                if (is_string($word)) {
                    $extraWords[] = $word;
                }
            }

            return new FileLogger(
                self::isAbsolute($path) ? $path : $app->basePath($path),
                $config->has('logging.level') ? $config->string('logging.level') : LogLevel::DEBUG,
                $config->has('logging.channel') ? $config->string('logging.channel') : 'app',
                new Redactor($extraWords),
                null,
                $config->has('logging.days') ? $config->int('logging.days') : 0,
            );
        });
    }

    private static function isAbsolute(string $path): bool
    {
        return preg_match('#^([A-Za-z]:[/\\\\]|[/\\\\])#', $path) === 1;
    }
}
