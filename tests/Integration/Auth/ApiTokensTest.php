<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use VeyroPHP\Application\Application;
use VeyroPHP\Auth\Auth;
use VeyroPHP\Auth\Authenticate;
use VeyroPHP\Auth\AuthServiceProvider;
use VeyroPHP\Auth\Tokens\AccessToken;
use VeyroPHP\Auth\Tokens\ApiTokens;
use VeyroPHP\Auth\Tokens\ApiTokenSchema;
use VeyroPHP\Auth\Tokens\AuthenticateToken;
use VeyroPHP\Auth\Tokens\Console\CreateCommand;
use VeyroPHP\Auth\Tokens\Console\ListCommand;
use VeyroPHP\Auth\Tokens\Console\PruneCommand;
use VeyroPHP\Auth\Tokens\Console\RevokeCommand;
use VeyroPHP\Auth\Tokens\NewAccessToken;
use VeyroPHP\Auth\Tokens\RequireAbility;
use VeyroPHP\Auth\Tokens\TokenException;
use VeyroPHP\Cache\CacheServiceProvider;
use VeyroPHP\Config\Config;
use VeyroPHP\Console\Console;
use VeyroPHP\Console\ConsoleServiceProvider;
use VeyroPHP\Console\Output;
use VeyroPHP\Database\Connection\Connection;
use VeyroPHP\Database\DatabaseServiceProvider;
use VeyroPHP\Database\Schema\Blueprint;
use VeyroPHP\Database\Schema\Schema;
use VeyroPHP\Http\HttpKernel;
use VeyroPHP\Http\HttpServiceProvider;
use VeyroPHP\Orm\Orm;
use VeyroPHP\Orm\OrmServiceProvider;
use VeyroPHP\Routing\Router;
use VeyroPHP\Security\PasswordHasher;
use VeyroPHP\Session\SessionServiceProvider;
use VeyroPHP\Session\StartSession;
use VeyroPHP\Session\VerifyCsrfToken;
use VeyroPHP\Testing\TestClient;
use VeyroPHP\Tests\Fixtures\Auth\AuthUser;

#[CoversClass(ApiTokens::class)]
#[CoversClass(AccessToken::class)]
#[CoversClass(NewAccessToken::class)]
#[CoversClass(ApiTokenSchema::class)]
#[CoversClass(AuthenticateToken::class)]
#[CoversClass(RequireAbility::class)]
#[CoversClass(TokenException::class)]
#[CoversClass(CreateCommand::class)]
#[CoversClass(ListCommand::class)]
#[CoversClass(RevokeCommand::class)]
#[CoversClass(PruneCommand::class)]
final class ApiTokensTest extends TestCase
{
    private Application $app;

    private TestClient $client;

    private int $now = 1_793_000_000;

    protected function setUp(): void
    {
        $this->app = new Application(sys_get_temp_dir());
        $this->app->container()->instance(Config::class, new Config([
            'database' => ['default' => 'memory', 'connections' => ['memory' => ['driver' => 'sqlite', 'database' => ':memory:']]],
            'cache' => ['default' => 'array', 'prefix' => 'test', 'stores' => ['array' => ['driver' => 'array']]],
            'session' => ['csrf_except' => ['/api/*']],
            'auth' => ['model' => AuthUser::class, 'hash_algorithm' => 'bcrypt', 'hash_options' => ['cost' => 4]],
        ]));
        foreach ([
            new DatabaseServiceProvider(), new CacheServiceProvider(), new SessionServiceProvider(),
            new HttpServiceProvider([], [StartSession::class, VerifyCsrfToken::class]), new OrmServiceProvider(),
            new AuthServiceProvider(), new ConsoleServiceProvider(AuthServiceProvider::COMMANDS),
        ] as $provider) {
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
        ApiTokenSchema::up($schema);

        // A clock the test controls.
        $this->app->container()->instance(ApiTokens::class, new ApiTokens($this->app->container()->make(Connection::class), clock: fn(): int => $this->now));

        $router = $this->app->container()->make(Router::class);
        $router->get('/api/me', static fn(Auth $auth): array => ['id' => $auth->id()])->middleware(AuthenticateToken::class);
        $router->post('/api/orders', static fn(): array => ['created' => true])
            ->middleware(AuthenticateToken::class)->middleware(RequireAbility::for('orders.write'));
        $router->get('/dashboard', static fn(): string => 'dashboard')->middleware(Authenticate::class);

        $this->client = new TestClient($this->app->container()->make(HttpKernel::class));
    }

    private function tokens(): ApiTokens
    {
        return $this->app->container()->make(ApiTokens::class);
    }

    private function user(string $email): AuthUser
    {
        $user = (new AuthUser())->fill(['name' => 'U', 'email' => $email]);
        $user->set('password', $this->app->container()->make(PasswordHasher::class)->hash('secret-pass'));
        $this->app->container()->make(Orm::class)->save($user);

        return $user;
    }

    /**
     * @return array<string, string>
     */
    private static function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'];
    }

