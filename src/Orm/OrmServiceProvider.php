<?php

declare(strict_types=1);

namespace VeyroPHP\Orm;

use Psr\EventDispatcher\EventDispatcherInterface;
use VeyroPHP\Application\Application;
use VeyroPHP\Application\BootableProvider;
use VeyroPHP\Container\Container;
use VeyroPHP\Database\Connection\Connection;
use VeyroPHP\Routing\HandlerInvoker;

/**
 * Registers Orm (default connection, and the event dispatcher when one is bound,
 * for model events) and, when the HTTP layer is registered, route binding of model
 * parameters (ModelRouteResolver).
 *
 * Register after DatabaseServiceProvider, HttpServiceProvider and, for model
 * events, EventServiceProvider.
 */
final class OrmServiceProvider implements BootableProvider
{
    public function register(Application $app): void
    {
        $app->container()->singleton(Orm::class, static fn(Container $c): Orm => new Orm(
            $c->make(Connection::class),
            events: $c->bound(EventDispatcherInterface::class) ? $c->make(EventDispatcherInterface::class) : null,
        ));
    }

    public function boot(Application $app): void
    {
        $container = $app->container();

        if ($container->bound(HandlerInvoker::class)) {
            $container->make(HandlerInvoker::class)->addParameterResolver(new ModelRouteResolver($container->make(Orm::class)));
        }
    }
}
