<?php

declare(strict_types=1);

namespace VeyroPHP\Support;

use DateTimeImmutable;
use Random\Engine\Mt19937;
use Random\Engine\Secure;
use Random\Randomizer;

/**
 * Small fake-data generator for factories, seeders and tests.
 *
 *     $fake = new Fake();          // random
 *     $fake = new Fake(seed: 42);  // the same data on every run
 *     $fake->name(); $fake->email(); $fake->sentence(); $fake->numberBetween(1, 10);
 *
 * Emails use reserved test domains (example.com, example.org, example.net), so
 * seeded data can never mail real people. For more kinds of data, use any
 * generator you like (for example fakerphp/faker) in your factories.
 */
final class Fake
{
    private const FIRST_NAMES = [
        'Aarav', 'Aditi', 'Ananya', 'Arjun', 'Diya', 'Ishaan', 'Kabir', 'Kavya', 'Meera', 'Neha',
        'Priya', 'Rahul', 'Riya', 'Rohan', 'Saanvi', 'Vihaan', 'Zara', 'Amit', 'Pooja', 'Vikram',
        'Emma', 'Liam', 'Olivia', 'Noah', 'Sofia', 'Lucas', 'Mia', 'Leo', 'Hana', 'Omar',
    ];

    private const LAST_NAMES = [
        'Sharma', 'Verma', 'Gupta', 'Singh', 'Patel', 'Kumar', 'Reddy', 'Iyer', 'Nair', 'Mehta',
        'Saini', 'Joshi', 'Chopra', 'Das', 'Khan', 'Smith', 'Garcia', 'Müller', 'Rossi', 'Tanaka',
    ];

    private const CITIES = [
        'Jaipur', 'Delhi', 'Mumbai', 'Bengaluru', 'Chennai', 'Kolkata', 'Pune', 'Hyderabad', 'Ahmedabad', 'Alwar',
        'Lucknow', 'Indore', 'Chandigarh', 'Kochi', 'Gurugram', 'London', 'Berlin', 'Toronto', 'Singapore', 'Dubai',
    ];

    private const WORDS = [
        'alpha', 'amber', 'anchor', 'apple', 'arch', 'atlas', 'balance', 'bridge', 'bright', 'canvas',
        'castle', 'cedar', 'circle', 'cloud', 'coral', 'crystal', 'delta', 'desert', 'echo', 'ember',
        'field', 'forest', 'garden', 'harbor', 'island', 'journey', 'lantern', 'meadow', 'mountain', 'ocean',
        'orbit', 'paper', 'pepper', 'planet', 'quiet', 'river', 'saffron', 'shadow', 'signal', 'silver',
        'spark', 'stone', 'summit', 'thunder', 'timber', 'valley', 'velvet', 'window', 'winter', 'yellow',
    ];

    private const DOMAINS = ['example.com', 'example.org', 'example.net'];

    private readonly Randomizer $random;

    /** @var array<string, true> */
    private array $usedEmails = [];

    public function __construct(?int $seed = null)
    {
        $this->random = new Randomizer($seed === null ? new Secure() : new Mt19937($seed));
    }

    public function firstName(): string
    {
        return $this->pick(self::FIRST_NAMES);
    }

    public function lastName(): string
    {
        return $this->pick(self::LAST_NAMES);
    }

    public function name(): string
    {
        return $this->firstName() . ' ' . $this->lastName();
    }

    /**
     * A unique (per Fake instance) address on a reserved example domain.
     */
    public function email(): string
    {
        do {
            $local = strtolower($this->firstName() . '.' . $this->lastName()) . $this->numberBetween(1, 9999);
            $local = (string) preg_replace('/[^a-z0-9.]/', '', strtr($local, ['ü' => 'u']));
            $email = $local . '@' . $this->pick(self::DOMAINS);
        } while (isset($this->usedEmails[$email]));

        $this->usedEmails[$email] = true;

        return $email;
    }

    public function city(): string
    {
        return $this->pick(self::CITIES);
    }

    /**
     * An Indian mobile number in E.164 form (+91 and 10 digits starting with 6-9).
     */
    public function phone(): string
    {
        return '+91' . $this->numberBetween(6, 9) . str_pad((string) $this->numberBetween(0, 999_999_999), 9, '0', STR_PAD_LEFT);
    }

    public function word(): string
    {
        return $this->pick(self::WORDS);
    }

    /**
     * @return list<string>
     */
    public function words(int $count = 3): array
    {
        $words = [];

        for ($i = 0; $i < $count; $i++) {
            $words[] = $this->word();
        }

        return $words;
    }

    public function sentence(int $words = 6): string
    {
        return ucfirst(implode(' ', $this->words(max(1, $words)))) . '.';
    }

    public function paragraph(int $sentences = 3): string
    {
        $parts = [];

        for ($i = 0; $i < max(1, $sentences); $i++) {
            $parts[] = $this->sentence($this->numberBetween(4, 10));
        }

        return implode(' ', $parts);
    }

    public function numberBetween(int $min = 0, int $max = 100): int
    {
        return $this->random->getInt(min($min, $max), max($min, $max));
    }

    public function randomFloat(float $min = 0.0, float $max = 1.0, int $decimals = 2): float
    {
        return round($min + ($max - $min) * $this->random->getInt(0, PHP_INT_MAX) / PHP_INT_MAX, $decimals);
    }

    /**
     * @param int $chance percent of true results (0-100)
     */
    public function boolean(int $chance = 50): bool
    {
        return $this->random->getInt(1, 100) <= $chance;
    }

    /**
     * @template T
     *
     * @param non-empty-list<T> $items
     *
     * @return T
     */
    public function randomElement(array $items): mixed
    {
        return $items[$this->random->getInt(0, count($items) - 1)];
    }

    public function dateTimeBetween(string $from = '-1 year', string $to = 'now'): DateTimeImmutable
    {
        $start = (new DateTimeImmutable($from))->getTimestamp();
        $end = (new DateTimeImmutable($to))->getTimestamp();

        return (new DateTimeImmutable())->setTimestamp($this->random->getInt(min($start, $end), max($start, $end)));
    }

    /**
     * A version 4 UUID.
     */
    public function uuid(): string
    {
        $bytes = $this->random->getBytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /**
     * @param non-empty-list<string> $items
     */
    private function pick(array $items): string
    {
        return $items[$this->random->getInt(0, count($items) - 1)];
    }
}
