<?php

declare(strict_types=1);

namespace VeyroPHP\View;

use VeyroPHP\Application\Application;
use VeyroPHP\Application\ServiceProvider;
use VeyroPHP\Config\Config;
use VeyroPHP\Container\Container;

/**
 * Registers View as a singleton.
 *
 * Config key "view.path" (optional) is the template directory, relative to the
 * base path. Default: "resources/views".
 */
final class ViewServiceProvider implements ServiceProvider
{
    public function register(Application $app): void
    {
        $app->container()->singleton(View::class, static function (Container $container) use ($app): View {
            $config = $container->make(Config::class);

            return new View($app->basePath($config->has('view.path') ? $config->string('view.path') : 'resources/views'));
        });
    }
}
