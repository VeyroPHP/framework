<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use VeyroPHP\Application\Application;
use VeyroPHP\Auth\Auth;
use VeyroPHP\Auth\AuthConfig;
use VeyroPHP\Auth\AuthServiceProvider;
use VeyroPHP\Auth\Passwords\PasswordBroker;
use VeyroPHP\Auth\Passwords\PasswordResetSchema;
use VeyroPHP\Auth\Tokens\ApiTokens;
use VeyroPHP\Auth\Tokens\ApiTokenSchema;
use VeyroPHP\Cache\CacheServiceProvider;
use VeyroPHP\Config\Config;
use VeyroPHP\Database\Connection\Connection;
use VeyroPHP\Database\DatabaseServiceProvider;
use VeyroPHP\Database\Schema\Blueprint;
use VeyroPHP\Database\Schema\Schema;
use VeyroPHP\Encryption\Encrypter;
use VeyroPHP\Orm\Orm;
use VeyroPHP\Orm\OrmServiceProvider;
use VeyroPHP\Security\PasswordHasher;
use VeyroPHP\Session\Session;
use VeyroPHP\Session\SessionServiceProvider;
use VeyroPHP\Tests\Fixtures\Auth\AuthUser;

/**
 * VQ-011: a password change ends every session that was logged in with the old
 * password, and a password reset revokes API tokens (configurable).
 */
#[CoversClass(Auth::class)]
#[CoversClass(PasswordBroker::class)]
#[CoversClass(AuthServiceProvider::class)]
final class SessionInvalidationTest extends TestCase
{
    private Application $app;

    private AuthUser $user;

    /**
     * @param array<string, mixed> $auth extra auth config
     * @param array<string, mixed> $app extra app config
     */
    private function boot(array $auth = [], array $app = [], bool $withTokens = true): void
    {
        $this->app = new Application(sys_get_temp_dir());
        $this->app->container()->instance(Config::class, new Config([
            'app' => $app + ['key' => 'base64:' . base64_encode(str_repeat('k', 32))],
            'database' => ['default' => 'memory', 'connections' => ['memory' => ['driver' => 'sqlite', 'database' => ':memory:']]],
            'cache' => ['default' => 'array', 'prefix' => 'test', 'stores' => ['array' => ['driver' => 'array']]],
            'auth' => $auth + ['model' => AuthUser::class, 'hash_algorithm' => 'bcrypt', 'hash_options' => ['cost' => 4]],
        ]));
        foreach ([new DatabaseServiceProvider(), new CacheServiceProvider(), new SessionServiceProvider(), new OrmServiceProvider(), new AuthServiceProvider()] as $provider) {
            $this->app->register($provider);
        }
        $this->app->boot();

        $schema = $this->app->container()->make(Schema::class);
        $schema->create('users', static function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->string('email')->unique();
            $t->string('password');
            $t->timestamps();
        });
        PasswordResetSchema::up($schema);
        if ($withTokens) {
            ApiTokenSchema::up($schema);
        }

