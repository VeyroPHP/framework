<?php

declare(strict_types=1);

namespace VeyroPHP\Application;

/**
 * Registers services in the application container.
 *
 * register() should only add bindings. Work that needs other services to be
 * registered first belongs in BootableProvider::boot().
 */
interface ServiceProvider
{
    public function register(Application $app): void;
}
