<?php

declare(strict_types=1);

namespace VeyroPHP\Security;

use VeyroPHP\Application\Application;
use VeyroPHP\Application\BootableProvider;
use VeyroPHP\Cache\CacheManager;
use VeyroPHP\Config\Config;
use VeyroPHP\Config\Exception\ConfigException;
use VeyroPHP\Config\OptionalSettings;
use VeyroPHP\Container\Container;
use VeyroPHP\Http\ClientIp;
use VeyroPHP\Http\HttpErrorResponder;
use VeyroPHP\Http\ResponseFactory;

/**
 * Registers RateLimiter, ClientIp, TrustProxies, SecurityHeaders, Cors and UploadStore.
 * Needs CacheServiceProvider (rate limits) and HttpServiceProvider (CORS, error pages).
 *
 * Config (config/security.php, all optional; a missing key or null means the default):
 * - security.trusted_proxies: list of proxy IPs/CIDRs whose X-Forwarded-For is trusted
 * - security.headers: header => value (null disables a default header)
 * - security.hsts_seconds: HSTS max-age on HTTPS (default 0 = off)
 * - security.cors.allowed_origins / allowed_methods / allowed_headers / exposed_headers / max_age / allow_credentials
 * - security.uploads_path: upload directory relative to the base path (default storage/uploads)
 *
 * The settings are checked at boot: a wrong type or an invalid proxy range stops the
 * application with a ConfigException naming the key (HTTP-5, HTTP-7), instead of being
 * replaced by a default. Messages never contain the values.
 */
final class SecurityServiceProvider implements BootableProvider
{
    public function register(Application $app): void
    {
        $container = $app->container();

        $container->singleton(RateLimiter::class, static fn(Container $c): RateLimiter => new RateLimiter($c->make(CacheManager::class)->store()));

        $container->singleton(ClientIp::class, static fn(Container $c): ClientIp => new ClientIp(self::trustedProxies($c->make(Config::class))));

        $container->singleton(TrustProxies::class, static fn(Container $c): TrustProxies => new TrustProxies($c->make(ClientIp::class)));

        $container->bind(ThrottleRequests::class, static fn(Container $c): ThrottleRequests => new ThrottleRequests(
            $c->make(RateLimiter::class),
            $c->make(ClientIp::class),
            $c->make(Config::class)->has('security.throttle.ipv6_prefix') ? $c->make(Config::class)->int('security.throttle.ipv6_prefix') : 64,
        ));

        $container->singleton(SecurityHeaders::class, static function (Container $c): SecurityHeaders {
            $config = $c->make(Config::class);

            return new SecurityHeaders(
                OptionalSettings::nullableStringMap($config, 'security.headers'),
                OptionalSettings::int($config, 'security.hsts_seconds', 0, 0),
                $c->make(HttpErrorResponder::class),
            );
        });

        $container->singleton(Cors::class, static function (Container $c): Cors {
            $config = $c->make(Config::class);

            return new Cors(
                $c->make(HttpErrorResponder::class),
                $c->make(ResponseFactory::class),
                ...self::cors($config),
            );
        });

        $container->singleton(UploadStore::class, static fn(Container $c): UploadStore => new UploadStore(
            $app->basePath(OptionalSettings::string($c->make(Config::class), 'security.uploads_path', 'storage/uploads')),
        ));
    }

    /**
     * Checks every setting now, so a wrong one fails when the application starts, not on
     * the first request that happens to need it.
     */
    public function boot(Application $app): void
    {
        $container = $app->container();

        if (!$container->bound(Config::class)) {
            return;
        }

        $config = $container->make(Config::class);
        self::trustedProxies($config);
        OptionalSettings::nullableStringMap($config, 'security.headers');
        OptionalSettings::int($config, 'security.hsts_seconds', 0, 0);
        self::cors($config);
        OptionalSettings::string($config, 'security.uploads_path', 'storage/uploads');
    }

    /**
     * @return list<string>
     */
    private static function trustedProxies(Config $config): array
    {
        $proxies = OptionalSettings::stringList($config, 'security.trusted_proxies');

        foreach ($proxies as $index => $range) {
            if (!ClientIp::isValidRange($range)) {
                // A malformed entry used to match nothing, so the proxy was silently not trusted.
                throw ConfigException::invalidValue('security.trusted_proxies.' . $index, 'an IP address or a CIDR range such as 10.0.0.0/8, without spaces');
            }
        }

        return $proxies;
    }

    /**
     * @return array{list<string>, list<string>, list<string>, list<string>, int, bool}
     */
    private static function cors(Config $config): array
    {
        return [
            OptionalSettings::stringList($config, 'security.cors.allowed_origins'),
            OptionalSettings::stringList($config, 'security.cors.allowed_methods', ['GET', 'POST', 'PUT', 'PATCH', 'DELETE']),
            OptionalSettings::stringList($config, 'security.cors.allowed_headers', ['Content-Type', 'Accept', 'Authorization', 'X-CSRF-Token', 'X-Requested-With']),
            OptionalSettings::stringList($config, 'security.cors.exposed_headers'),
            OptionalSettings::int($config, 'security.cors.max_age', 600, 0),
            OptionalSettings::bool($config, 'security.cors.allow_credentials', false),
        ];
    }
}
