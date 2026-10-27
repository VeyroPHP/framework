<?php

declare(strict_types=1);

namespace VeyroPHP\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use VeyroPHP\Application\Application;
use VeyroPHP\Application\BootableProvider;
use VeyroPHP\Cache\CacheManager;
use VeyroPHP\Config\Config;
use VeyroPHP\Container\Container;
use VeyroPHP\Database\Connection\Connection;
use VeyroPHP\Http\Exception\HttpException;
use VeyroPHP\Http\Health\HealthCheck;
use VeyroPHP\Http\Maintenance\MaintenanceMode;
use VeyroPHP\Routing\HandlerInvoker;
use VeyroPHP\Routing\Router;

/**
 * Registers the HTTP layer: PSR-17 factories, ResponseFactory, Router,
 * HandlerInvoker, HttpErrorResponder and HttpKernel (all singletons), plus
 * MaintenanceMode (storage/maintenance.json) and HealthCheck with checks for the
 * database, the cache and storage/ when those are registered.
 *
 * Route files are loaded during boot, in the given order. Each file receives
 * $router and $app:
 *
 *     // routes/web.php
 *     $router->get('/', fn () => 'Hello');
 *
 * Config key "app.debug" (bool, optional, default false) controls error detail.
 * A bound Psr\Log\LoggerInterface is used for error logging if present.
 */
final class HttpServiceProvider implements BootableProvider
{
    /**
     * @param list<string> $routeFiles paths relative to the base path
     * @param list<MiddlewareInterface|string> $middleware global middleware, outermost first
     */
    public function __construct(
        private readonly array $routeFiles = [],
        private readonly array $middleware = [],
    ) {}

    public function register(Application $app): void
    {
        $container = $app->container();

        $container->singleton(Psr17Factory::class);

        foreach ([
            RequestFactoryInterface::class,
            ResponseFactoryInterface::class,
            ServerRequestFactoryInterface::class,
            StreamFactoryInterface::class,
            UploadedFileFactoryInterface::class,
            UriFactoryInterface::class,
        ] as $interface) {
            $container->bind($interface, static fn(Container $c): Psr17Factory => $c->make(Psr17Factory::class));
        }

        $container->singleton(ResponseFactory::class, static fn(Container $c): ResponseFactory => new ResponseFactory(
            $c->make(Psr17Factory::class),
            $c->make(Psr17Factory::class),
        ));

        $container->singleton(Router::class);
        $container->singleton(HandlerInvoker::class);

        $container->singleton(HttpErrorResponder::class, static function (Container $c): HttpErrorResponder {
            $config = $c->make(Config::class);

            return new HttpErrorResponder(
                $c->make(ResponseFactory::class),
                $c->has(LoggerInterface::class) ? $c->make(LoggerInterface::class) : new NullLogger(),
                $config->has('app.debug') && $config->bool('app.debug'),
            );
        });

        $container->singleton(MaintenanceMode::class, static fn(): MaintenanceMode => new MaintenanceMode($app->basePath(MaintenanceMode::FILE)));

        // Built-in checks for what the application has registered; apps add their own.
        $container->singleton(HealthCheck::class, static function (Container $c) use ($app): HealthCheck {
            $health = new HealthCheck($c->has(LoggerInterface::class) ? $c->make(LoggerInterface::class) : new NullLogger());

            if ($c->bound(Connection::class)) {
                $health->add('database', static fn(): mixed => $c->make(Connection::class)->selectOne('SELECT 1'));
            }

            if ($c->bound(CacheManager::class)) {
                $health->add('cache', static function () use ($c): void {
                    $cache = $c->make(CacheManager::class)->store();
                    $key = 'health.' . bin2hex(random_bytes(6));
                    $cache->set($key, 'ok', 10);
                    $read = $cache->get($key);
                    $cache->delete($key);

                    if ($read !== 'ok') {
                        throw new \RuntimeException('The cache did not return the value just written.');
                    }
                });
            }

            $health->add('storage', static function () use ($app): void {
                $storage = $app->basePath('storage');

                if (!is_dir($storage) || !is_writable($storage)) {
                    throw new \RuntimeException(sprintf('%s is not a writable directory.', $storage));
                }
            });

            return $health;
        });

        $middleware = $this->middleware;
        $container->singleton(HttpKernel::class, static fn(Container $c): HttpKernel => new HttpKernel(
            $c->make(Router::class),
            $c,
            $c->make(HandlerInvoker::class),
            $c->make(HttpErrorResponder::class),
            $c->make(ResponseFactory::class),
            $middleware,
        ));
    }

    public function boot(Application $app): void
    {
        $router = $app->container()->make(Router::class);

        foreach ($this->routeFiles as $file) {
            $path = $app->basePath($file);

            if (!is_file($path)) {
                throw new HttpException(500, sprintf('Route file "%s" does not exist.', $file));
            }

            (static function (string $__path, Router $router, Application $app): void {
                require $__path;
            })($path, $router, $app);
        }
    }
}