    public function testCreateFindAndStoredHash(): void
    {
        $user = $this->user('asha@example.com');

        $new = $this->tokens()->create($user, 'Mobile app', ['orders.read'], $this->now + 3600);

        self::assertMatchesRegularExpression('/^vyr_' . $new->token->id . '_[A-Za-z0-9_-]{43}$/', $new->plainText);
        $row = $this->app->container()->make(Connection::class)->table('personal_access_tokens')->first();
        self::assertNotNull($row);
        self::assertSame(hash('sha256', explode('_', $new->plainText, 3)[2]), $row['token_hash']);
        self::assertStringNotContainsString(substr($new->plainText, -20), (string) json_encode($row), 'the secret is not stored');

        $found = $this->tokens()->find($new->plainText);
        self::assertNotNull($found);
        self::assertSame(['Mobile app', (string) $user->getKey(), ['orders.read']], [$found->name, $found->userId, $found->abilities]);
        self::assertTrue($found->can('orders.read'));
        self::assertFalse($found->can('orders.write'));
    }

    public function testWrongMalformedExpiredAndRevokedTokensAreRejected(): void
    {
        $user = $this->user('asha@example.com');
        $new = $this->tokens()->create($user, 'App', expiresAt: $this->now + 60);
        $other = $this->tokens()->create($user, 'Other');
        [, $id, $secret] = explode('_', $new->plainText, 3);

        foreach ([
            'vyr_' . $id . '_' . strrev($secret),             // wrong secret
            'vyr_' . $other->token->id . '_' . $secret,       // secret of another token
            'vyr_999_' . $secret,                             // unknown id
            $secret,                                          // no prefix
            '',
            'vyr_' . $id . '_' . $secret . 'x',               // too long
            $new->plainText . "\n",                           // trailing newline
        ] as $wrong) {
            self::assertNull($this->tokens()->find($wrong), $wrong);
        }

        $this->now += 60;
        self::assertNull($this->tokens()->find($new->plainText), 'expired');
        self::assertSame(1, $this->tokens()->pruneExpired());

        self::assertTrue($this->tokens()->revoke($user, $other->token->id));
        self::assertNull($this->tokens()->find($other->plainText), 'revoked');
    }

    public function testUsersOnlyManageTheirOwnTokens(): void
    {
        $asha = $this->user('asha@example.com');
        $ravi = $this->user('ravi@example.com');
        $ashas = $this->tokens()->create($asha, 'A1');
        $this->tokens()->create($asha, 'A2');
        $this->tokens()->create($ravi, 'R1');

        self::assertFalse($this->tokens()->revoke($ravi, $ashas->token->id), "Ravi cannot revoke Asha's token");
        self::assertSame(['A2', 'A1'], array_map(static fn(AccessToken $t): string => $t->name, $this->tokens()->tokensFor($asha)));
        self::assertSame(2, $this->tokens()->revokeAll($asha));
        self::assertCount(1, $this->tokens()->tokensFor($ravi));
    }

    public function testLastUsedIsWrittenAtMostOncePerMinute(): void
    {
        $new = $this->tokens()->create($this->user('asha@example.com'), 'App');
        $lastUsed = function (): int {
            $value = $this->app->container()->make(Connection::class)->table('personal_access_tokens')->value('last_used_at');

            return is_numeric($value) ? (int) $value : -1;
        };

        $this->tokens()->find($new->plainText);
        self::assertSame($this->now, $lastUsed());

        $this->now += 30;
        $this->tokens()->find($new->plainText);
        self::assertSame($this->now - 30, $lastUsed(), 'not written again within a minute');

        $this->now += 30;
        $this->tokens()->find($new->plainText);
        self::assertSame($this->now, $lastUsed());
    }

