<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Unit\Scheduling;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VeyroPHP\Scheduling\CronExpression;
use VeyroPHP\Scheduling\ScheduleException;

#[CoversClass(CronExpression::class)]
#[CoversClass(ScheduleException::class)]
final class CronExpressionTest extends TestCase
{
    private static function at(string $time, string $zone = 'UTC'): DateTimeImmutable
    {
        return new DateTimeImmutable($time, new DateTimeZone($zone));
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function dueCases(): iterable
    {
        // 2026-10-26 is a Monday.
        yield 'every minute' => ['* * * * *', '2026-10-26 13:37', true];
        yield 'every 15 at :45' => ['*/15 * * * *', '2026-10-26 13:45', true];
        yield 'every 15 at :46' => ['*/15 * * * *', '2026-10-26 13:46', false];
        yield 'range with step' => ['10-30/10 * * * *', '2026-10-26 13:20', true];
        yield 'range with step, outside' => ['10-30/10 * * * *', '2026-10-26 13:40', false];
        yield 'start with step' => ['5/20 * * * *', '2026-10-26 13:45', true];
        yield 'list' => ['0 8,12,18 * * *', '2026-10-26 12:00', true];
        yield 'weekday names' => ['0 9 * * mon-fri', '2026-10-26 09:00', true];
        yield 'weekday names, saturday' => ['0 9 * * mon-fri', '2026-10-31 09:00', false];
        yield 'sunday as 7' => ['0 0 * * 7', '2026-11-01 00:00', true];
        yield 'month names' => ['0 0 1 jan,jul *', '2026-07-01 00:00', true];
        yield 'day of month only' => ['0 0 26 * *', '2026-10-26 00:00', true];
        yield 'both restricted: day matches' => ['0 0 26 * fri', '2026-10-26 00:00', true];
        yield 'both restricted: weekday matches' => ['0 0 1 * mon', '2026-10-26 00:00', true];
        yield 'both restricted: neither' => ['0 0 1 * fri', '2026-10-26 00:00', false];
        yield '@daily' => ['@daily', '2026-10-26 00:00', true];
        yield '@hourly at :01' => ['@hourly', '2026-10-26 13:01', false];
        yield '@weekly on sunday' => ['@weekly', '2026-11-01 00:00', true];
        yield '@yearly' => ['@yearly', '2027-01-01 00:00', true];
        yield 'seconds are ignored' => ['30 13 * * *', '2026-10-26 13:30:59', true];
    }

    #[DataProvider('dueCases')]
    public function testIsDue(string $expression, string $time, bool $due): void
    {
        self::assertSame($due, (new CronExpression($expression))->isDue(self::at($time)));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function nextCases(): iterable
    {
        yield 'next quarter hour' => ['*/15 * * * *', '2026-10-26 13:37:12', '2026-10-26 13:45'];
        yield 'strictly after' => ['*/15 * * * *', '2026-10-26 13:45:00', '2026-10-26 14:00'];
        yield 'next day' => ['30 2 * * *', '2026-10-26 03:00', '2026-10-27 02:30'];
        yield 'next weekday' => ['0 9 * * mon-fri', '2026-10-30 10:00', '2026-11-02 09:00'];
        yield 'next month' => ['0 0 1 * *', '2026-10-26 13:00', '2026-11-01 00:00'];
        yield 'leap day' => ['0 0 29 2 *', '2026-10-26 00:00', '2028-02-29 00:00'];
        yield 'end of year' => ['59 23 31 12 *', '2026-12-31 23:59', '2027-12-31 23:59'];
    }

    #[DataProvider('nextCases')]
    public function testNextRunDate(string $expression, string $after, string $expected): void
    {
        self::assertSame($expected, (new CronExpression($expression))->nextRunDate(self::at($after))->format('Y-m-d H:i'));
    }

    public function testNextRunDateSkipsTimesThatDoNotExistOnDstDays(): void
    {
        // In New York, 2026-03-08 02:00-02:59 does not exist (clocks jump to 03:00).
        $next = (new CronExpression('30 2 * * *'))->nextRunDate(self::at('2026-03-07 03:00', 'America/New_York'));

        self::assertSame('2026-03-09 02:30', $next->format('Y-m-d H:i'));
    }

    public function testDueInTheTimeZoneOfTheGivenTime(): void
    {
        $cron = new CronExpression('0 9 * * *');
        $utc = self::at('2026-10-26 03:30');   // 09:00 in Kolkata

        self::assertFalse($cron->isDue($utc));
        self::assertTrue($cron->isDue($utc->setTimezone(new DateTimeZone('Asia/Kolkata'))));
    }

    public function testExpressionThatNeverMatches(): void
    {
        $this->expectException(ScheduleException::class);
        $this->expectExceptionMessage('never matches');
        (new CronExpression('0 0 31 2 *'))->nextRunDate(self::at('2026-10-26'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalid(): iterable
    {
        yield 'too few fields' => ['* * * *', 'needs 5 fields'];
        yield 'too many fields' => ['* * * * * *', 'needs 5 fields'];
        yield 'minute 60' => ['60 * * * *', '"60" is not a valid minute'];
        yield 'hour 24' => ['0 24 * * *', '"24" is not a valid hour'];
        yield 'day 0' => ['0 0 0 * *', '"0" is not a valid day of month'];
        yield 'month 13' => ['0 0 1 13 *', '"13" is not a valid month'];
        yield 'weekday 8' => ['0 0 * * 8', '"8" is not a valid day of week'];
        yield 'step 0' => ['*/0 * * * *', 'not a valid minute'];
        yield 'reversed range' => ['30-10 * * * *', 'not a valid minute'];
        yield 'unknown name' => ['0 0 * * funday', '"funday" is not a valid day of week'];
        yield 'garbage' => ['a b c d e', 'not a valid minute'];
    }

    #[DataProvider('invalid')]
    public function testInvalidExpressions(string $expression, string $message): void
    {
        $this->expectException(ScheduleException::class);
        $this->expectExceptionMessage($message);
        new CronExpression($expression);
    }

    public function testFieldsExpandAliases(): void
    {
        self::assertSame(['0', '0', '*', '*', '0'], (new CronExpression('@weekly'))->fields());
        self::assertSame(['*/5', '*', '*', '*', '*'], (new CronExpression('  */5   *  * * *  '))->fields());
    }
}
