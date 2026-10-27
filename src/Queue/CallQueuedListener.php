<?php

declare(strict_types=1);

namespace VeyroPHP\Queue;

use Psr\Container\ContainerInterface;
use VeyroPHP\Support\ListenerCallable;

/**
 * Runs a ShouldQueue event listener in a worker. Created by the event
 * dispatcher; the event is stored with the same rules as a job.
 *
 * @internal
 */
final class CallQueuedListener implements Job
{
    /**
     * @param class-string $listener
     * @param array<mixed> $event packed event (Payload::pack)
     */
    public function __construct(
        public readonly string $listener,
        public readonly array $event,
    ) {}

    public function handle(ContainerInterface $container, Payload $payload): void
    {
        $listener = $container->get($this->listener);
        $event = $payload->unpack($this->event);

        $callable = ListenerCallable::from($listener, $this->listener, static fn(string $message): QueueException => new QueueException($message));
        $callable($event);
    }
}
