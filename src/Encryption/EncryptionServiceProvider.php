<?php

declare(strict_types=1);

namespace VeyroPHP\Encryption;

use VeyroPHP\Application\Application;
use VeyroPHP\Application\ServiceProvider;
use VeyroPHP\Config\Config;
use VeyroPHP\Container\Container;
use VeyroPHP\Security\UrlSigner;

/**
 * Registers Encrypter and UrlSigner (singletons, created on first use) from
 * config "app.key" and "app.previous_keys":
 *
 *     'key' => $env->string('APP_KEY', ''),
 *     'previous_keys' => $env->string('APP_PREVIOUS_KEYS', ''),   // comma-separated, or a list
 *
 * A missing or invalid key only fails when encryption or signing is used.
 */
final class EncryptionServiceProvider implements ServiceProvider
{
    public function register(Application $app): void
    {
        $container = $app->container();

        $container->singleton(Encrypter::class, static function (Container $c): Encrypter {
            [$key, $previous] = self::keys($c->make(Config::class));

            return new Encrypter($key, $previous);
        });

        $container->singleton(UrlSigner::class, static function (Container $c): UrlSigner {
            [$key, $previous] = self::keys($c->make(Config::class));

            return new UrlSigner($key, $previous);
        });
    }

    /**
     * @return array{string, list<string>} raw current key and raw previous keys
     */
    private static function keys(Config $config): array
    {
        [$key, $previous] = AppKeys::fromConfig($config);

        return [Encrypter::parseKey($key), array_map(Encrypter::parseKey(...), $previous)];
    }
}
