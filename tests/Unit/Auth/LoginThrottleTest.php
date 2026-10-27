<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use VeyroPHP\Auth\LoginThrottle;
use VeyroPHP\Cache\ArrayStore;
use VeyroPHP\Cache\Cache;
use VeyroPHP\Security\RateLimiter;

/**
 * MISC-9: the two login limits of VQ-020, moved from the starter into the framework.
 */
#[CoversClass(LoginThrottle::class)]
final class LoginThrottleTest extends TestCase
{
    private int $now = 1_800_000_000;

    private RateLimiter $limiter;

    protected function setUp(): void
    {
        $store = new ArrayStore(fn(): int => $this->now);
        $this->limiter = new RateLimiter(new Cache($store, 'app', null, 10, 50, fn(): int => $this->now), fn(): int => $this->now);
    }

    private function throttle(): LoginThrottle
    {
        return new LoginThrottle($this->limiter, maxAttempts: 3, decaySeconds: 60, accountMaxAttempts: 5, accountDecaySeconds: 900);
    }

    public function testOneAddressIsLimitedPerAccount(): void
    {
        $throttle = $this->throttle();

        for ($i = 0; $i < 3; $i++) {
            self::assertNull($throttle->attempt('asha@example.test', '203.0.113.5'));
        }

        $wait = $throttle->attempt('ASHA@example.test ', '203.0.113.5');
        self::assertNotNull($wait, 'case and spaces do not give extra guesses');
        self::assertGreaterThanOrEqual(1, $wait);
        self::assertLessThanOrEqual(60, $wait);

        self::assertNull($throttle->attempt('ravi@example.test', '203.0.113.5'), 'another account from the same address');
        self::assertNull($throttle->attempt('asha@example.test', '198.51.100.7'), 'the same account from another address');
    }

    public function testGuessesSpreadOverManyAddressesHitTheAccountLimit(): void
    {
        $throttle = $this->throttle();

        foreach (['198.51.100.1', '198.51.100.2', '198.51.100.3', '198.51.100.4', '198.51.100.5'] as $ip) {
            self::assertNull($throttle->attempt('asha@example.test', $ip));
        }

        self::assertNotNull($throttle->attempt('asha@example.test', '198.51.100.6'));
        self::assertGreaterThan(60, (int) $throttle->attempt('asha@example.test', '198.51.100.7'), 'the account window is longer');
    }

    public function testIpv6AddressesInOneSlash64ShareACounter(): void
    {
        $throttle = $this->throttle();

        foreach (['2001:db8::1', '2001:db8::2', '2001:db8::ffff'] as $ip) {
            self::assertNull($throttle->attempt('asha@example.test', $ip));
        }

        self::assertNotNull($throttle->attempt('asha@example.test', '2001:db8::abcd'));
        self::assertNull($throttle->attempt('asha@example.test', '2001:db8:0:1::1'), 'another /64');
    }

    public function testASuccessfulLoginClearsBothCounters(): void
    {
        $throttle = $this->throttle();

        for ($i = 0; $i < 3; $i++) {
            $throttle->attempt('asha@example.test', '203.0.113.5');
        }

        $throttle->clear('asha@example.test', '203.0.113.5');
        self::assertNull($throttle->attempt('asha@example.test', '203.0.113.5'));
    }

    public function testTheWindowEnds(): void
    {
        $throttle = $this->throttle();

        for ($i = 0; $i < 4; $i++) {
            $throttle->attempt('asha@example.test', '203.0.113.5');
        }

        $this->now += 61;
        self::assertNull($throttle->attempt('asha@example.test', '203.0.113.5'));
    }

    public function testTheKeysAreTheStartersSoRunningCountersCarryOver(): void
    {
        $this->throttle()->attempt('asha@example.test', '203.0.113.5');

        self::assertSame(1, $this->limiter->attempts('login:asha@example.test|203.0.113.5'));
        self::assertSame(1, $this->limiter->attempts('login-account:asha@example.test'));
    }

    public function testLimitsMustBePositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new LoginThrottle($this->limiter, maxAttempts: 0);
    }
}
