<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Unit\Container;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;
use stdClass;
use VeyroPHP\Container\Container;
use VeyroPHP\Container\Exception\CircularDependencyException;
use VeyroPHP\Container\Exception\ContainerException;
use VeyroPHP\Container\Exception\NotFoundException;
use VeyroPHP\Exception\VeyroPHPException;
use VeyroPHP\Tests\Fixtures\Container\AbstractService;
use VeyroPHP\Tests\Fixtures\Container\CycleA;
use VeyroPHP\Tests\Fixtures\Container\FileLogger;
use VeyroPHP\Tests\Fixtures\Container\LoggerContract;
use VeyroPHP\Tests\Fixtures\Container\NeedsConcrete;
use VeyroPHP\Tests\Fixtures\Container\NeedsContainer;
use VeyroPHP\Tests\Fixtures\Container\NeedsLogger;
use VeyroPHP\Tests\Fixtures\Container\NeedsNested;
use VeyroPHP\Tests\Fixtures\Container\NeedsRequestContext;
use VeyroPHP\Tests\Fixtures\Container\NeedsScalar;
use VeyroPHP\Tests\Fixtures\Container\NeedsThrowing;
use VeyroPHP\Tests\Fixtures\Container\NeedsUnion;
use VeyroPHP\Tests\Fixtures\Container\NoConstructor;
use VeyroPHP\Tests\Fixtures\Container\NullLogger;
use VeyroPHP\Tests\Fixtures\Container\OptionalLogger;
use VeyroPHP\Tests\Fixtures\Container\PrivateConstructor;
use VeyroPHP\Tests\Fixtures\Container\RequestContext;
use VeyroPHP\Tests\Fixtures\Container\SelfCycle;
use VeyroPHP\Tests\Fixtures\Container\ThrowingConstructor;
use VeyroPHP\Tests\Fixtures\Container\Untyped;
use VeyroPHP\Tests\Fixtures\Container\WithDefaults;
use VeyroPHP\Tests\Fixtures\Container\WithVariadic;

