<?php

declare(strict_types=1);

namespace VeyroPHP\Database;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use VeyroPHP\Application\Application;
use VeyroPHP\Application\ServiceProvider;
use VeyroPHP\Config\Config;
use VeyroPHP\Container\Container;
use VeyroPHP\Database\Connection\Connection;
use VeyroPHP\Database\Migration\Migrator;
use VeyroPHP\Database\Profiling\ProfileQueriesMiddleware;
use VeyroPHP\Database\Profiling\QueryLogger;
use VeyroPHP\Database\Profiling\QueryProfiler;
use VeyroPHP\Database\Schema\Schema;

/**
 * Registers DatabaseManager (singleton), the default Connection, Schema,
 * Migrator (directory "database.migrations", default "database/migrations"),
 * QueryProfiler and ProfileQueriesMiddleware.
 *
 * Reads the "database" config section (see DatabaseManager::fromConfig()).
 * Relative SQLite paths are resolved inside the base path. No connection is
 * opened until the first query.
 *
 * Optional "database.profiling" keys:
 *
 * - enabled (bool, default false): record queries in QueryProfiler
 * - slow_query_ms (int|float, default 100): threshold for slow queries
 * - log_slow_queries (bool, default true): log slow queries as warnings
 * - log_queries (bool, default false): log every query at debug level
 * - n_plus_one_threshold (int, default 5): repeats that count as possible N+1
 * - expose_headers (bool, default false): add X-Query-Count / X-Query-Time-Ms
 */
final class DatabaseServiceProvider implements ServiceProvider
{
    public function register(Application $app): void
    {
        $container = $app->container();

        $container->singleton(QueryProfiler::class, static fn(Container $c): QueryProfiler => new QueryProfiler(
            self::profilingFloat($c->make(Config::class), 'slow_query_ms', 100.0),
        ));

        $container->singleton(DatabaseManager::class, static function (Container $c) use ($app): DatabaseManager {
            $config = $c->make(Config::class);
            $database = $config->array('database');
            $connections = $database['connections'] ?? null;

            if (is_array($connections)) {
                $resolved = [];
                foreach ($connections as $name => $settings) {
                    if (is_array($settings) && ($settings['driver'] ?? null) === 'sqlite' && is_string($settings['database'] ?? null)) {
                        $settings['database'] = self::resolveSqlitePath($app, $settings['database']);
                    }
                    $resolved[$name] = $settings;
                }
                $database['connections'] = $resolved;
            }

            $manager = DatabaseManager::fromConfig($database);

            if (self::profilingBool($config, 'enabled', false)) {
                $profiler = $c->make(QueryProfiler::class);
                $manager->listen($profiler->listener());

                $logAll = self::profilingBool($config, 'log_queries', false);
                if ($logAll || self::profilingBool($config, 'log_slow_queries', true)) {
                    $manager->listen((new QueryLogger(self::logger($c), $profiler->slowThresholdMs(), $logAll))(...));
                }
            }

            return $manager;
        });

        $container->bind(Connection::class, static fn(Container $c): Connection => $c->make(DatabaseManager::class)->connection());
        $container->bind(Schema::class, static fn(Container $c): Schema => new Schema($c->make(Connection::class)));

        $container->singleton(Migrator::class, static function (Container $c) use ($app): Migrator {
            $config = $c->make(Config::class);
            $directory = $config->has('database.migrations') ? $config->string('database.migrations') : 'database/migrations';

            return new Migrator($c->make(Connection::class), $app->basePath($directory));
        });

        $container->singleton(ProfileQueriesMiddleware::class, static function (Container $c): ProfileQueriesMiddleware {
            $config = $c->make(Config::class);

            return new ProfileQueriesMiddleware(
                $c->make(QueryProfiler::class),
                self::logger($c),
                $config->has('database.profiling.n_plus_one_threshold') ? $config->int('database.profiling.n_plus_one_threshold') : 5,
                self::profilingBool($config, 'expose_headers', false),
            );
        });
    }

    private static function resolveSqlitePath(Application $app, string $path): string
    {
        if ($path === ':memory:' || preg_match('#^([A-Za-z]:[/\\\\]|[/\\\\])#', $path) === 1) {
            return $path;
        }

        return $app->basePath($path);
    }

    private static function profilingBool(Config $config, string $key, bool $default): bool
    {
        return $config->has('database.profiling.' . $key) ? $config->bool('database.profiling.' . $key) : $default;
    }

    private static function profilingFloat(Config $config, string $key, float $default): float
    {
        return $config->has('database.profiling.' . $key) ? $config->float('database.profiling.' . $key) : $default;
    }

    private static function logger(Container $container): LoggerInterface
    {
        return $container->has(LoggerInterface::class) ? $container->make(LoggerInterface::class) : new NullLogger();
    }
}
