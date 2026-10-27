<?php

declare(strict_types=1);

namespace VeyroPHP\Auth\Rbac;

use VeyroPHP\Application\Application;
use VeyroPHP\Application\ServiceProvider;
use VeyroPHP\Config\Config;
use VeyroPHP\Container\Container;
use VeyroPHP\Database\Connection\Connection;

/**
 * Registers Rbac as a scoped service (permissions memoized per request).
 * Config "rbac.super_admin" (default "super-admin") names the role that passes every check.
 */
final class RbacServiceProvider implements ServiceProvider
{
    public function register(Application $app): void
    {
        $app->container()->scoped(Rbac::class, static function (Container $c): Rbac {
            $superAdmin = $c->make(Config::class)->get('rbac.super_admin', 'super-admin');

            return new Rbac($c->make(Connection::class), is_string($superAdmin) ? $superAdmin : 'super-admin');
        });
    }
}
