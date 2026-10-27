<?php

declare(strict_types=1);

namespace VeyroPHP\Application;

/**
 * A service provider with a boot step.
 *
 * boot() runs after every provider has been registered, in registration order.
 */
interface BootableProvider extends ServiceProvider
{
    public function boot(Application $app): void;
}
