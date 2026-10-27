<?php

declare(strict_types=1);

namespace VeyroPHP\Auth;

use Psr\EventDispatcher\EventDispatcherInterface;
use VeyroPHP\Application\Application;
use VeyroPHP\Application\ServiceProvider;
use VeyroPHP\Auth\Access\AccessRules;
use VeyroPHP\Auth\Access\Gate;
use VeyroPHP\Auth\Mail\ResetPasswordMail;
use VeyroPHP\Auth\Mail\VerifyEmailMail;
use VeyroPHP\Auth\Passwords\PasswordBroker;
use VeyroPHP\Auth\Rbac\Rbac;
use VeyroPHP\Auth\Remember\RememberMe;
use VeyroPHP\Auth\Remember\RememberSchema;
use VeyroPHP\Auth\Remember\RememberTokens;
use VeyroPHP\Auth\Tokens\ApiTokens;
use VeyroPHP\Auth\Verification\EmailVerifier;
use VeyroPHP\Auth\Verification\EnsureEmailIsVerified;
use VeyroPHP\Config\Config;
use VeyroPHP\Config\OptionalSettings;
use VeyroPHP\Container\Container;
use VeyroPHP\Database\Connection\Connection;
use VeyroPHP\Database\Schema\Schema;
use VeyroPHP\Encryption\AppKeys;
use VeyroPHP\Encryption\Encrypter;
use VeyroPHP\Http\HttpErrorResponder;
use VeyroPHP\Http\ResponseFactory;
use VeyroPHP\Mail\Mailable;
use VeyroPHP\Mail\Mailer;
use VeyroPHP\Orm\Model;
use VeyroPHP\Orm\Orm;
use VeyroPHP\Queue\Queue;
use VeyroPHP\Routing\Router;
use VeyroPHP\Security\PasswordHasher;
use VeyroPHP\Security\RateLimiter;
use VeyroPHP\Security\UrlSigner;
use VeyroPHP\Session\Session;
use VeyroPHP\Session\SessionConfig;

/**
 * Registers PasswordHasher (singleton), AuthConfig, and Auth (scoped per request).
 * Auth sends Login, LoginFailed and Logout events when an event dispatcher is registered.
 *
 * Also registers PasswordBroker and EmailVerifier (created on first use; they need
 * Mail, the encryption key for signed links, "app.url" for absolute links and the
 * named routes "password.reset" and "verification.verify"). Config:
 *
 *     'passwords' => ['table' => 'password_reset_tokens', 'expire' => 60, 'throttle' => 60,
 *                     'route' => 'password.reset', 'mail' => ResetPasswordMail::class,
 *                     'revoke_tokens' => true],   // a reset also revokes the user's API tokens
 *     'verification' => ['expire' => 60, 'route' => 'verification.verify',
 *                        'notice_path' => '/email/verify', 'mail' => VerifyEmailMail::class],
 *     'email_column' => 'email',
 *     'queue_mail' => true,   // queue the mails when a queue is registered
 *
 * Needs Session, ORM and HTTP providers.
 *
 * Config "auth": model (required), identifier, password_column, login_path,
 * hash_algorithm (default, bcrypt or argon2id), hash_options.
 *
 * "Remember me": RememberTokens (scoped; config auth.remember: table, lifetime_days,
 * cookie; Secure, path and domain follow the session cookie). LoginThrottle
 * (singleton; config security.login, needs SecurityServiceProvider's RateLimiter).
 *
 * Authorization: AccessRules (singleton; define abilities and policies in a provider's
 * boot()) and Gate (scoped; checks for the logged-in user, using RBAC when registered).
 *
 * Sessions keep a fingerprint of the password hash (an HMAC with a key derived from
 * APP_KEY and its previous keys): changing or resetting the password ends every
 * session that logged in with the old one.
 */
final class AuthServiceProvider implements ServiceProvider
{
    /**
     * Optional commands, for ConsoleServiceProvider.
     *
     * @var list<class-string<\VeyroPHP\Console\Command>>
     */
    public const COMMANDS = [
        Console\ClearResetsCommand::class,
        Console\PruneRememberTokensCommand::class,
        Tokens\Console\CreateCommand::class,
        Tokens\Console\ListCommand::class,
        Tokens\Console\RevokeCommand::class,
        Tokens\Console\PruneCommand::class,
    ];

