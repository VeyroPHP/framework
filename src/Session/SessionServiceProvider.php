<?php

declare(strict_types=1);

namespace VeyroPHP\Session;

use VeyroPHP\Application\Application;
use VeyroPHP\Application\BootableProvider;
use VeyroPHP\Cache\CacheManager;
use VeyroPHP\Config\Config;
use VeyroPHP\Config\Exception\ConfigException;
use VeyroPHP\Config\OptionalSettings;
use VeyroPHP\Container\Container;
use VeyroPHP\Http\Cookie;
use VeyroPHP\Http\HttpErrorResponder;

/**
 * Registers sessions: SessionHolder and Session (scoped per request),
 * StartSession and VerifyCsrfToken. Needs CacheServiceProvider.
 *
 * Config "session" (all optional; a missing key or null means the default): cookie,
 * lifetime (minutes), secure (bool or null = automatic on HTTPS), same_site, path,
 * domain, store (cache store name; default: the default cache store), csrf_except
 * (list of paths).
 *
 * The settings are checked at boot: a wrong type, or same_site "none" without
 * secure => true, stops the application with a ConfigException naming the key (HTTP-5).
 *
 * Add StartSession and then VerifyCsrfToken to the global middleware.
 */
final class SessionServiceProvider implements BootableProvider
{
    public function register(Application $app): void
    {
        $container = $app->container();

        $container->scoped(SessionHolder::class);
        $container->scoped(Session::class, static fn(Container $c): Session => $c->make(SessionHolder::class)->session());

        $container->bind(SessionConfig::class, static fn(Container $c): SessionConfig => self::sessionConfig($c->make(Config::class)));

        $container->bind(StartSession::class, static fn(Container $c): StartSession => new StartSession(
            $c->make(CacheManager::class)->store(OptionalSettings::nullableString($c->make(Config::class), 'session.store')),
            $c->make(SessionHolder::class),
            $c->make(SessionConfig::class),
            // Errors after regenerate() keep the new session cookie (VQ-046).
            $c->bound(HttpErrorResponder::class) ? $c->make(HttpErrorResponder::class) : null,
        ));

        $container->bind(VerifyCsrfToken::class, static fn(Container $c): VerifyCsrfToken => new VerifyCsrfToken(
            OptionalSettings::stringList($c->make(Config::class), 'session.csrf_except'),
        ));
    }

    /**
     * Checks every setting now, so a wrong one fails when the application starts.
     */
    public function boot(Application $app): void
    {
        $container = $app->container();

        if (!$container->bound(Config::class)) {
            return;
        }

        $config = $container->make(Config::class);
        self::sessionConfig($config);
        OptionalSettings::nullableString($config, 'session.store');
        OptionalSettings::stringList($config, 'session.csrf_except');
    }

    private static function sessionConfig(Config $config): SessionConfig
    {
        $secure = OptionalSettings::nullableBool($config, 'session.secure');
        $sameSite = strtolower(OptionalSettings::string($config, 'session.same_site', 'lax'));

        if (!in_array($sameSite, Cookie::SAME_SITE, true)) {
            throw ConfigException::invalidValue('session.same_site', 'lax, strict or none');
        }

        // Browsers drop SameSite=None cookies without Secure, and with automatic secure every
        // plain-HTTP request failed with a 500 when the cookie was built.
        if ($sameSite === 'none' && $secure !== true) {
            throw ConfigException::invalidValue('session.same_site', '"lax" or "strict" unless session.secure is true: "none" needs secure cookies');
        }

        return new SessionConfig(
            OptionalSettings::string($config, 'session.cookie', 'veyrophp_session'),
            OptionalSettings::int($config, 'session.lifetime', 120, 1),
            $secure,
            $sameSite,
            OptionalSettings::string($config, 'session.path', '/'),
            OptionalSettings::nullableString($config, 'session.domain'),
        );
    }
}
