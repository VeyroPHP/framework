<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use VeyroPHP\Application\Application;
use VeyroPHP\Auth\Auth;
use VeyroPHP\Auth\Authenticate;
use VeyroPHP\Auth\AuthServiceProvider;
use VeyroPHP\Auth\Console\ClearResetsCommand;
use VeyroPHP\Auth\Events\PasswordReset;
use VeyroPHP\Auth\Events\Verified;
use VeyroPHP\Auth\Mail\ResetPasswordMail;
use VeyroPHP\Auth\Mail\VerifyEmailMail;
use VeyroPHP\Auth\Passwords\PasswordBroker;
use VeyroPHP\Auth\Passwords\PasswordResetSchema;
use VeyroPHP\Auth\Passwords\ResetStatus;
use VeyroPHP\Auth\Verification\EmailVerifier;
use VeyroPHP\Auth\Verification\EnsureEmailIsVerified;
use VeyroPHP\Cache\CacheServiceProvider;
use VeyroPHP\Config\Config;
use VeyroPHP\Database\Connection\Connection;
use VeyroPHP\Database\DatabaseServiceProvider;
use VeyroPHP\Database\Schema\Blueprint;
use VeyroPHP\Database\Schema\Schema;
use VeyroPHP\Encryption\Encrypter;
use VeyroPHP\Encryption\EncryptionServiceProvider;
use VeyroPHP\Events\EventDispatcher;
use VeyroPHP\Events\EventServiceProvider;
use VeyroPHP\Http\Exception\HttpException;
use VeyroPHP\Http\HttpKernel;
use VeyroPHP\Http\HttpServiceProvider;
use VeyroPHP\Mail\Mailer;
use VeyroPHP\Mail\MailServiceProvider;
use VeyroPHP\Mail\SentMessage;
use VeyroPHP\Mail\Transport\ArrayTransport;
use VeyroPHP\Orm\Orm;
use VeyroPHP\Orm\OrmServiceProvider;
use VeyroPHP\Routing\Router;
use VeyroPHP\Security\PasswordHasher;
use VeyroPHP\Security\ValidateSignature;
use VeyroPHP\Session\SessionServiceProvider;
use VeyroPHP\Session\StartSession;
use VeyroPHP\Testing\TestClient;
use VeyroPHP\Tests\Fixtures\Auth\AuthUser;

#[CoversClass(PasswordBroker::class)]
#[CoversClass(PasswordResetSchema::class)]
#[CoversClass(ResetStatus::class)]
#[CoversClass(EmailVerifier::class)]
#[CoversClass(EnsureEmailIsVerified::class)]
#[CoversClass(ResetPasswordMail::class)]
#[CoversClass(VerifyEmailMail::class)]
#[CoversClass(ClearResetsCommand::class)]
#[CoversClass(PasswordReset::class)]
#[CoversClass(Verified::class)]
final class PasswordResetAndVerificationTest extends TestCase
{
    private Application $app;

    private TestClient $client;

    /** @var list<object> */
    private array $events = [];