    public function register(Application $app): void
    {
        $container = $app->container();

        $container->singleton(PasswordHasher::class, static function (Container $c): PasswordHasher {
            $config = $c->make(Config::class);
            $algorithm = $config->get('auth.hash_algorithm', 'default');
            $options = $config->get('auth.hash_options', []);
            $intOptions = [];

            foreach (is_array($options) ? $options : [] as $key => $value) {
                if (is_string($key) && is_int($value)) {
                    $intOptions[$key] = $value;
                }
            }

            return new PasswordHasher(is_string($algorithm) ? $algorithm : 'default', $intOptions);
        });

        $container->singleton(AuthConfig::class, static function (Container $c): AuthConfig {
            $config = $c->make(Config::class);
            $model = $config->string('auth.model');

            if (!is_subclass_of($model, Model::class)) {
                throw new \InvalidArgumentException(sprintf('auth.model "%s" must extend %s.', $model, Model::class));
            }

            return new AuthConfig(
                $model,
                $config->has('auth.identifier') ? $config->string('auth.identifier') : 'email',
                $config->has('auth.password_column') ? $config->string('auth.password_column') : 'password',
                $config->has('auth.login_path') ? $config->string('auth.login_path') : '/login',
            );
        });

        $container->singleton(PasswordBroker::class, static function (Container $c): PasswordBroker {
            $config = $c->make(Config::class);

            return new PasswordBroker(
                $c->make(Connection::class),
                $c->make(Orm::class),
                $c->make(AuthConfig::class),
                $c->make(PasswordHasher::class),
                static function (Model $user, string $email, string $token) use ($c, $config): void {
                    $path = $c->make(Router::class)->path(self::string($config, 'auth.passwords.route', 'password.reset'), ['token' => $token, 'email' => $email]);
                    self::deliver($c, $config, self::mailClass($config, 'auth.passwords.mail', ResetPasswordMail::class), $email, self::url($config, $path), self::int($config, 'auth.passwords.expire', 60));
                },
                self::string($config, 'auth.passwords.table', 'password_reset_tokens'),
                self::string($config, 'auth.email_column', 'email'),
                self::int($config, 'auth.passwords.expire', 60),
                self::int($config, 'auth.passwords.throttle', 60),
                $c->bound(EventDispatcherInterface::class) ? $c->make(EventDispatcherInterface::class) : null,
                null,
                $config->has('auth.passwords.revoke_tokens') && !$config->bool('auth.passwords.revoke_tokens')
                    ? null
                    : static function (Model $user) use ($c, $config): void {
                        // Only where API tokens are in use: without the table there is nothing to revoke.
                        if ($c->make(Schema::class)->hasTable(self::string($config, 'auth.tokens.table', 'personal_access_tokens'))) {
                            $c->make(ApiTokens::class)->revokeAll($user);
                        }
                    },
            );
        });

        $container->singleton(EmailVerifier::class, static function (Container $c): EmailVerifier {
            $config = $c->make(Config::class);
            $expire = self::int($config, 'auth.verification.expire', 60);

            return new EmailVerifier(
                $c->make(UrlSigner::class),
                $c->make(Orm::class),
                static fn(string $id, string $hash): string => $c->make(Router::class)->path(self::string($config, 'auth.verification.route', 'verification.verify'), ['id' => $id, 'hash' => $hash]),
                static function (Model $user, string $email, string $path) use ($c, $config, $expire): void {
                    self::deliver($c, $config, self::mailClass($config, 'auth.verification.mail', VerifyEmailMail::class), $email, self::url($config, $path), $expire);
                },
                self::string($config, 'auth.email_column', 'email'),
                'email_verified_at',
                $expire,
                $c->bound(EventDispatcherInterface::class) ? $c->make(EventDispatcherInterface::class) : null,
            );
        });

        $container->singleton(ApiTokens::class, static fn(Container $c): ApiTokens => new ApiTokens(
            $c->make(Connection::class),
            self::string($c->make(Config::class), 'auth.tokens.table', 'personal_access_tokens'),
        ));

        $container->bind(EnsureEmailIsVerified::class, static fn(Container $c): EnsureEmailIsVerified => new EnsureEmailIsVerified(
            $c->make(Auth::class),
            $c->make(EmailVerifier::class),
            $c->make(ResponseFactory::class),
            self::string($c->make(Config::class), 'auth.verification.notice_path', '/email/verify'),
        ));

        $container->scoped(Auth::class, static fn(Container $c): Auth => new Auth(
            $c->make(Orm::class),
            $c->make(Session::class),
            $c->make(PasswordHasher::class),
            $c->make(AuthConfig::class),
            $c->bound(EventDispatcherInterface::class) ? $c->make(EventDispatcherInterface::class) : null,
            self::fingerprintKeys($c->make(Config::class)),
            $c->make(RememberTokens::class),
        ));

        // "Remember me": the table is only read when a remember cookie arrives or is issued.
        $container->scoped(RememberTokens::class, static function (Container $c): RememberTokens {
            $config = $c->make(Config::class);
            $session = $c->bound(SessionConfig::class) ? $c->make(SessionConfig::class) : new SessionConfig();

            return new RememberTokens(
                $c->make(Connection::class),
                OptionalSettings::string($config, 'auth.remember.table', RememberSchema::TABLE),
                OptionalSettings::int($config, 'auth.remember.lifetime_days', 30, 1),
                OptionalSettings::string($config, 'auth.remember.cookie', RememberTokens::COOKIE),
                $session->secure,
                $session->path,
                $session->domain,
            );
        });

        $container->bind(RememberMe::class, static fn(Container $c): RememberMe => new RememberMe(
            $c->make(RememberTokens::class),
            $c->bound(HttpErrorResponder::class) ? $c->make(HttpErrorResponder::class) : null,
        ));

        $container->singleton(LoginThrottle::class, static function (Container $c): LoginThrottle {
            $config = $c->make(Config::class);

            return new LoginThrottle(
                $c->make(RateLimiter::class),
                OptionalSettings::int($config, 'security.login.max_attempts', 5, 1),
                OptionalSettings::int($config, 'security.login.decay_seconds', 60, 1),
                OptionalSettings::int($config, 'security.login.account_max_attempts', 20, 1),
                OptionalSettings::int($config, 'security.login.account_decay_seconds', 900, 1),
                OptionalSettings::int($config, 'security.throttle.ipv6_prefix', 64, 1),
            );
        });

        // Authorization: rules once per application, checks per request for the logged-in user.
        $container->singleton(AccessRules::class, static fn(): AccessRules => new AccessRules());
        $container->scoped(Gate::class, static fn(Container $c): Gate => new Gate(
            $c->make(AccessRules::class),
            $c,
            static fn(): ?Model => $c->make(Auth::class)->user(),
            $c->bound(Rbac::class) ? $c->make(Rbac::class) : null,
        ));
    }

