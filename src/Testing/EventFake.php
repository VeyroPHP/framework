<?php

declare(strict_types=1);

namespace VeyroPHP\Testing;

use Closure;
use PHPUnit\Framework\Assert;
use Psr\EventDispatcher\EventDispatcherInterface;
use VeyroPHP\Container\Container;

/**
 * Records events instead of running their listeners.
 *
 *     $events = EventFake::install($container);              // before the code under test is built
 *     // ... run the code ...
 *     $events->assertDispatched(OrderPlaced::class, fn (OrderPlaced $e) => $e->id === 7);
 *
 * - install() replaces Psr\EventDispatcher\EventDispatcherInterface in the container.
 *   Services already built keep the dispatcher they got, so install it first.
 * - Event classes in $passThrough still reach the real dispatcher (and are recorded).
 * - Assertions use PHPUnit's Assert when PHPUnit is installed; otherwise they throw
 *   \AssertionError, so they also work with other test runners.
 */
final class EventFake implements EventDispatcherInterface
{
    /** @var list<object> */
    private array $events = [];

    /**
     * @param list<class-string> $passThrough
     */
    public function __construct(
        private readonly ?EventDispatcherInterface $real = null,
        private readonly array $passThrough = [],
    ) {}

    /**
     * @param list<class-string> $passThrough event classes that still reach their listeners
     */
    public static function install(Container $container, array $passThrough = []): self
    {
        $real = $container->bound(EventDispatcherInterface::class) ? $container->make(EventDispatcherInterface::class) : null;
        $fake = new self($real instanceof EventDispatcherInterface && !$real instanceof self ? $real : null, $passThrough);
        $container->instance(EventDispatcherInterface::class, $fake);

        return $fake;
    }

    public function dispatch(object $event): object
    {
        $this->events[] = $event;

        foreach ($this->passThrough as $class) {
            if ($event instanceof $class) {
                return $this->real?->dispatch($event) ?? $event;
            }
        }

        return $event;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return list<T>
     */
    public function dispatched(string $class): array
    {
        return array_values(array_filter($this->events, static fn(object $e): bool => $e instanceof $class));
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     * @param (Closure(T): bool)|null $matches
     */
    public function assertDispatched(string $class, ?Closure $matches = null): void
    {
        $found = $this->matching($class, $matches);

        self::check($found !== [], sprintf('Expected event %s to be dispatched%s; it was not.', $class, $matches === null ? '' : ' matching the condition'));
    }

    /**
     * @param class-string $class
     */
    public function assertDispatchedTimes(string $class, int $times): void
    {
        $count = count($this->dispatched($class));

        self::check($count === $times, sprintf('Expected event %s to be dispatched %d time(s); it was dispatched %d time(s).', $class, $times, $count));
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     * @param (Closure(T): bool)|null $matches
     */
    public function assertNotDispatched(string $class, ?Closure $matches = null): void
    {
        self::check($this->matching($class, $matches) === [], sprintf('Expected event %s not to be dispatched; it was.', $class));
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     * @param (Closure(T): bool)|null $matches
     *
     * @return list<T>
     */
    private function matching(string $class, ?Closure $matches): array
    {
        return array_values(array_filter($this->dispatched($class), static fn(object $e): bool => $matches === null || $matches($e) === true));
    }

    private static function check(bool $condition, string $message): void
    {
        if (class_exists(Assert::class)) {
            Assert::assertTrue($condition, $message);

            return;
        }

        if (!$condition) {
            throw new \AssertionError($message);
        }
    }
}