    protected function setUp(): void
    {
        $this->app = new Application(sys_get_temp_dir());
        $this->app->container()->instance(Config::class, new Config([
            'app' => ['name' => 'Shop', 'url' => 'https://shop.example/', 'key' => Encrypter::generateKey()],
            'database' => ['default' => 'memory', 'connections' => ['memory' => ['driver' => 'sqlite', 'database' => ':memory:']]],
            'cache' => ['default' => 'array', 'prefix' => 'test', 'stores' => ['array' => ['driver' => 'array']]],
            'auth' => ['model' => AuthUser::class, 'hash_algorithm' => 'bcrypt', 'hash_options' => ['cost' => 4]],
            'mail' => ['default' => 'array', 'from' => ['address' => 'shop@example.com'], 'mailers' => ['array' => ['transport' => 'array']]],
        ]));
        foreach ([
            new DatabaseServiceProvider(), new CacheServiceProvider(), new SessionServiceProvider(),
            new HttpServiceProvider([], [StartSession::class]), new OrmServiceProvider(), new EventServiceProvider(),
            new EncryptionServiceProvider(), new MailServiceProvider(), new AuthServiceProvider(),
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
            $t->dateTime('email_verified_at')->nullable();
            $t->timestamps();
        });
        PasswordResetSchema::up($schema);

        $events = $this->app->container()->make(EventDispatcher::class);
        $events->listen(PasswordReset::class, fn(PasswordReset $e) => $this->events[] = $e);
        $events->listen(Verified::class, fn(Verified $e) => $this->events[] = $e);

        $router = $this->app->container()->make(Router::class);
        $router->get('/reset-password/{token}', static fn(): string => 'reset form')->name('password.reset');
        $router->post('/login', static function (ServerRequestInterface $r, Auth $auth): string {
            $body = (array) $r->getParsedBody();

            $email = $body['email'] ?? '';
            $password = $body['password'] ?? '';

            return is_string($email) && is_string($password) && $auth->attempt($email, $password) ? 'in' : 'out';
        });
        $router->get('/email/verify/{id}/{hash}', static function (string $id, string $hash, Auth $auth, EmailVerifier $verifier): string {
            $user = $auth->user() ?? throw new HttpException(401);

            return $verifier->verify($user, $id, $hash) ? 'verified' : throw new HttpException(403, 'Wrong user.');
        })->name('verification.verify')->middleware(Authenticate::class)->middleware(ValidateSignature::class);
        $router->get('/members', static fn(): string => 'members only')->middleware(Authenticate::class)->middleware(EnsureEmailIsVerified::class);

        $this->client = new TestClient($this->app->container()->make(HttpKernel::class));
    }

    private function user(string $email, string $password = 'old-password'): AuthUser
    {
        $user = (new AuthUser())->fill(['name' => 'U', 'email' => $email]);
        $user->set('password', $this->app->container()->make(PasswordHasher::class)->hash($password));
        $this->app->container()->make(Orm::class)->save($user);

        return $user;
    }

    private function broker(): PasswordBroker
    {
        return $this->app->container()->make(PasswordBroker::class);
    }

    /**
     * @return list<SentMessage>
     */
    private function mails(): array
    {
        $transport = $this->app->container()->make(Mailer::class)->transport();
        self::assertInstanceOf(ArrayTransport::class, $transport);

        return $transport->messages();
    }

    private static function linkIn(SentMessage $mail): string
    {
        return self::capture('#https://shop\.example(/\S+)#', (string) $mail->message->getText());
    }

    private static function capture(string $pattern, string $subject): string
    {
        if (preg_match($pattern, $subject, $m) !== 1 || !isset($m[1])) {
            self::fail(sprintf('"%s" does not match %s', $subject, $pattern));
        }

        return $m[1];
    }

    private function db(): Connection
    {
        return $this->app->container()->make(Connection::class);
    }

    // Password reset

    public function testResetLinkMailAndStoredHash(): void
    {
        $this->user('asha@example.com');

        self::assertSame(ResetStatus::LinkSent, $this->broker()->sendResetLink('asha@example.com'));

        $mails = $this->mails();
        self::assertCount(1, $mails);
        self::assertSame(['asha@example.com'], $mails[0]->recipients);
        self::assertSame('Reset your Shop password', $mails[0]->message->getSubject());
        $link = self::linkIn($mails[0]);
        $token = self::capture('#^/reset-password/([A-Za-z0-9_-]{43})\?email=asha%40example\.com$#', $link);
        self::assertStringContainsString('href="https://shop.example/reset-password/' . $token . '?email=asha%40example.com"', (string) $mails[0]->message->getHtml());

        $row = $this->db()->table('password_reset_tokens')->first();
        self::assertNotNull($row);
        self::assertSame(hash('sha256', $token), $row['token_hash'], 'only the hash is stored');
        self::assertStringNotContainsString($token, (string) json_encode($row));

        self::assertSame('reset form', (string) $this->client->get($link)->getBody());
    }