    /**
     * Keys for the session's password fingerprint: HKDF of APP_KEY and its previous keys
     * (purpose "auth-session"), current first. Empty without APP_KEY.
     *
     * @return list<string>
     */
    private static function fingerprintKeys(Config $config): array
    {
        [$key, $previous] = AppKeys::fromConfig($config);

        if ($key === '') {
            return [];
        }

        return array_map(
            static fn(string $key): string => hash_hkdf('sha256', Encrypter::parseKey($key), 32, 'veyrophp:auth-session'),
            [$key, ...$previous],
        );
    }

    /**
     * @param class-string<Mailable> $class
     */
    private static function deliver(Container $c, Config $config, string $class, string $email, string $url, int $minutes): void
    {
        $name = $config->get('app.name', 'VeyroPHP');
        $mail = new $class($email, $url, is_string($name) ? $name : 'VeyroPHP', $minutes);
        $mailer = $c->make(Mailer::class);

        if ($config->get('auth.queue_mail', true) !== false && $c->bound(Queue::class)) {
            $mailer->queue($mail);
        } else {
            $mailer->send($mail);
        }
    }

    private static function url(Config $config, string $path): string
    {
        $base = $config->get('app.url');

        if (!is_string($base) || preg_match('#^https?://[^\s/]+#', $base) !== 1) {
            throw new \InvalidArgumentException('Links in mails need the application URL: set APP_URL (config app.url), for example https://example.com.');
        }

        return rtrim($base, '/') . $path;
    }

    /**
     * @param class-string<Mailable> $default
     *
     * @return class-string<Mailable>
     */
    private static function mailClass(Config $config, string $key, string $default): string
    {
        $class = $config->get($key, $default);

        if (!is_string($class) || !is_subclass_of($class, Mailable::class)) {
            throw new \InvalidArgumentException(sprintf('config %s must be a Mailable class.', $key));
        }

        return $class;
    }

    private static function string(Config $config, string $key, string $default): string
    {
        $value = $config->get($key, $default);

        return is_string($value) && $value !== '' ? $value : $default;
    }

    private static function int(Config $config, string $key, int $default): int
    {
        $value = $config->get($key, $default);

        return is_int($value) && $value > 0 ? $value : $default;
    }
}
