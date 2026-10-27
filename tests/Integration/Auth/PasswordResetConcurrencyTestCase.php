<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Integration\Auth;

use Closure;
use PHPUnit\Framework\TestCase;
use VeyroPHP\Auth\AuthConfig;
use VeyroPHP\Auth\Events\PasswordReset;
use VeyroPHP\Auth\Passwords\PasswordBroker;
use VeyroPHP\Auth\Passwords\PasswordResetSchema;
use VeyroPHP\Auth\Passwords\ResetStatus;
use VeyroPHP\Container\Container;
use VeyroPHP\Database\Connection\Connection;
use VeyroPHP\Database\Connection\ConnectionConfig;
use VeyroPHP\Database\DatabaseManager;
use VeyroPHP\Database\Schema\Blueprint;
use VeyroPHP\Database\Schema\Schema;
use VeyroPHP\Events\EventDispatcher;
use VeyroPHP\Orm\Events\ModelSaving;
use VeyroPHP\Orm\Model;
use VeyroPHP\Orm\Orm;
use VeyroPHP\Security\PasswordHasher;
use VeyroPHP\Tests\Fixtures\Auth\AuthUser;

/**
 * SVC-4: a reset token is consumed once, even when two resets with the same token
 * overlap. The overlap is made deterministic: the first reset's clock, which it reads
 * after loading the token row, runs the second reset to completion.
 */
abstract class PasswordResetConcurrencyTestCase extends TestCase
{
    private Connection $db;

    private EventDispatcher $events;

    private Orm $orm;

    private PasswordHasher $hasher;

    private string $token = '';

    /** @var list<string> */
    private array $log = [];

    private bool $failSaves = false;

    /**
     * @return array<string, string>
     */
    abstract protected static function settings(): array;

    protected function setUp(): void
    {
        $this->db = (new DatabaseManager(['test' => ConnectionConfig::fromArray('test', static::settings())], 'test'))->connection();
        $schema = new Schema($this->db);
        $this->dropTables();
        $schema->create('users', static function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->string('email')->unique();
            $t->string('password');
            $t->timestamps();
        });
        PasswordResetSchema::up($schema);

        $this->events = new EventDispatcher(new Container());
        $this->events->listen(PasswordReset::class, fn() => $this->log[] = 'event');
        $this->orm = new Orm($this->db, events: $this->events);
        $this->hasher = new PasswordHasher('bcrypt', ['cost' => 4]);

        $user = (new AuthUser())->fill(['name' => 'Asha', 'email' => 'asha@example.com']);
        $user->set('password', $this->hasher->hash('old-password'));
        $this->orm->save($user);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->dropTables();
            $this->db->disconnect();
        }
    }

    private function dropTables(): void
    {
        $schema = new Schema($this->db);
        PasswordResetSchema::down($schema);
        $schema->dropIfExists('users');
    }

    /**
     * @param (Closure(): int)|null $clock
     */
    private function broker(?Closure $clock = null): PasswordBroker
    {
        return new PasswordBroker(
            $this->db,
            $this->orm,
            new AuthConfig(AuthUser::class),
            $this->hasher,
            function (Model $user, string $email, string $token): void {
                $this->token = $token;
            },
            events: $this->events,
            clock: $clock,
            afterReset: function (): void {
                $this->log[] = 'after reset';
            },
        );
    }

    private function storedHash(): string
    {
        $hash = $this->db->table('users')->where('email', 'asha@example.com')->value('password');
        self::assertIsString($hash);

        return $hash;
    }

    public function testATokenIsConsumedOnceWhenTwoResetsOverlap(): void
    {
        self::assertSame(ResetStatus::LinkSent, $this->broker()->sendResetLink('asha@example.com'));
        $token = $this->token;
        $second = $this->broker();
        $secondResult = null;
        $interleaved = false;

        $first = $this->broker(static function () use (&$interleaved, &$secondResult, $second, $token): int {
            // The first reset has read the token row and is about to check it.
            if (!$interleaved) {
                $interleaved = true;
                $secondResult = $second->reset('asha@example.com', $token, 'second-password');
            }

            return time();
        });

        $firstResult = $first->reset('asha@example.com', $token, 'first-password');

        self::assertTrue($interleaved, 'the second reset ran inside the first');
        self::assertSame(ResetStatus::PasswordReset, $secondResult);
        self::assertSame(ResetStatus::InvalidToken, $firstResult, 'the token was already used');
        self::assertTrue($this->hasher->verify('second-password', $this->storedHash()));
        self::assertSame(['after reset', 'event'], $this->log, 'side effects ran once');
        self::assertSame(0, $this->db->table('password_reset_tokens')->count());
    }

    public function testAFailedSaveDoesNotUseUpTheToken(): void
    {
        $this->broker()->sendResetLink('asha@example.com');
        $this->failSaves = true;
        $this->events->listen(ModelSaving::class, function (): void {
            if ($this->failSaves) {
                throw new \RuntimeException('database write failed');
            }
        });

        try {
            $this->broker()->reset('asha@example.com', $this->token, 'new-password');
            self::fail('the failed save was hidden');
        } catch (\RuntimeException) {
        }

        self::assertTrue($this->hasher->verify('old-password', $this->storedHash()));
        self::assertTrue($this->broker()->tokenIsValid('asha@example.com', $this->token), 'the token still works');

        $this->failSaves = false;
        self::assertSame(ResetStatus::PasswordReset, $this->broker()->reset('asha@example.com', $this->token, 'new-password'));
        self::assertTrue($this->hasher->verify('new-password', $this->storedHash()));
        self::assertSame(ResetStatus::InvalidToken, $this->broker()->reset('asha@example.com', $this->token, 'again'), 'and then it is used up');
    }

    public function testAnExpiredOrWrongTokenIsNotConsumed(): void
    {
        $this->broker()->sendResetLink('asha@example.com');

        self::assertSame(ResetStatus::InvalidToken, $this->broker()->reset('asha@example.com', 'wrong', 'x-password'));
        self::assertSame(ResetStatus::InvalidToken, $this->broker(static fn(): int => time() + 3601)->reset('asha@example.com', $this->token, 'x-password'));
        self::assertSame(1, $this->db->table('password_reset_tokens')->count(), 'a refused attempt leaves the token');
        self::assertTrue($this->hasher->verify('old-password', $this->storedHash()));
    }
}