    public function testResetChangesPasswordOnceAndDispatchesEvent(): void
    {
        $user = $this->user('asha@example.com');
        $this->broker()->sendResetLink('asha@example.com');
        $token = explode('?', explode('/reset-password/', self::linkIn($this->mails()[0]))[1])[0];

        self::assertTrue($this->broker()->tokenIsValid('asha@example.com', $token));
        self::assertSame(ResetStatus::PasswordReset, $this->broker()->reset('asha@example.com', $token, 'new-password-123'));
        self::assertSame(ResetStatus::InvalidToken, $this->broker()->reset('asha@example.com', $token, 'again-123'), 'works only once');

        self::assertSame('out', (string) $this->client->post('/login', ['email' => 'asha@example.com', 'password' => 'old-password'])->getBody());
        self::assertSame('in', (string) $this->client->post('/login', ['email' => 'asha@example.com', 'password' => 'new-password-123'])->getBody());
        self::assertCount(1, $this->events);
        self::assertInstanceOf(PasswordReset::class, $this->events[0]);
        self::assertSame($user->getKey(), $this->events[0]->user->getKey());
        self::assertSame(0, $this->db()->table('password_reset_tokens')->count());
    }

    public function testWrongExpiredOtherUserAndUnknownTokensAreRejected(): void
    {
        $clock = 1_793_000_000;
        $c = $this->app->container();
        $sent = [];
        $broker = new PasswordBroker(
            $c->make(Connection::class),
            $c->make(Orm::class),
            $c->make(\VeyroPHP\Auth\AuthConfig::class),
            $c->make(PasswordHasher::class),
            static function ($user, string $email, string $token) use (&$sent): void {
                $sent[$email] = $token;
            },
            clock: static function () use (&$clock): int {
                return $clock;
            },
        );
        $this->user('asha@example.com');
        $this->user('ravi@example.com');
        $broker->sendResetLink('asha@example.com');
        $broker->sendResetLink('ravi@example.com');

        self::assertSame(ResetStatus::InvalidToken, $broker->reset('asha@example.com', $sent['ravi@example.com'], 'x-123456'), "Ravi's token does not work for Asha");
        self::assertSame(ResetStatus::InvalidToken, $broker->reset('asha@example.com', 'guess', 'x-123456'));
        self::assertSame(ResetStatus::InvalidToken, $broker->reset('asha@example.com', '', 'x-123456'));
        self::assertSame(ResetStatus::InvalidToken, $broker->reset('nobody@example.com', $sent['asha@example.com'], 'x-123456'));

        $clock += 3601;
        self::assertSame(ResetStatus::InvalidToken, $broker->reset('asha@example.com', $sent['asha@example.com'], 'x-123456'), 'expired after 60 minutes');
        self::assertSame(2, $broker->deleteExpired());
    }

    public function testThrottleAndUnknownUsers(): void
    {
        $this->user('asha@example.com');

        self::assertSame(ResetStatus::UserNotFound, $this->broker()->sendResetLink('nobody@example.com'));
        self::assertSame(ResetStatus::UserNotFound, $this->broker()->sendResetLink('   '));
        self::assertSame(ResetStatus::LinkSent, $this->broker()->sendResetLink('asha@example.com'));
        self::assertSame(ResetStatus::Throttled, $this->broker()->sendResetLink('asha@example.com'), 'one link per minute');
        self::assertCount(1, $this->mails(), 'no mail for unknown or throttled requests');
    }

    public function testMissingAppUrlGivesAClearError(): void
    {
        $config = $this->app->container()->make(Config::class)->all();
        $app = $config['app'] ?? [];
        self::assertIsArray($app);
        unset($app['url']);
        $config['app'] = $app;
        $this->app->container()->instance(Config::class, new Config($config));
        $this->user('asha@example.com');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('set APP_URL');
        $this->broker()->sendResetLink('asha@example.com');
    }

