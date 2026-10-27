<?php

declare(strict_types=1);

namespace VeyroPHP\Events;

use Closure;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\ListenerProviderInterface;
use Psr\EventDispatcher\StoppableEventInterface;
use VeyroPHP\Queue\CallQueuedListener;
use VeyroPHP\Queue\Queue;
use VeyroPHP\Queue\ShouldQueue;
use VeyroPHP\Support\ListenerCallable;

/**
 * PSR-14 event dispatcher and listener provider.
 *
 *     $events->listen(UserRegistered::class, SendWelcomeMail::class);
 *     $events->listen(UserRegistered::class, fn(UserRegistered $e) => ...);
 *     $events->dispatch(new UserRegistered($user));
 *
 * - An event is any object. A listener registered for a class or interface
 *   also receives subclasses and implementations.
 * - Listeners run in registration order, synchronously. A listener that
 *   throws stops the dispatch and the exception reaches the caller (PSR-14).
 * - Listener classes are created through the container on first use, so
 *   their dependencies are injected and unused listeners cost nothing. They
 *   must have handle(object $event) or __invoke(object $event).
 * - Events implementing StoppableEventInterface stop when isPropagationStopped().
 * - Listener classes implementing VeyroPHP\Queue\ShouldQueue are not called:
 *   a job is queued that calls them in a worker. The event must then follow
 *   the same constructor rules as a job.
 */
final class EventDispatcher implements EventDispatcherInterface, ListenerProviderInterface
{
    /** @var list<array{string, Closure|string}> */
    private array $listeners = [];

    /**
     * @param (Closure(): Queue)|null $queue resolves the queue for ShouldQueue listeners
     */
    public function __construct(
        private readonly ?ContainerInterface $container = null,
        private readonly ?Closure $queue = null,
    ) {}

    /**
     * @param string $event class or interface name
     * @param callable|string $listener a callable, or a listener class name (checked here)
     */
    public function listen(string $event, callable|string $listener): void
    {
        if (!class_exists($event) && !interface_exists($event)) {
            throw new EventException(sprintf('Cannot listen to "%s": no such class or interface.', $event));
        }

        if (is_string($listener) && !is_callable($listener)) {
            if (!class_exists($listener)) {
                throw new EventException(sprintf('Listener class "%s" for %s does not exist.', $listener, $event));
            }

            if (!method_exists($listener, 'handle') && !method_exists($listener, '__invoke')) {
                throw new EventException(sprintf('Listener %s needs a handle() or __invoke() method.', $listener));
            }

            $this->listeners[] = [$event, $listener];

            return;
        }

        $this->listeners[] = [$event, Closure::fromCallable($listener)];
    }

    public function hasListeners(string $event): bool
    {
        foreach ($this->listeners as [$type]) {
            if (is_a($event, $type, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Listener class names (or "Closure") per event type, for debugging.
     *
     * @return array<string, list<string>>
     */
    public function listeners(): array
    {
        $map = [];

        foreach ($this->listeners as [$type, $listener]) {
            $map[$type][] = is_string($listener) ? $listener : 'Closure';
        }

        return $map;
    }

    /**
     * @template T of object
     * @param T $event
     * @return T
     */
    public function dispatch(object $event): object
    {
        $stoppable = $event instanceof StoppableEventInterface;

        foreach ($this->getListenersForEvent($event) as $listener) {
            if ($stoppable && $event->isPropagationStopped()) {
                break;
            }

            $listener($event);
        }

        return $event;
    }

    /**
     * @return iterable<callable(object): mixed>
     */
    public function getListenersForEvent(object $event): iterable
    {
        foreach ($this->listeners as [$type, $listener]) {
            if ($event instanceof $type) {
                yield match (true) {
                    !is_string($listener) => $listener,
                    is_subclass_of($listener, ShouldQueue::class) => $this->queued($listener),
                    default => $this->resolve($listener),
                };
            }
        }
    }

    /**
     * @param class-string $listener
     */
    private function queued(string $listener): Closure
    {
        return function (object $event) use ($listener): void {
            if ($this->queue === null) {
                throw new EventException(sprintf('Listener %s implements ShouldQueue, but no queue is registered (QueueServiceProvider).', $listener));
            }

            $queue = ($this->queue)();
            $queue->push(new CallQueuedListener($listener, $queue->payload()->pack($event)));
        };
    }

    /**
     * Not cached: a listener may depend on per-request (scoped) services.
     */
    private function resolve(string $class): Closure
    {
        try {
            $instance = $this->container === null ? new $class() : $this->container->get($class);
        } catch (ContainerExceptionInterface $e) {
            throw new EventException(sprintf('Listener %s could not be created: %s', $class, $e->getMessage()), 0, $e);
        }

        return ListenerCallable::from($instance, $class, static fn(string $message): EventException => new EventException($message));
    }
}
