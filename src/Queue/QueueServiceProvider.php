<?php

declare(strict_types=1);

namespace VeyroPHP\Queue;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;
use VeyroPHP\Application\Application;
use VeyroPHP\Application\ServiceProvider;
use VeyroPHP\Config\Config;
use VeyroPHP\Container\Container;
use VeyroPHP\Database\DatabaseManager;
use VeyroPHP\Orm\Orm;
use VeyroPHP\Queue\Driver\ArrayDriver;
use VeyroPHP\Queue\Driver\DatabaseDriver;
use VeyroPHP\Queue\Driver\Driver;
use VeyroPHP\Queue\Driver\RedisDriver;
use VeyroPHP\Queue\Driver\SyncDriver;
use VeyroPHP\Queue\Failed\DatabaseFailedJobStore;
use VeyroPHP\Queue\Failed\FailedJobStore;
use VeyroPHP\Support\RedisConnector;

/**
 * Registers Queue, Payload, JobRunner, Worker and the failed-job store.
 * Drivers are created on first use.
 *
 * Config (config/queue.php):
 *
 *     'default' => 'database',
 *     'connections' => [
 *         'database' => ['driver' => 'database', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90, 'connection' => null],
 *         'redis' => ['driver' => 'redis', 'host' => '127.0.0.1', 'port' => 6379, 'password' => null, 'database' => 0,
 *                     'prefix' => 'queues:', 'queue' => 'default', 'retry_after' => 90],
 *         'sync' => ['driver' => 'sync'],
 *         'array' => ['driver' => 'array'],
 *     ],
 *     'failed' => ['table' => 'failed_jobs'],    // null: failed jobs are only logged
 *
 * retry_after must be longer than the longest job (and its timeout): a job
 * reserved longer than that is handed to another worker.
 */
final class QueueServiceProvider implements ServiceProvider
{
    /**
     * The queue commands, for ConsoleServiceProvider:
     * new ConsoleServiceProvider([...QueueServiceProvider::COMMANDS, ...]).
     *
     * @var list<class-string<\VeyroPHP\Console\Command>>
     */
    public const COMMANDS = [
        Console\WorkCommand::class,
        Console\FailedCommand::class,
        Console\RetryCommand::class,
        Console\ForgetCommand::class,
        Console\FlushCommand::class,
        Console\RestartCommand::class,
    ];

    public function register(Application $app): void
    {
        $container = $app->container();

        $container->singleton(Payload::class, static fn(Container $c): Payload => new Payload(
            $c->bound(Orm::class) ? static fn(): Orm => $c->make(Orm::class) : null,
        ));

        $container->singleton(JobRunner::class, static fn(Container $c): JobRunner => new JobRunner($c, $c->make(Payload::class)));

        $container->singleton(Queue::class, static function (Container $c): Queue {
            $config = $c->make(Config::class);
            $default = $config->get('queue.default', 'sync');
            $connections = $config->get('queue.connections', ['sync' => ['driver' => 'sync']]);
            $queues = [];

            foreach (is_array($connections) ? $connections : [] as $name => $settings) {
                if (is_string($name) && is_array($settings) && is_string($settings['queue'] ?? null)) {
                    $queues[$name] = $settings['queue'];
                }
            }

            return new Queue(
                static fn(string $name): Driver => self::driver($name, $config, $c),
                is_string($default) && $default !== '' ? $default : 'sync',
                $c->make(Payload::class),
                $queues,
            );
        });

        $container->singleton(FailedJobStore::class, static function (Container $c): FailedJobStore {
            $table = $c->make(Config::class)->get('queue.failed.table', 'failed_jobs');

            return new DatabaseFailedJobStore($c->make(DatabaseManager::class)->connection(), is_string($table) ? $table : 'failed_jobs');
        });

        $container->singleton(Worker::class, static function (Container $c): Worker {
            $config = $c->make(Config::class);
            $failed = $config->has('queue.failed') && $config->get('queue.failed') !== null;

            return new Worker(
                $c->make(Queue::class),
                $c->make(JobRunner::class),
                $failed ? $c->make(FailedJobStore::class) : null,
                $c->bound(LoggerInterface::class) ? $c->make(LoggerInterface::class) : new NullLogger(),
                $c->bound(EventDispatcherInterface::class) ? $c->make(EventDispatcherInterface::class) : null,
                $c->bound(CacheInterface::class) ? $c->make(CacheInterface::class) : null,
                static fn() => $c->resetScope(),
            );
        });
    }

    private static function driver(string $name, Config $config, Container $c): Driver
    {
        $settings = $config->get('queue.connections.' . $name);

        if (!is_array($settings)) {
            throw new QueueException(sprintf('No queue connection "%s" is configured in config/queue.php.', $name));
        }

        $retryAfter = $settings['retry_after'] ?? 90;

        if (!is_int($retryAfter) || $retryAfter < 1) {
            throw new QueueException(sprintf('queue.connections.%s.retry_after must be a positive number of seconds.', $name));
        }

        return match ($settings['driver'] ?? null) {
            'database' => new DatabaseDriver(
                $c->make(DatabaseManager::class)->connection(is_string($settings['connection'] ?? null) ? $settings['connection'] : null),
                is_string($settings['table'] ?? null) ? $settings['table'] : 'jobs',
                $retryAfter,
            ),
            'redis' => new RedisDriver(RedisConnector::connect(
                $settings,
                static fn(string $problem, ?\Throwable $previous = null): QueueException => new QueueException(sprintf('Queue connection "%s": %s.', $name, $problem), 0, $previous),
                '127.0.0.1',
                2.0,
            ), is_string($settings['prefix'] ?? null) ? $settings['prefix'] : 'queues:', $retryAfter),
            'sync' => new SyncDriver(static function (string $payload) use ($c): void {
                $runner = $c->make(JobRunner::class);
                $runner->handle($runner->instantiate(JobRunner::decode($payload)['job']));
            }),
            'array' => new ArrayDriver(),
            default => throw new QueueException(sprintf('Queue connection "%s" has an unknown driver; use database, redis, sync or array.', $name)),
        };
    }
}
