<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Unit\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use VeyroPHP\Cache\ArrayStore;
use VeyroPHP\Cache\Cache;
use VeyroPHP\Cache\Exception\LockTimeoutException;
use VeyroPHP\Security\RateLimiter;

#[CoversClass(RateLimiter::class)]
#[CoversClass(Cache::class)]
final class RateLimiterTest extends TestCase
{
    private int $now = 1_000_000;

    private ArrayStore $store;

    private Cache $cache;

    private RateLimiter $limiter;

    protected function setUp(): void
    {
        $this->store = new ArrayStore(fn(): int => $this->now);
        $this->cache = new Cache($this->store, 'app', null, 10, 50, fn(): int => $this->now);
        $this->limiter = new RateLimiter($this->cache, fn(): int => $this->now);
    }

    /**
     * VQ-020: attempt() checks and counts in one step, so parallel requests cannot all
     * pass the check before any of them is counted.
     */
    public function testAttemptAllowsUpToTheLimitAndCountsOnlyAllowedAttempts(): void
    {
        self::assertTrue($this->limiter->attempt('k', 2, 60));
        self::assertTrue($this->limiter->attempt('k', 2, 60));
        self::assertFalse($this->limiter->attempt('k', 2, 60));
        self::assertFalse($this->limiter->attempt('k', 2, 60));
        self::assertSame(2, $this->limiter->attempts('k'), 'refused attempts are not counted');
        self::assertSame(60, $this->limiter->availableIn('k'));

        $this->now += 60;
        self::assertTrue($this->limiter->attempt('k', 2, 60), 'a new window');
    }

    public function testAttemptChecksAndCountsUnderOneLock(): void
    {
        $log = new \ArrayObject();
        $inner = new ArrayStore(fn(): int => $this->now);
        $store = new class ($inner, $log) implements \VeyroPHP\Cache\Store, \VeyroPHP\Cache\LockingStore {
            /** @param \ArrayObject<int, string> $log */
            public function __construct(private readonly ArrayStore $inner, private readonly \ArrayObject $log) {}

            public function fetch(string $key): ?array
            {
                $this->log[] = 'read';

                return $this->inner->fetch($key);
            }

            public function put(string $key, mixed $value, ?int $expiresAt): bool
            {
                $this->log[] = 'write';

                return $this->inner->put($key, $value, $expiresAt);
            }

            public function delete(string $key): bool
            {
                return $this->inner->delete($key);
            }

            public function clear(string $prefix): bool
            {
                return $this->inner->clear($prefix);
            }

            public function acquireLock(string $name, int $seconds): ?string
            {
                $this->log[] = 'lock';

                return $this->inner->acquireLock($name, $seconds);
            }

            public function releaseLock(string $name, string $token): void
            {
                $this->log[] = 'unlock';
                $this->inner->releaseLock($name, $token);
            }
        };
        $limiter = new RateLimiter(new Cache($store, 'app', null, 10, 50, fn(): int => $this->now), fn(): int => $this->now);

        $limiter->attempt('k', 5, 60);

        self::assertSame(['lock', 'read', 'write', 'unlock'], $log->getArrayCopy(), 'the check (read) and the count (write) happen while the lock is held');
    }

    public function testCountsHitsInsideTheWindow(): void
    {
        self::assertSame(0, $this->limiter->attempts('login:a'));
        self::assertSame(1, $this->limiter->hit('login:a', 60));
        self::assertSame(2, $this->limiter->hit('login:a', 60));
        self::assertSame(3, $this->limiter->hit('login:a', 60));

        self::assertSame(3, $this->limiter->attempts('login:a'));
        self::assertSame(2, $this->limiter->remaining('login:a', 5));
        self::assertFalse($this->limiter->tooManyAttempts('login:a', 5));
        self::assertTrue($this->limiter->tooManyAttempts('login:a', 3));
        self::assertSame(0, $this->limiter->remaining('login:a', 2));
    }

