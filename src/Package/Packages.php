<?php

declare(strict_types=1);

namespace VeyroPHP\Package;

use ReflectionClass;
use VeyroPHP\Application\Application;
use VeyroPHP\Application\ServiceProvider;
use VeyroPHP\Config\Config;

/**
 * Registers the service providers of discovered packages:
 *
 *     // bootstrap/app.php, after the framework's own providers
 *     Packages::register($app);
 *
 * Config "packages.dont_discover": package names to skip, or ["*"] to skip
 * all (then register package providers yourself, explicitly).
 *
 * Installing a package means trusting its code: its provider runs on every
 * request. "php veyro package:discover" lists what is registered.
 */
final class Packages
{
    /**
     * @return list<string> the provider classes that were registered, in order
     */
    public static function register(Application $app, ?PackageManifest $manifest = null): array
    {
        $manifest ??= new PackageManifest($app->basePath());
        $dontDiscover = [];

        if ($app->container()->bound(Config::class)) {
            foreach ((array) $app->container()->make(Config::class)->get('packages.dont_discover', []) as $name) {
                if (is_string($name)) {
                    $dontDiscover[] = $name;
                }
            }
        }

        $registered = [];
        $already = array_map(static fn(ServiceProvider $p): string => $p::class, $app->providers());

        foreach ($manifest->providers($dontDiscover) as $class) {
            if (in_array($class, $already, true)) {
                continue;
            }

            $app->register(self::instantiate($class));
            $registered[] = $class;
        }

        return $registered;
    }

    public static function instantiate(string $class): ServiceProvider
    {
        if (!class_exists($class)) {
            throw new PackageException(sprintf('Package provider %s does not exist; run "composer dump-autoload" or "php veyro package:discover".', $class));
        }

        $reflection = new ReflectionClass($class);

        if (!$reflection->implementsInterface(ServiceProvider::class) || !$reflection->isInstantiable()) {
            throw new PackageException(sprintf('Package provider %s must be a concrete class implementing %s.', $class, ServiceProvider::class));
        }

        if (($reflection->getConstructor()?->getNumberOfRequiredParameters() ?? 0) > 0) {
            throw new PackageException(sprintf('Package provider %s must be constructible without arguments.', $class));
        }

        $provider = $reflection->newInstance();
        \assert($provider instanceof ServiceProvider);

        return $provider;
    }
}
