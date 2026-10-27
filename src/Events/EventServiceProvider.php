<?php

declare(strict_types=1);

namespace VeyroPHP\Events;

use Psr\EventDispatcher\EventDispatcherInterface;
use VeyroPHP\Application\Application;
use VeyroPHP\Application\BootableProvider;
use VeyroPHP\Config\Config;
use VeyroPHP\Container\Container;
use VeyroPHP\Queue\Queue;

/**
 * Registers EventDispatcher (singleton) as itself and as the PSR-14
 * EventDispatcherInterface, and adds the listeners from config/events.php:
 *
 *     'listeners' => [
 *         UserRegistered::class => [SendWelcomeMail::class],
 *         Login::class => [RecordLastLogin::class],
 *     ],
 *
 * Listeners can also be added in an application provider's boot() with
 * EventDispatcher::listen().
 */
final class EventServiceProvider implements BootableProvider
{
    public function register(Application $app): void
    {
        $container = $app->container();

        $container->singleton(EventDispatcher::class, static fn(Container $c): EventDispatcher => new EventDispatcher(
            $c,
            static fn(): Queue => $c->bound(Queue::class)
                ? $c->make(Queue::class)
                : throw new EventException('A ShouldQueue listener needs the QueueServiceProvider.'),
        ));
        $container->bind(EventDispatcherInterface::class, static fn(Container $c): EventDispatcher => $c->make(EventDispatcher::class));
    }

    public function boot(Application $app): void
    {
        $container = $app->container();
        $config = $container->make(Config::class);

        if (!$config->has('events.listeners')) {
            return;
        }

        $events = $container->make(EventDispatcher::class);

        foreach ($config->array('events.listeners') as $event => $listeners) {
            if (!is_string($event) || !is_array($listeners)) {
                throw new EventException('config events.listeners must map event class names to lists of listener class names.');
            }

            foreach ($listeners as $listener) {
                if (!is_string($listener)) {
                    throw new EventException(sprintf('config events.listeners.%s must contain listener class names.', $event));
                }

                $events->listen($event, $listener);
            }
        }
    }
}
