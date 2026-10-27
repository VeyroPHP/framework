<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Unit\Events;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\StoppableEventInterface;
use RuntimeException;
use VeyroPHP\Application\Application;
use VeyroPHP\Config\Config;
use VeyroPHP\Container\Container;
use VeyroPHP\Events\EventDispatcher;
use VeyroPHP\Events\EventException;
use VeyroPHP\Events\EventServiceProvider;

interface ShippingEvent {}

class OrderEvent
{
    /** @var list<string> */
    public array $log = [];
}

final class OrderShipped extends OrderEvent implements ShippingEvent {}

final class OrderCancelled extends OrderEvent {}

final class StoppableOrder extends OrderEvent implements StoppableEventInterface
{
    public bool $stopped = false;

    public function isPropagationStopped(): bool
    {
        return $this->stopped;
    }
}

final class Mailer
{
    public int $sent = 0;
}

final class SendShippingMail
{
    public static int $created = 0;

    public function __construct(private readonly Mailer $mailer)
    {
        self::$created++;
    }

    public function handle(OrderEvent $event): void
    {
        $this->mailer->sent++;
        $event->log[] = 'mail';
    }
}

final class InvokableListener
{
    public function __invoke(OrderEvent $event): void
    {
        $event->log[] = 'invoke';
    }
}

final class NoHandleMethod {}

final class PrivateHandle
{
    public function __invoke(object $event): void
    {
        $this->handle($event);
    }

    private function handle(object $event): void {}
}

#[CoversClass(EventDispatcher::class)]
#[CoversClass(EventServiceProvider::class)]
#[CoversClass(EventException::class)]
final class EventDispatcherTest extends TestCase
{
    protected function setUp(): void
    {
        SendShippingMail::$created = 0;
    }

    public function testListenersRunInRegistrationOrderAndMatchParentsAndInterfaces(): void
    {
        $events = new EventDispatcher();
        $events->listen(ShippingEvent::class, static function (OrderEvent $e): void {
            $e->log[] = 'interface';
        });
        $events->listen(OrderShipped::class, static function (OrderEvent $e): void {
            $e->log[] = 'exact';
        });
        $events->listen(OrderEvent::class, static function (OrderEvent $e): void {
            $e->log[] = 'parent';
        });
        $events->listen(OrderCancelled::class, static function (OrderEvent $e): void {
            $e->log[] = 'other';
        });

        $event = $events->dispatch(new OrderShipped());

        self::assertSame(['interface', 'exact', 'parent'], $event->log);
        self::assertSame(['parent', 'other'], $events->dispatch(new OrderCancelled())->log);
    }

    public function testEventWithoutListenersIsReturnedUnchanged(): void
    {
        $event = new OrderShipped();

        self::assertSame($event, (new EventDispatcher())->dispatch($event));
        self::assertSame([], $event->log);
    }

    public function testHasListeners(): void
    {
        $events = new EventDispatcher();
        $events->listen(OrderEvent::class, static fn(): null => null);

        self::assertTrue($events->hasListeners(OrderShipped::class));
        self::assertTrue($events->hasListeners(OrderEvent::class));
        self::assertFalse($events->hasListeners(ShippingEvent::class));
        self::assertFalse($events->hasListeners(\stdClass::class));
    }

    public function testListenerClassesAreCreatedLazilyByTheContainerForEveryDispatch(): void
    {
        $container = new Container();
        $mailer = new Mailer();
        $container->instance(Mailer::class, $mailer);

        $events = new EventDispatcher($container);
        $events->listen(OrderShipped::class, SendShippingMail::class);
        $events->listen(OrderShipped::class, InvokableListener::class);

        self::assertSame(0, SendShippingMail::$created, 'not created when registered');
        $events->dispatch(new OrderCancelled());
        self::assertSame(0, SendShippingMail::$created, 'not created for other events');

        self::assertSame(['mail', 'invoke'], $events->dispatch(new OrderShipped())->log);
        $events->dispatch(new OrderShipped());

        self::assertSame(2, $mailer->sent);
        // A fresh instance each time, so listeners never keep per-request services between requests.
        self::assertSame(2, SendShippingMail::$created);
    }

    public function testListenerClassWithoutContainer(): void
    {
        $events = new EventDispatcher();
        $events->listen(OrderEvent::class, InvokableListener::class);

        self::assertSame(['invoke'], $events->dispatch(new OrderShipped())->log);
    }

