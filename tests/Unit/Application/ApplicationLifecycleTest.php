<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Unit\Application;

use ArrayObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use VeyroPHP\Application\Application;
use VeyroPHP\Application\Exception\ApplicationException;
use VeyroPHP\Container\Container;
use VeyroPHP\Tests\Fixtures\Application\FailingBootProvider;
use VeyroPHP\Tests\Fixtures\Application\FirstBootableProvider;
use VeyroPHP\Tests\Fixtures\Application\RegisterOnlyProvider;
use VeyroPHP\Tests\Fixtures\Application\SecondBootableProvider;

#[CoversClass(Application::class)]
#[CoversClass(ApplicationException::class)]
final class ApplicationLifecycleTest extends TestCase
{
    private Application $app;

    /** @var ArrayObject<int, string> */
    private ArrayObject $log;

    protected function setUp(): void
    {
        $this->app = new Application(sys_get_temp_dir());
        $this->log = new ArrayObject();
    }

    public function testApplicationIsRegisteredInItsContainer(): void
    {
        self::assertSame($this->app, $this->app->container()->get(Application::class));
    }

    public function testUsesGivenContainer(): void
    {
        $container = new Container();
        $app = new Application(sys_get_temp_dir(), $container);

        self::assertSame($container, $app->container());
        self::assertSame($app, $container->get(Application::class));
    }

    public function testRegisterRunsImmediatelyAndBootRunsInRegistrationOrder(): void
    {
        $this->app->register(new FirstBootableProvider($this->log));
        $this->app->register(new SecondBootableProvider($this->log));
        $this->app->register(new RegisterOnlyProvider($this->log));

        self::assertSame(['first:register', 'second:register', 'register-only:register'], $this->log->getArrayCopy());

        $this->app->boot();

        self::assertSame(
            ['first:register', 'second:register', 'register-only:register', 'first:boot:hello', 'second:boot'],
            $this->log->getArrayCopy(),
        );
    }

    public function testProvidersAreListedInRegistrationOrder(): void
    {
        $second = new SecondBootableProvider($this->log);
        $first = new FirstBootableProvider($this->log);
        $this->app->register($second);
        $this->app->register($first);

        self::assertSame([$second, $first], $this->app->providers());
    }

    public function testHooksRunAroundProviderBoot(): void
    {
        $this->app->register(new SecondBootableProvider($this->log));
        $this->app->afterBoot(function (Application $app): void {
            self::assertTrue($app->isBooted());
            $this->log[] = 'after';
        });
        $this->app->beforeBoot(function (Application $app): void {
            self::assertFalse($app->isBooted());
            $this->log[] = 'before';
        });

        $this->app->boot();

        self::assertSame(['second:register', 'before', 'second:boot', 'after'], $this->log->getArrayCopy());
    }

    public function testIsBootedReflectsState(): void
    {
        self::assertFalse($this->app->isBooted());
        $this->app->boot();
        self::assertTrue($this->app->isBooted());
    }

    public function testBootTwiceFails(): void
    {
        $this->app->boot();

        $this->expectException(ApplicationException::class);
        $this->expectExceptionMessage('Cannot boot');

        $this->app->boot();
    }

    public function testRegisterAfterBootFails(): void
    {
        $this->app->boot();

        $this->expectException(ApplicationException::class);
        $this->expectExceptionMessage('Cannot register ' . SecondBootableProvider::class);

        $this->app->register(new SecondBootableProvider($this->log));
    }

    public function testHooksAfterBootFail(): void
    {
        $this->app->boot();

        try {
            $this->app->beforeBoot(static function (): void {});
            self::fail('Expected beforeBoot() to throw.');
        } catch (ApplicationException) {
        }

        $this->expectException(ApplicationException::class);
        $this->app->afterBoot(static function (): void {});
    }

    public function testDuplicateProviderClassFails(): void
    {
        $this->app->register(new SecondBootableProvider($this->log));

        $this->expectException(ApplicationException::class);
        $this->expectExceptionMessage('already registered');

        $this->app->register(new SecondBootableProvider($this->log));
    }

    public function testFailedBootLeavesApplicationUnbootedAndPropagates(): void
    {
        $this->app->register(new FailingBootProvider());

        try {
            $this->app->boot();
            self::fail('Expected boot() to throw.');
        } catch (RuntimeException $e) {
            self::assertSame('boot failed', $e->getMessage());
        }

        self::assertFalse($this->app->isBooted());
    }
}
