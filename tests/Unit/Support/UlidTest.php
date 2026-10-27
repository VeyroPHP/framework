<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Unit\Support;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use VeyroPHP\Support\Ulid;

#[CoversClass(Ulid::class)]
final class UlidTest extends TestCase
{
    public function testGeneratesValidUlids(): void
    {
        $ulid = Ulid::generate();

        self::assertSame(26, strlen($ulid));
        self::assertTrue(Ulid::isValid($ulid));
        self::assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $ulid);
    }

    public function testEncodesTimestampAndDecodesItBack(): void
    {
        $time = new DateTimeImmutable('2026-10-25T10:15:30.123+00:00');
        $ulid = Ulid::generate($time);

        self::assertSame('2026-10-25 10:15:30.123', Ulid::timestamp($ulid)->format('Y-m-d H:i:s.v'));
    }

    public function testKnownTimePrefix(): void
    {
        // 1469918176385 ms is "01ARYZ6S41" in the ULID specification's example.
        $time = DateTimeImmutable::createFromFormat('U.v', '1469918176.385');
        self::assertInstanceOf(DateTimeImmutable::class, $time);

        self::assertStringStartsWith('01ARYZ6S41', Ulid::generate($time));
    }

    public function testSortsByTime(): void
    {
        $earlier = Ulid::generate(new DateTimeImmutable('2026-01-01'));
        $later = Ulid::generate(new DateTimeImmutable('2026-01-02'));

        self::assertLessThan(0, strcmp($earlier, $later));
    }

    public function testRandomPartIsUnique(): void
    {
        $time = new DateTimeImmutable('2026-01-01');
        $ulids = [];

        for ($i = 0; $i < 2000; $i++) {
            $ulids[] = Ulid::generate($time);
        }

        self::assertCount(2000, array_unique($ulids));
    }

    public function testValidation(): void
    {
        self::assertFalse(Ulid::isValid(''));
        self::assertFalse(Ulid::isValid("01ARYZ6S41TSV4RRFFQ69G5FAV\n"), 'trailing newline');
        self::assertFalse(Ulid::isValid('01ARYZ6S41TSV4RRFFQ69G5FA'));   // 25 chars
        self::assertFalse(Ulid::isValid('01ARYZ6S41TSV4RRFFQ69G5FAVX')); // 27 chars
        self::assertFalse(Ulid::isValid('01ARYZ6S41TSV4RRFFQ69G5FAI'));  // I is not in the alphabet
        self::assertFalse(Ulid::isValid('01arYZ6S41TSV4RRFFQ69G5FAV'));  // lowercase
        self::assertFalse(Ulid::isValid('81ARYZ6S41TSV4RRFFQ69G5FAV'));  // overflows 48 bits
        self::assertTrue(Ulid::isValid('01ARYZ6S41TSV4RRFFQ69G5FAV'));
    }

    public function testTimestampRejectsInvalidUlid(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Ulid::timestamp('not-a-ulid');
    }

    public function testRejectsTimeBefore1970(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Ulid::generate(new DateTimeImmutable('1969-12-31'));
    }
}
