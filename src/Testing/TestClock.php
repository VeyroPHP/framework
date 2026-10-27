<?php

declare(strict_types=1);

namespace VeyroPHP\Testing;

use Closure;
use DateTimeImmutable;
use DateTimeZone;

/**
 * A clock for tests that stands still until the test moves it.
 *
 *     $clock = new TestClock('2026-10-27 10:00:00', 'Asia/Kolkata');
 *     $limiter = new RateLimiter($cache, $clock->timestamps());
 *     $clock->travel(61);          // seconds, or '+1 day'
 *
 * There is no global time override: the framework's time-dependent classes take a
 * clock closure (RateLimiter, Cache, ArrayStore, MaintenanceMode take timestamps();
 * FileLogger takes dates()), and the test passes this clock's closures to them.
 */
final class TestClock
{
    private DateTimeImmutable $now;

    /**
     * @throws \InvalidArgumentException for a time or time zone PHP cannot read
     */
    public function __construct(string $time = 'now', string $timezone = 'UTC')
    {
        try {
            $this->now = new DateTimeImmutable($time, new DateTimeZone($timezone));
        } catch (\Exception $e) {
            throw new \InvalidArgumentException(sprintf('Invalid test time "%s" (%s).', $time, $timezone), 0, $e);
        }
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function timestamp(): int
    {
        return $this->now->getTimestamp();
    }

    /**
     * Moves the clock: a number of seconds (negative goes back) or a relative time such as "+2 hours".
     *
     * @throws \InvalidArgumentException for a modifier PHP cannot read
     */
    public function travel(int|string $by): void
    {
        $moved = is_int($by) ? $this->now->modify(sprintf('%+d seconds', $by)) : @$this->now->modify($by);

        if ($moved === false) {
            throw new \InvalidArgumentException(sprintf('Cannot move the test clock by "%s".', (string) $by));
        }

        $this->now = $moved;
    }

    /**
     * @throws \InvalidArgumentException for a time PHP cannot read
     */
    public function set(string $time): void
    {
        try {
            $this->now = new DateTimeImmutable($time, $this->now->getTimezone());
        } catch (\Exception $e) {
            throw new \InvalidArgumentException(sprintf('Invalid test time "%s".', $time), 0, $e);
        }
    }

    /**
     * @return Closure(): int Unix time of this clock, read at each call
     */
    public function timestamps(): Closure
    {
        return fn(): int => $this->now->getTimestamp();
    }

    /**
     * @return Closure(): DateTimeImmutable this clock's time, read at each call
     */
    public function dates(): Closure
    {
        return fn(): DateTimeImmutable => $this->now;
    }
}
