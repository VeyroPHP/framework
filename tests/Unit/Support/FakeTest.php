<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Unit\Support;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use VeyroPHP\Support\Fake;

#[CoversClass(Fake::class)]
final class FakeTest extends TestCase
{
    public function testSameSeedGivesSameData(): void
    {
        $a = new Fake(seed: 42);
        $b = new Fake(seed: 42);

        for ($i = 0; $i < 20; $i++) {
            self::assertSame([$a->name(), $a->email(), $a->sentence(), $a->numberBetween(1, 1000)], [$b->name(), $b->email(), $b->sentence(), $b->numberBetween(1, 1000)]);
        }

        self::assertNotSame((new Fake(seed: 1))->paragraph(), (new Fake(seed: 2))->paragraph());
    }

    public function testEmailsAreUniqueAndOnReservedDomains(): void
    {
        $fake = new Fake(seed: 7);
        $emails = [];

        for ($i = 0; $i < 2000; $i++) {
            $email = $fake->email();
            self::assertMatchesRegularExpression('/^[a-z0-9.]+@example\.(com|org|net)$/', $email);
            self::assertNotFalse(filter_var($email, FILTER_VALIDATE_EMAIL));
            $emails[] = $email;
        }

        self::assertCount(2000, array_unique($emails));
    }

    public function testFormats(): void
    {
        $fake = new Fake();

        for ($i = 0; $i < 200; $i++) {
            self::assertMatchesRegularExpression('/^\+91[6-9]\d{9}$/', $fake->phone());
            self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $fake->uuid());

            $n = $fake->numberBetween(5, 7);
            self::assertTrue($n >= 5 && $n <= 7);
            $f = $fake->randomFloat(1.5, 2.5);
            self::assertTrue($f >= 1.5 && $f <= 2.5);

            $date = $fake->dateTimeBetween('2026-01-01', '2026-01-31');
            self::assertTrue($date >= new DateTimeImmutable('2026-01-01') && $date <= new DateTimeImmutable('2026-01-31'));
        }

        self::assertMatchesRegularExpression('/^[A-Z][a-z]+( [a-z]+){5}\.$/', $fake->sentence());
        self::assertCount(4, $fake->words(4));
        self::assertContains($fake->randomElement(['a', 'b']), ['a', 'b']);
        self::assertSame(5, $fake->numberBetween(5, 5));
        self::assertNotSame('', $fake->city());
    }

    public function testBooleanChanceExtremes(): void
    {
        $fake = new Fake(seed: 3);

        for ($i = 0; $i < 100; $i++) {
            self::assertTrue($fake->boolean(100));
            self::assertFalse($fake->boolean(0));
        }
    }
}
