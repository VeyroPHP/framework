<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Unit\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VeyroPHP\Application\Application;
use VeyroPHP\Cache\CacheServiceProvider;
use VeyroPHP\Config\Config;
use VeyroPHP\Config\Exception\ConfigException;
use VeyroPHP\Http\ClientIp;
use VeyroPHP\Http\HttpServiceProvider;
use VeyroPHP\Security\Cors;
use VeyroPHP\Security\SecurityHeaders;
use VeyroPHP\Security\SecurityServiceProvider;
use VeyroPHP\Security\UploadStore;
use VeyroPHP\Session\SessionConfig;
use VeyroPHP\Session\SessionServiceProvider;

/**
 * HTTP-5 and HTTP-7: wrong security and session settings stop the application at boot
 * with a ConfigException naming the key, instead of being replaced by defaults.
 */
#[CoversClass(SecurityServiceProvider::class)]
#[CoversClass(SessionServiceProvider::class)]
#[CoversClass(SessionConfig::class)]
final class SecuritySessionConfigTest extends TestCase
{
    /**
     * @param array<string, mixed> $config
     */
    private static function boot(array $config): Application
    {
        $app = new Application(sys_get_temp_dir());
        $app->container()->instance(Config::class, new Config([
            'cache' => ['default' => 'array', 'stores' => ['array' => ['driver' => 'array']]],
            ...$config,
        ]));

        foreach ([new CacheServiceProvider(), new HttpServiceProvider(), new SecurityServiceProvider(), new SessionServiceProvider()] as $provider) {
            $app->register($provider);
        }

        $app->boot();

        return $app;
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidConfigs(): iterable
    {
        yield 'trusted proxies as a string' => [['security' => ['trusted_proxies' => '10.0.0.1,10.0.0.2']], 'security.trusted_proxies'];
        yield 'a proxy that is not a string' => [['security' => ['trusted_proxies' => ['10.0.0.1', 42]]], 'security.trusted_proxies.1'];
        yield 'a proxy range that is not valid' => [['security' => ['trusted_proxies' => ['10.0.0.0/8', '10.0.0.0/33']]], 'security.trusted_proxies.1'];
        yield 'a proxy with a space' => [['security' => ['trusted_proxies' => ['10.0.0.1 ']]], 'security.trusted_proxies.0'];
        yield 'hsts as a string' => [['security' => ['hsts_seconds' => '31536000']], 'security.hsts_seconds'];
        yield 'negative hsts' => [['security' => ['hsts_seconds' => -1]], 'security.hsts_seconds'];
        yield 'headers as a string' => [['security' => ['headers' => 'X-Frame-Options: DENY']], 'security.headers'];
        yield 'a header value that is not a string' => [['security' => ['headers' => ['X-Frame-Options' => false]]], 'security.headers.X-Frame-Options'];
        yield 'cors origins as a string' => [['security' => ['cors' => ['allowed_origins' => 'https://a.example']]], 'security.cors.allowed_origins'];
        yield 'cors max age as a string' => [['security' => ['cors' => ['max_age' => '600']]], 'security.cors.max_age'];
        yield 'cors credentials as a string' => [['security' => ['cors' => ['allow_credentials' => 'true']]], 'security.cors.allow_credentials'];
        yield 'uploads path as a number' => [['security' => ['uploads_path' => 5]], 'security.uploads_path'];
        yield 'session lifetime as a string' => [['session' => ['lifetime' => '120']], 'session.lifetime'];
        yield 'session secure as a string' => [['session' => ['secure' => 'true']], 'session.secure'];
        yield 'session cookie as a number' => [['session' => ['cookie' => 1]], 'session.cookie'];
        yield 'session same_site unknown' => [['session' => ['same_site' => 'sometimes']], 'session.same_site'];
        yield 'same_site none with automatic secure' => [['session' => ['same_site' => 'none']], 'session.same_site'];
        yield 'same_site none without secure' => [['session' => ['same_site' => 'None', 'secure' => false]], 'session.same_site'];
        yield 'session store as a number' => [['session' => ['store' => 3]], 'session.store'];
        yield 'csrf except as a string' => [['session' => ['csrf_except' => '/webhooks/*']], 'session.csrf_except'];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('invalidConfigs')]
    public function testInvalidSettingsStopTheApplicationAtBoot(array $config, string $key): void
    {
        try {
            self::boot($config);
            self::fail('booted with an invalid ' . $key);
        } catch (ConfigException $e) {
            self::assertStringContainsString('"' . $key . '"', $e->getMessage());
            self::assertStringNotContainsString('10.0.0.0/33', $e->getMessage(), 'values are never shown');
            self::assertStringNotContainsString('31536000', $e->getMessage());
        }
    }

    public function testValidAndMissingSettingsKeepTheirMeaning(): void
    {
        $app = self::boot([
            'security' => ['trusted_proxies' => ['10.0.0.0/8', '::1'], 'headers' => ['X-Frame-Options' => null], 'hsts_seconds' => 3600, 'cors' => ['max_age' => 60, 'allow_credentials' => true]],
            'session' => ['same_site' => 'none', 'secure' => true, 'domain' => null, 'secure_unused' => 1],
        ]);
        $c = $app->container();

        self::assertTrue($c->make(ClientIp::class)->isTrusted('10.1.2.3'));
        self::assertInstanceOf(SecurityHeaders::class, $c->make(SecurityHeaders::class));
        self::assertInstanceOf(Cors::class, $c->make(Cors::class));
        self::assertInstanceOf(UploadStore::class, $c->make(UploadStore::class));
        $session = $c->make(SessionConfig::class);
        self::assertSame(['none', true, null, 120], [$session->sameSite, $session->secure, $session->domain, $session->lifetimeMinutes]);

        // Nothing configured, or null: the documented defaults.
        $defaults = self::boot(['security' => ['trusted_proxies' => null, 'hsts_seconds' => null], 'session' => ['secure' => null, 'cookie' => null]])->container()->make(SessionConfig::class);
        self::assertSame(['veyrophp_session', null, 'lax', '/'], [$defaults->cookieName, $defaults->secure, $defaults->sameSite, $defaults->path]);
    }

    public function testSessionConfigItselfRefusesSameSiteNoneWithoutSecure(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SessionConfig(sameSite: 'none', secure: null);
    }
}