    public function testHandleIsPreferredOverInvokeAndMustBePublic(): void
    {
        $events = new EventDispatcher(new Container());
        $events->listen(OrderEvent::class, PrivateHandle::class);

        $this->expectException(EventException::class);
        $this->expectExceptionMessage('needs a public handle() or __invoke() method');
        $events->dispatch(new OrderShipped());
    }

    public function testStoppableEventStopsFurtherListeners(): void
    {
        $events = new EventDispatcher();
        $events->listen(StoppableOrder::class, static function (StoppableOrder $e): void {
            $e->log[] = 'first';
            $e->stopped = true;
        });
        $events->listen(StoppableOrder::class, static function (StoppableOrder $e): void {
            $e->log[] = 'second';
        });

        self::assertSame(['first'], $events->dispatch(new StoppableOrder())->log);

        $already = new StoppableOrder();
        $already->stopped = true;
        self::assertSame([], $events->dispatch($already)->log);
    }

    public function testListenerExceptionStopsDispatchAndReachesTheCaller(): void
    {
        $events = new EventDispatcher();
        $events->listen(OrderEvent::class, static function (): never {
            throw new RuntimeException('boom');
        });
        $after = false;
        $events->listen(OrderEvent::class, static function () use (&$after): void {
            $after = true;
        });

        try {
            $events->dispatch(new OrderShipped());
            self::fail('exception expected');
        } catch (RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }

        self::assertFalse($after);
    }

    public function testCallableArraysAndStringFunctionsAreAccepted(): void
    {
        $events = new EventDispatcher();
        $listener = new InvokableListener();
        $events->listen(OrderEvent::class, [$listener, '__invoke']);
        $events->listen(OrderEvent::class, 'is_object');

        self::assertSame(['invoke'], $events->dispatch(new OrderShipped())->log);
        self::assertSame([OrderEvent::class => ['Closure', 'Closure']], $events->listeners());
    }

    public function testInvalidRegistrationsFailEarly(): void
    {
        $events = new EventDispatcher();

        foreach ([
            ['App\\Events\\Missing', InvokableListener::class, 'no such class or interface'],
            [OrderEvent::class, 'App\\Listeners\\Missing', 'does not exist'],
            [OrderEvent::class, NoHandleMethod::class, 'needs a handle() or __invoke() method'],
        ] as [$event, $listener, $message]) {
            try {
                $events->listen($event, $listener);
                self::fail('EventException expected for ' . $listener);
            } catch (EventException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }

        self::assertSame([], $events->listeners());
    }

    public function testListenerThatTheContainerCannotBuildGivesAClearError(): void
    {
        $events = new EventDispatcher(new Container());
        $events->listen(OrderEvent::class, NeedsUnboundInterface::class);

        $this->expectException(EventException::class);
        $this->expectExceptionMessage('Listener ' . NeedsUnboundInterface::class . ' could not be created');
        $events->dispatch(new OrderShipped());
    }

    public function testServiceProviderRegistersDispatcherAndConfiguredListeners(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->container()->instance(Config::class, new Config(['events' => ['listeners' => [
            OrderShipped::class => [InvokableListener::class],
        ]]]));
        $app->register(new EventServiceProvider());
        $app->boot();

        $events = $app->container()->make(EventDispatcherInterface::class);
        self::assertSame($app->container()->make(EventDispatcher::class), $events);
        self::assertSame(['invoke'], $events->dispatch(new OrderShipped())->log);
    }

    public function testServiceProviderWithoutConfig(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->container()->instance(Config::class, new Config([]));
        $app->register(new EventServiceProvider());
        $app->boot();
        self::assertSame([], $app->container()->make(EventDispatcher::class)->listeners());
    }

    /**
     * @return iterable<string, array{array<mixed>}>
     */
    public static function invalidListenerConfig(): iterable
    {
        yield 'listener not in a list' => [[OrderShipped::class => InvokableListener::class]];
        yield 'listener not a class name' => [[OrderShipped::class => [123]]];
    }

    /**
     * @param array<mixed> $listeners
     */
    #[DataProvider('invalidListenerConfig')]
    public function testServiceProviderRejectsInvalidConfig(array $listeners): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->container()->instance(Config::class, new Config(['events' => ['listeners' => $listeners]]));
        $app->register(new EventServiceProvider());

        $this->expectException(EventException::class);
        $this->expectExceptionMessage('events.listeners');
        $app->boot();
    }
}

interface UnboundDependency {}

final class NeedsUnboundInterface
{
    public function __construct(public readonly UnboundDependency $dependency) {}

    public function handle(object $event): void {}
}
