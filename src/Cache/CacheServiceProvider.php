<?php

declare(strict_types=1);

namespace VeyroPHP\Cache;

use Psr\SimpleCache\CacheInterface;
use VeyroPHP\Application\Application;
use VeyroPHP\Application\ServiceProvider;
use VeyroPHP\Config\Config;
use VeyroPHP\Container\Container;

/**
 * Registers CacheManager and binds Cache and PSR-16 CacheInterface to the default store.
 *
 * Without a "cache" config section, a file store in storage/cache with prefix "app" is used.
 * Relative file-store paths are resolved inside the base path.
 */
final class CacheServiceProvider implements ServiceProvider
{
    public function register(Application $app): void
    {
        $container = $app->container();

        $container->singleton(CacheManager::class, static function (Container $c) use ($app): CacheManager {
            $config = $c->make(Config::class);
            $settings = $config->has('cache') ? $config->array('cache') : [
                'default' => 'file',
                'prefix' => 'app',
                'stores' => ['file' => ['driver' => 'file', 'path' => 'storage/cache']],
            ];

            return new CacheManager(
                $settings,
                static fn(string $path): string => preg_match('#^([A-Za-z]:[/\\\\]|[/\\\\])#', $path) === 1 ? $path : $app->basePath($path),
            );
        });

        $container->bind(Cache::class, static fn(Container $c): Cache => $c->make(CacheManager::class)->store());
        $container->bind(CacheInterface::class, static fn(Container $c): Cache => $c->make(CacheManager::class)->store());
    }
}
