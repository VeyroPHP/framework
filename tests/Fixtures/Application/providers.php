<?php

declare(strict_types=1);

// Service providers used by the application lifecycle tests. Each records the
// calls it receives in a shared, explicitly passed log.

namespace VeyroPHP\Tests\Fixtures\Application;

use ArrayObject;
use RuntimeException;
use VeyroPHP\Application\Application;
use VeyroPHP\Application\BootableProvider;
use VeyroPHP\Application\ServiceProvider;

final class Greeting
{
    public function __construct(public readonly string $text) {}
}

final class RegisterOnlyProvider implements ServiceProvider
{
    /** @param ArrayObject<int, string> $log */
    public function __construct(private readonly ArrayObject $log) {}

    public function register(Application $app): void
    {
        $this->log[] = 'register-only:register';
        $app->container()->singleton(Greeting::class, static fn(): Greeting => new Greeting('hello'));
    }
}

final class FirstBootableProvider implements BootableProvider
{
    /** @param ArrayObject<int, string> $log */
    public function __construct(private readonly ArrayObject $log) {}

    public function register(Application $app): void
    {
        $this->log[] = 'first:register';
    }

    public function boot(Application $app): void
    {
        // Uses a service registered by a provider added later, which works because boot runs after all registers.
        $greeting = $app->container()->make(Greeting::class);
        $this->log[] = 'first:boot:' . $greeting->text;
    }
}

final class SecondBootableProvider implements BootableProvider
{
    /** @param ArrayObject<int, string> $log */
    public function __construct(private readonly ArrayObject $log) {}

    public function register(Application $app): void
    {
        $this->log[] = 'second:register';
    }

    public function boot(Application $app): void
    {
        $this->log[] = 'second:boot';
    }
}

final class FailingBootProvider implements BootableProvider
{
    public function register(Application $app): void {}

    public function boot(Application $app): void
    {
        throw new RuntimeException('boot failed');
    }
}