        $this->user = (new AuthUser())->fill(['name' => 'asha', 'email' => 'asha@example.com']);
        $this->user->set('password', $this->app->container()->make(PasswordHasher::class)->hash('old-password'));
        $this->app->container()->make(Orm::class)->save($this->user);
    }

    /**
     * A new Auth for an existing session, as on the next request.
     *
     * @param list<string> $keys
     */
    private function auth(Session $session, array $keys = []): Auth
    {
        $c = $this->app->container();

        return $keys === []
            ? new Auth($c->make(Orm::class), $session, $c->make(PasswordHasher::class), $c->make(AuthConfig::class), null, $this->fingerprintKeys())
            : new Auth($c->make(Orm::class), $session, $c->make(PasswordHasher::class), $c->make(AuthConfig::class), null, $keys);
    }

    /**
     * @return list<string>
     */
    private function fingerprintKeys(): array
    {
        return [hash_hkdf('sha256', Encrypter::parseKey('base64:' . base64_encode(str_repeat('k', 32))), 32, 'veyrophp:auth-session')];
    }

    private function loggedInSession(): Session
    {
        $session = new Session(Session::generateId());
        self::assertTrue($this->auth($session)->attempt('asha@example.com', 'old-password'));

        return $session;
    }

    private function resetPassword(string $new): void
    {
        // A reset token stored the way PasswordBroker stores it (sha256 of the token, per user id),
        // so the broker built by the container (with its token revocation) handles the reset.
        $token = 'test-token-' . bin2hex(random_bytes(8));
        $this->app->container()->make(Connection::class)->table('password_reset_tokens')->insert([
            'identifier' => (string) $this->user->getKey(),
            'token_hash' => hash('sha256', $token),
            'created_at' => time(),
        ]);
        $broker = $this->app->container()->make(PasswordBroker::class);
        self::assertSame('PasswordReset', $broker->reset('asha@example.com', $token, $new)->name);
    }

    public function testAPasswordResetEndsEverySessionOfTheOldPassword(): void
    {
        $this->boot();
        $attacker = $this->loggedInSession();
        $laptop = $this->loggedInSession();

        self::assertNotNull($this->auth($attacker)->user());

        $this->resetPassword('brand-new-password');

        self::assertNull($this->auth($attacker)->user(), 'the attacker is logged out');
        self::assertNull($this->auth($laptop)->user(), 'every other device too');
        self::assertFalse($this->auth($attacker)->check(), 'and stays logged out');

        $fresh = new Session(Session::generateId());
        self::assertTrue($this->auth($fresh)->attempt('asha@example.com', 'brand-new-password'));
        self::assertNotNull($this->auth($fresh)->user(), 'a login with the new password works');
    }

    public function testAPasswordChangedByTheApplicationAlsoEndsSessions(): void
    {
        $this->boot();
        $session = $this->loggedInSession();

        $this->user->set('password', $this->app->container()->make(PasswordHasher::class)->hash('changed-in-profile'));
        $this->app->container()->make(Orm::class)->save($this->user);

        self::assertNull($this->auth($session)->user());
    }

    public function testTheSessionHoldsAFingerprintNotThePasswordHash(): void
    {
        $this->boot();
        $session = $this->loggedInSession();
        $hash = $this->user->raw('password');
        self::assertIsString($hash);

        $stored = json_encode($session->toStorage());
        self::assertIsString($stored);
        self::assertStringNotContainsString($hash, $stored);
        self::assertStringNotContainsString(substr($hash, 7), $stored, 'not even the salt and hash part');
    }

    public function testSessionsFromBeforeTheUpgradeAreLoggedOutOnce(): void
    {
        $this->boot();
        // A session written before fingerprints existed: only the user id.
        $old = new Session(Session::generateId(), ['_auth_user' => $this->user->getKey()], false);

        self::assertNull($this->auth($old)->user(), 'documented deploy-time logout');
    }

    public function testRotatedAppKeysKeepSessionsValid(): void
    {
        $this->boot();
        $session = $this->loggedInSession();
        $newKey = hash_hkdf('sha256', str_repeat('n', 32), 32, 'veyrophp:auth-session');

        self::assertNotNull($this->auth($session, [$newKey, ...$this->fingerprintKeys()])->user(), 'the old key still verifies');
        self::assertNull($this->auth($session, [$newKey])->user(), 'once the old key is removed, the session ends');
    }

    public function testAResetRevokesApiTokensByDefault(): void
    {
        $this->boot();
        $tokens = $this->app->container()->make(ApiTokens::class);
        $plain = $tokens->create($this->user, 'attacker')->plainText;

        $this->resetPassword('brand-new-password');

        self::assertNull($tokens->find($plain));
    }

    public function testTokenRevocationCanBeTurnedOff(): void
    {
        $this->boot(['passwords' => ['revoke_tokens' => false]]);
        $tokens = $this->app->container()->make(ApiTokens::class);
        $plain = $tokens->create($this->user, 'ci')->plainText;

        $this->resetPassword('brand-new-password');

        self::assertNotNull($tokens->find($plain));
    }

    public function testResetWorksWithoutATokensTable(): void
    {
        $this->boot(withTokens: false);
        $session = $this->loggedInSession();

        $this->resetPassword('brand-new-password');

        self::assertNull($this->auth($session)->user());
        self::assertFalse($this->app->container()->make(Schema::class)->hasTable('personal_access_tokens'));
        self::assertInstanceOf(Connection::class, $this->app->container()->make(Connection::class));
    }

    public function testTheContainerBuildsAuthWithTheDerivedKey(): void
    {
        $this->boot();
        $c = $this->app->container();
        $session = new Session(Session::generateId());
        $c->instance(Session::class, $session);
        self::assertTrue($c->make(Auth::class)->attempt('asha@example.com', 'old-password'));

        // The fingerprint written through the container verifies with the documented key derivation.
        self::assertNotNull($this->auth($session)->user());
    }
}