    // Email verification

    private function loginAs(string $email): void
    {
        self::assertSame('in', (string) $this->client->post('/login', ['email' => $email, 'password' => 'old-password'])->getBody());
    }

    public function testVerificationLinkVerifiesTheLoggedInUser(): void
    {
        $user = $this->user('asha@example.com');
        $verifier = $this->app->container()->make(EmailVerifier::class);

        self::assertFalse($verifier->isVerified($user));
        self::assertTrue($verifier->send($user));
        $mail = $this->mails()[0];
        self::assertSame('Confirm your email address for Shop', $mail->message->getSubject());
        $link = self::linkIn($mail);
        self::assertSame(1, preg_match('#^/email/verify/' . $user->getKey() . '/[0-9a-f]{64}\?expires=\d+&signature=[A-Za-z0-9_-]+$#', $link), $link);

        $this->loginAs('asha@example.com');
        self::assertSame(302, $this->client->get('/members')->getStatusCode(), 'unverified users are sent to the notice page');
        $forbidden = $this->client->get('/members', ['Accept' => 'application/json']);
        self::assertSame(403, $forbidden->getStatusCode());
        self::assertStringContainsString('Verify your email address before opening this page.', (string) $forbidden->getBody());

        $response = $this->client->get($link);
        self::assertSame('verified', (string) $response->getBody());
        self::assertSame('members only', (string) $this->client->get('/members')->getBody());

        $fresh = $this->app->container()->make(Orm::class)->find(AuthUser::class, (int) $user->getKey());
        self::assertNotNull($fresh);
        self::assertTrue($verifier->isVerified($fresh));
        self::assertCount(1, $this->events);
        self::assertFalse($verifier->send($fresh), 'no mail for verified users');
    }

    public function testVerificationLinkRejectsTamperingOtherUsersAndChangedEmail(): void
    {
        $asha = $this->user('asha@example.com');
        $this->user('ravi@example.com');
        $verifier = $this->app->container()->make(EmailVerifier::class);
        $verifier->send($asha);
        $link = self::linkIn($this->mails()[0]);

        self::assertSame(302, $this->client->get($link)->getStatusCode(), 'guests must log in first');

        $this->loginAs('ravi@example.com');
        self::assertSame(403, $this->client->get($link)->getStatusCode(), "Ravi cannot use Asha's link");

        $this->client->clearCookies();
        $this->loginAs('asha@example.com');
        self::assertSame(403, $this->client->get(str_replace('/email/verify/' . $asha->getKey(), '/email/verify/999', $link))->getStatusCode(), 'changed id breaks the signature');
        self::assertSame(403, $this->client->get(preg_replace('/expires=\d+/', 'expires=9999999999', $link) ?? '')->getStatusCode(), 'longer expiry breaks the signature');

        // The address changes before the link is used.
        $this->db()->table('users')->where('id', $asha->getKey())->update(['email' => 'asha.new@example.com']);
        $this->client->clearCookies();
        $this->client->post('/login', ['email' => 'asha.new@example.com', 'password' => 'old-password']);
        self::assertSame(403, $this->client->get($link)->getStatusCode(), 'a link for the old address does not verify the new one');
        self::assertSame([], $this->events);
    }

    public function testExpiredVerificationLink(): void
    {
        $asha = $this->user('asha@example.com');
        $c = $this->app->container();
        $past = time() - 3601;
        $verifier = new EmailVerifier(
            $c->make(\VeyroPHP\Security\UrlSigner::class),
            $c->make(Orm::class),
            static fn(string $id, string $hash): string => '/email/verify/' . $id . '/' . $hash,
            static function (): void {},
            clock: static fn(): int => $past,
        );

        $this->loginAs('asha@example.com');
        self::assertSame(403, $this->client->get($verifier->signedPath($asha))->getStatusCode());
    }
}