    public function testInvalidTokenSettings(): void
    {
        $user = $this->user('asha@example.com');

        foreach ([
            static fn(ApiTokens $t) => $t->create($user, ''),
            static fn(ApiTokens $t) => $t->create($user, "a\nb"),
            static fn(ApiTokens $t) => $t->create($user, 'x', ['orders read']),
            static fn(ApiTokens $t) => $t->create($user, 'x', [42]),
            fn(ApiTokens $t) => $t->create($user, 'x', ['*'], $this->now),
            static fn(ApiTokens $t) => $t->create(new AuthUser(), 'x'),
        ] as $i => $call) {
            try {
                $call($this->tokens());
                self::fail('expected TokenException ' . $i);
            } catch (TokenException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // HTTP

    public function testBearerAuthenticationAndAbilities(): void
    {
        $user = $this->user('asha@example.com');
        $reader = $this->tokens()->create($user, 'Reader', ['orders.read']);
        $writer = $this->tokens()->create($user, 'Writer', ['orders.read', 'orders.write']);
        $all = $this->tokens()->create($user, 'Everything');

        $response = $this->client->get('/api/me', ['Accept' => 'application/json']);
        self::assertSame(401, $response->getStatusCode());
        self::assertSame('Bearer', $response->getHeaderLine('WWW-Authenticate'));

        self::assertSame(401, $this->client->get('/api/me', self::bearer('vyr_1_' . str_repeat('a', 43)))->getStatusCode());

        $response = $this->client->get('/api/me', self::bearer($reader->plainText));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['id' => $user->getKey()], json_decode((string) $response->getBody(), true));

        // POST without a CSRF token works for /api (excepted); abilities decide.
        self::assertSame(403, $this->client->request('POST', '/api/orders', [], self::bearer($reader->plainText))->getStatusCode());
        self::assertSame(200, $this->client->request('POST', '/api/orders', [], self::bearer($writer->plainText))->getStatusCode());
        self::assertSame(200, $this->client->request('POST', '/api/orders', [], self::bearer($all->plainText))->getStatusCode());
    }

    public function testTokensDoNotCreateSessionsOrUnlockSessionRoutes(): void
    {
        $new = $this->tokens()->create($this->user('asha@example.com'), 'App');

        self::assertSame(200, $this->client->get('/api/me', self::bearer($new->plainText))->getStatusCode());
        self::assertSame(401, $this->client->get('/api/me', ['Accept' => 'application/json'])->getStatusCode(), 'nothing remembered without the header');
        self::assertSame(302, $this->client->get('/dashboard', ['Authorization' => 'Bearer ' . $new->plainText])->getStatusCode(), 'session pages ignore tokens (redirect to login)');
    }

    public function testDeletedUserIsRejected(): void
    {
        $user = $this->user('asha@example.com');
        $new = $this->tokens()->create($user, 'App');
        // A raw delete, as if the foreign key had no cascade.
        $this->app->container()->make(Connection::class)->statement('PRAGMA foreign_keys = OFF');
        $this->app->container()->make(Connection::class)->table('users')->where('id', $user->getKey())->delete();

        self::assertSame(401, $this->client->get('/api/me', self::bearer($new->plainText))->getStatusCode());
    }

    // Commands

    /**
     * @param list<string> $arguments
     *
     * @return array{int, string, string}
     */
    private function exec(array $arguments): array
    {
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        self::assertIsResource($out);
        self::assertIsResource($err);

        $code = $this->app->container()->make(Console::class)->run($arguments, new Output($out, $err));
        rewind($out);
        rewind($err);

        return [$code, (string) stream_get_contents($out), (string) stream_get_contents($err)];
    }

    public function testCommands(): void
    {
        $this->user('asha@example.com');

        [$code, $out] = $this->exec(['token:create', 'asha@example.com', 'CI deploy', '--abilities=deploy,status.read', '--days=30']);
        self::assertSame(0, $code);
        if (preg_match('/(vyr_\d+_[A-Za-z0-9_-]{43})/', $out, $m) !== 1) {
            self::fail('no token in: ' . $out);
        }
        $plain = $m[1];
        $token = $this->tokens()->find($plain);
        self::assertNotNull($token);
        self::assertSame(['deploy', 'status.read'], $token->abilities);
        // The command uses the real clock.
        self::assertEqualsWithDelta(time() + 30 * 86400, $token->expiresAt, 5);

        [$code, $out] = $this->exec(['token:list', 'asha@example.com']);
        self::assertSame(0, $code);
        self::assertStringContainsString('CI deploy', $out);
        self::assertStringNotContainsString($plain, $out, 'secrets are never listed');

        self::assertSame(2, $this->exec(['token:create', 'nobody@example.com', 'x'])[0]);
        self::assertSame(2, $this->exec(['token:create', 'asha@example.com', 'x', '--abilities=bad ability'])[0]);
        self::assertSame(1, $this->exec(['token:revoke', 'asha@example.com', '999'])[0]);
        self::assertSame(0, $this->exec(['token:revoke', 'asha@example.com', (string) $token->id])[0]);
        self::assertSame(0, $this->exec(['token:revoke', 'asha@example.com', 'all'])[0]);
        self::assertStringContainsString('Deleted 0 expired tokens.', $this->exec(['token:prune'])[1]);
    }
}