#[CoversClass(Container::class)]
#[CoversClass(ContainerException::class)]
#[CoversClass(NotFoundException::class)]
#[CoversClass(CircularDependencyException::class)]
final class ContainerTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        $this->container = new Container();
    }

    // Autowiring

    public function testAutowiresClassWithoutConstructor(): void
    {
        self::assertInstanceOf(NoConstructor::class, $this->container->get(NoConstructor::class));
    }

    public function testAutowiresConstructorDependenciesRecursively(): void
    {
        $service = $this->container->make(NeedsNested::class);

        self::assertInstanceOf(NoConstructor::class, $service->inner->dependency);
    }

    public function testAutowiredUnboundClassesAreTransient(): void
    {
        self::assertNotSame(
            $this->container->get(NoConstructor::class),
            $this->container->get(NoConstructor::class),
        );
    }

    public function testUsesDefaultValuesForBuiltinParameters(): void
    {
        $service = $this->container->make(WithDefaults::class);

        self::assertSame(3, $service->retries);
        self::assertSame('default', $service->name);
    }

    public function testUsesDefaultForUnboundOptionalInterface(): void
    {
        self::assertNull($this->container->make(OptionalLogger::class)->logger);
    }

    public function testResolvesOptionalInterfaceWhenBound(): void
    {
        $this->container->bind(LoggerContract::class, FileLogger::class);

        self::assertInstanceOf(FileLogger::class, $this->container->make(OptionalLogger::class)->logger);
    }

    public function testVariadicParametersReceiveNoArguments(): void
    {
        $this->container->bind(LoggerContract::class, FileLogger::class);

        self::assertSame([], $this->container->make(WithVariadic::class)->loggers);
    }

    public function testInjectsContainerItself(): void
    {
        self::assertSame($this->container, $this->container->make(NeedsContainer::class)->container);
        self::assertSame($this->container, $this->container->get(Container::class));
        self::assertSame($this->container, $this->container->get(ContainerInterface::class));
    }

    // Interface bindings

    public function testBindsInterfaceToImplementation(): void
    {
        $this->container->bind(LoggerContract::class, FileLogger::class);

        self::assertInstanceOf(FileLogger::class, $this->container->make(NeedsLogger::class)->logger);
    }

    public function testRebindingReplacesPreviousBinding(): void
    {
        $this->container->singleton(LoggerContract::class, FileLogger::class);
        $this->container->get(LoggerContract::class);

        $this->container->singleton(LoggerContract::class, NullLogger::class);

        self::assertInstanceOf(NullLogger::class, $this->container->get(LoggerContract::class));
    }

    public function testUnboundInterfaceDependencyFailsWithChain(): void
    {
        try {
            $this->container->get(NeedsLogger::class);
            self::fail('Expected a ContainerException.');
        } catch (ContainerException $e) {
            self::assertNotInstanceOf(NotFoundExceptionInterface::class, $e);
            self::assertStringContainsString('$logger', $e->getMessage());
            self::assertStringContainsString(NeedsLogger::class . '::__construct()', $e->getMessage());
            self::assertStringContainsString(LoggerContract::class, $e->getMessage());
        }
    }

    public function testRejectsBindingToMissingClass(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('does not exist');

        // Deliberately invalid: checks the runtime guard for callers without static analysis.
        // @phpstan-ignore argument.type
        $this->container->bind(LoggerContract::class, 'App\\Missing\\Logger');
    }

    public function testRejectsBindingToNonSubtype(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('does not extend or implement');

        $this->container->bind(LoggerContract::class, NoConstructor::class);
    }

    public function testRejectsBindingToAbstractClass(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('abstract');

        $this->container->bind('service', AbstractService::class);
    }

    public function testRejectsEmptyId(): void
    {
        $this->expectException(ContainerException::class);

        $this->container->bind('', NoConstructor::class);
    }

    public function testStringIdMayBindAnyClass(): void
    {
        $this->container->bind('logger', FileLogger::class);

        self::assertInstanceOf(FileLogger::class, $this->container->get('logger'));
    }

    // Factories and instances

    public function testFactoryReceivesContainer(): void
    {
        $this->container->bind('config.port', static fn(): int => 8080);
        $this->container->bind(
            NeedsScalar::class,
            static function (Container $c): NeedsScalar {
                $port = $c->get('config.port');
                self::assertIsInt($port);

                return new NeedsScalar($port);
            },
        );

        self::assertSame(8080, $this->container->make(NeedsScalar::class)->port);
    }

    public function testTransientFactoryRunsOnEveryResolution(): void
    {
        $calls = 0;
        $this->container->bind('counter', static function () use (&$calls): int {
            return ++$calls;
        });

        $this->container->get('counter');
        $this->container->get('counter');

        self::assertSame(2, $calls);
    }

    public function testInstanceReturnsSameValue(): void
    {
        $object = new stdClass();
        $this->container->instance('object', $object);

        self::assertSame($object, $this->container->get('object'));
    }

    public function testInstanceMayHoldNonObjectValues(): void
    {
        $this->container->instance('debug', false);

        self::assertFalse($this->container->get('debug'));
        self::assertTrue($this->container->has('debug'));
    }

    // Lifetimes

    public function testSingletonIsBuiltOnce(): void
    {
        $calls = 0;
        $this->container->singleton(LoggerContract::class, static function () use (&$calls): FileLogger {
            ++$calls;

            return new FileLogger();
        });

        self::assertSame($this->container->get(LoggerContract::class), $this->container->get(LoggerContract::class));
        self::assertSame(1, $calls);
    }

    public function testSingletonWithoutConcreteAutowiresId(): void
    {
        $this->container->singleton(NoConstructor::class);

        self::assertSame($this->container->get(NoConstructor::class), $this->container->get(NoConstructor::class));
    }

    public function testSingletonInterfaceBindingBuildsConcreteDirectly(): void
    {
        $this->container->singleton(FileLogger::class);
        $this->container->bind(LoggerContract::class, FileLogger::class);

        self::assertNotSame($this->container->get(FileLogger::class), $this->container->get(LoggerContract::class));
    }

    public function testScopedServiceIsReusedUntilScopeReset(): void
    {
        $this->container->scoped(RequestContext::class);

        $first = $this->container->get(RequestContext::class);
        self::assertSame($first, $this->container->get(RequestContext::class));

        $this->container->resetScope();

        self::assertNotSame($first, $this->container->get(RequestContext::class));
    }

    public function testResetScopeKeepsSingletons(): void
    {
        $this->container->singleton(NoConstructor::class);
        $first = $this->container->get(NoConstructor::class);

        $this->container->resetScope();

        self::assertSame($first, $this->container->get(NoConstructor::class));
    }

    public function testTransientServiceMayDependOnScopedService(): void
    {
        $this->container->scoped(RequestContext::class);

        $a = $this->container->make(NeedsRequestContext::class);
        $b = $this->container->make(NeedsRequestContext::class);

        self::assertNotSame($a, $b);
        self::assertSame($a->context, $b->context);
    }

    public function testSingletonCannotCaptureScopedService(): void
    {
        $this->container->scoped(RequestContext::class);
        $this->container->singleton(NeedsRequestContext::class);

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('cannot be injected into a singleton');

        $this->container->get(NeedsRequestContext::class);
    }

    public function testSingletonCannotCaptureAlreadyResolvedScopedService(): void
    {
        $this->container->scoped(RequestContext::class);
        $this->container->get(RequestContext::class);
        $this->container->singleton(NeedsRequestContext::class);

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('cannot be injected into a singleton');

        $this->container->get(NeedsRequestContext::class);
    }

    public function testSingletonFactoryCannotCaptureScopedServiceThroughTransient(): void
    {
        $this->container->scoped(RequestContext::class);
        $this->container->singleton('holder', static fn(Container $c): mixed => $c->get(NeedsRequestContext::class));

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('holder -> ' . NeedsRequestContext::class . ' -> ' . RequestContext::class);

        $this->container->get('holder');
    }

    // Circular dependencies

    public function testDetectsCircularDependencyWithFullChain(): void
    {
        $this->expectException(CircularDependencyException::class);
        $this->expectExceptionMessage(sprintf(
            'Circular dependency detected: %1$sCycleA -> %1$sCycleB -> %1$sCycleC -> %1$sCycleA.',
            'VeyroPHP\\Tests\\Fixtures\\Container\\',
        ));

        $this->container->get(CycleA::class);
    }

    public function testDetectsSelfDependency(): void
    {
        $this->expectException(CircularDependencyException::class);
        $this->expectExceptionMessage(SelfCycle::class . ' -> ' . SelfCycle::class);

        $this->container->get(SelfCycle::class);
    }

    public function testDetectsCircularDependencyThroughFactories(): void
    {
        $this->container->singleton('a', static fn(Container $c): mixed => $c->get('b'));
        $this->container->singleton('b', static fn(Container $c): mixed => $c->get('a'));

        $this->expectException(CircularDependencyException::class);
        $this->expectExceptionMessage('a -> b -> a');

        $this->container->get('a');
    }

    public function testResolutionStateIsCleanAfterCircularFailure(): void
    {
        try {
            $this->container->get(CycleA::class);
        } catch (CircularDependencyException) {
        }

        self::assertInstanceOf(NoConstructor::class, $this->container->get(NoConstructor::class));
    }

    // Not found and unresolvable

    public function testUnknownIdThrowsNotFound(): void
    {
        $this->expectException(NotFoundException::class);

        $this->container->get('missing.service');
    }

    public function testUnboundInterfaceThrowsNotFound(): void
    {
        $this->expectException(NotFoundExceptionInterface::class);

        $this->container->get(LoggerContract::class);
    }

    public function testHasReflectsBindingsAndInstantiableClasses(): void
    {
        self::assertFalse($this->container->has('missing.service'));
        self::assertFalse($this->container->has(LoggerContract::class));
        self::assertFalse($this->container->has(AbstractService::class));
        self::assertFalse($this->container->has(PrivateConstructor::class));
        self::assertTrue($this->container->has(NoConstructor::class));

        $this->container->bind(LoggerContract::class, FileLogger::class);

        self::assertTrue($this->container->has(LoggerContract::class));
    }

    public function testAbstractAndPrivateConstructorClassesAreNotFound(): void
    {
        foreach ([AbstractService::class, PrivateConstructor::class] as $id) {
            try {
                $this->container->get($id);
                self::fail("Expected NotFoundException for {$id}.");
            } catch (NotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testBuiltinParameterWithoutDefaultFails(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('built-in type "int"');

        $this->container->get(NeedsScalar::class);
    }

    public function testUntypedParameterWithoutDefaultFails(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('$untyped');

        $this->container->get(Untyped::class);
    }

    public function testUnionTypeIsNotAutowired(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('union and intersection types');

        $this->container->get(NeedsUnion::class);
    }

    public function testNestedFailureMessageContainsResolutionChain(): void
    {
        $this->container->bind('entry', static fn(Container $c): mixed => $c->get(NeedsLogger::class));

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('while resolving entry -> ' . NeedsLogger::class);

        $this->container->get('entry');
    }

    // Failure propagation

    public function testConstructorExceptionsPropagateUnchanged(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('constructor failed');

        $this->container->get(NeedsThrowing::class);
    }

    public function testFailedSingletonIsNotCachedAndCanBeRetried(): void
    {
        $attempts = 0;
        $this->container->singleton('flaky', static function () use (&$attempts): stdClass {
            if (++$attempts === 1) {
                throw new RuntimeException('first attempt fails');
            }

            return new stdClass();
        });

        try {
            $this->container->get('flaky');
            self::fail('Expected the first attempt to fail.');
        } catch (RuntimeException) {
        }

        self::assertInstanceOf(stdClass::class, $this->container->get('flaky'));
        self::assertSame(2, $attempts);
    }

    public function testThrowingConstructorDoesNotLeaveResolutionState(): void
    {
        try {
            $this->container->get(ThrowingConstructor::class);
        } catch (RuntimeException) {
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('constructor failed');

        $this->container->get(ThrowingConstructor::class);
    }

    // make()

    public function testMakeRejectsWrongType(): void
    {
        $this->container->bind(NoConstructor::class, static fn(): stdClass => new stdClass());

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('resolved to stdClass');

        $this->container->make(NoConstructor::class);
    }

    public function testExceptionsImplementPsrAndFrameworkInterfaces(): void
    {
        try {
            $this->container->get('missing.service');
            self::fail('Expected an exception.');
        } catch (NotFoundException $e) {
            self::assertInstanceOf(NotFoundExceptionInterface::class, $e);
            self::assertInstanceOf(ContainerExceptionInterface::class, $e);
            self::assertInstanceOf(VeyroPHPException::class, $e);
        }
    }

    public function testConcreteDependencyIsResolvedFromItsBinding(): void
    {
        $shared = new NoConstructor();
        $this->container->instance(NoConstructor::class, $shared);

        self::assertSame($shared, $this->container->make(NeedsConcrete::class)->dependency);
    }
}