    public function testKeysAreIndependent(): void
    {
        $this->limiter->hit('login:a');
        $this->limiter->hit('login:a');
        $this->limiter->hit('login:b');

        self::assertSame(2, $this->limiter->attempts('login:a'));
        self::assertSame(1, $this->limiter->attempts('login:b'));
    }

    public function testWindowIsFixedAndResetsAfterDecay(): void
    {
        $this->limiter->hit('k', 60);
        $this->now += 30;
        $this->limiter->hit('k', 60);

        // A later hit does not extend the window.
        self::assertSame(30, $this->limiter->availableIn('k'));

        $this->now += 29;
        self::assertSame(2, $this->limiter->attempts('k'));

        $this->now += 1;
        self::assertSame(0, $this->limiter->attempts('k'));
        self::assertSame(1, $this->limiter->hit('k', 60));
        self::assertSame(60, $this->limiter->availableIn('k'));
    }

    public function testClearResetsTheKey(): void
    {
        $this->limiter->hit('k');
        $this->limiter->hit('k');
        $this->limiter->clear('k');

        self::assertSame(0, $this->limiter->attempts('k'));
        self::assertSame(0, $this->limiter->availableIn('k'));
    }

    public function testKeysWithCharactersCacheKeysForbidAreAccepted(): void
    {
        $key = 'login:user@example.com|2001:db8::1 {weird}/(key)';

        self::assertSame(1, $this->limiter->hit($key));
        self::assertSame(1, $this->limiter->attempts($key));
    }

    public function testCorruptStateCountsAsNoAttempts(): void
    {
        $this->cache->set('ratelimit.' . hash('sha256', 'k'), 'garbage');

        self::assertSame(0, $this->limiter->attempts('k'));
        self::assertSame(1, $this->limiter->hit('k'));
    }

    public function testHitHoldsTheLockAndReleasesIt(): void
    {
        $lockName = 'app:ratelimit.' . hash('sha256', 'k') . '#lock';

        $this->limiter->hit('k');

        // Released afterwards: another caller can take it.
        $token = $this->store->acquireLock($lockName, 10);
        self::assertNotNull($token);

        // While someone else holds it, hit() waits (50 ms here) and then fails closed (VQ-057):
        // it throws instead of counting without the lock.
        try {
            $this->limiter->hit('k');
            self::fail('expected LockTimeoutException');
        } catch (LockTimeoutException) {
        }

        $this->store->releaseLock($lockName, $token);
        self::assertSame(1, $this->limiter->attempts('k'), 'nothing was counted without the lock');
    }

    public function testAttemptRefusesWhenTheLockCannotBeAcquired(): void
    {
        $lockName = 'app:ratelimit.' . hash('sha256', 'k') . '#lock';
        $token = $this->store->acquireLock($lockName, 10);
        self::assertNotNull($token);

        self::assertFalse($this->limiter->attempt('k', 5), 'fail closed: refused, not allowed without the lock');
        self::assertSame(0, $this->limiter->attempts('k'));

        $this->store->releaseLock($lockName, $token);
        self::assertTrue($this->limiter->attempt('k', 5));
    }

    public function testCacheLockDoesNotRunTheCallbackWithoutTheLock(): void
    {
        $token = $this->store->acquireLock('app:counter#lock', 10);
        self::assertNotNull($token);
        $ran = false;

        try {
            $this->cache->lock('counter', static function () use (&$ran): void {
                $ran = true;
            });
            self::fail('expected LockTimeoutException');
        } catch (LockTimeoutException $e) {
            self::assertStringContainsString('counter', $e->getMessage());
        }

        self::assertFalse($ran);
    }

    public function testCacheLockRunsCallbackUnderTheLock(): void
    {
        $result = $this->cache->lock('counter', function (): string {
            self::assertNull($this->store->acquireLock('app:counter#lock', 10), 'lock must be held during the callback');

            return 'done';
        });

        self::assertSame('done', $result);
        self::assertNotNull($this->store->acquireLock('app:counter#lock', 10));
    }
}
