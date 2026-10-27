<?php

declare(strict_types=1);

namespace VeyroPHP\Support;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * ULID: 26-character, URL-safe identifiers for public use, e.g. "01JB8Q3Z9X4V7K2M5N6P8R0T1W".
 *
 * 48 bits of millisecond timestamp followed by 80 random bits from
 * random_bytes(), encoded in Crockford base32. ULIDs sort by creation time at
 * millisecond precision; order within the same millisecond is random.
 *
 * Use them as public identifiers (for example a "public_id" column) so URLs
 * do not reveal auto-increment ids. They do not replace authorization checks.
 */
final class Ulid
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    private const MAX_TIME = 281474976710655; // 2^48 - 1

    public static function generate(?DateTimeInterface $time = null): string
    {
        $milliseconds = (int) ($time ?? new DateTimeImmutable())->format('Uv');

        if ($milliseconds < 0 || $milliseconds > self::MAX_TIME) {
            throw new InvalidArgumentException('ULID time must be between 1970 and the year 10889.');
        }

        return self::encodeTime($milliseconds) . self::encodeRandom(random_bytes(10));
    }

    public static function isValid(string $ulid): bool
    {
        return preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/D', $ulid) === 1;
    }

    /**
     * The creation time encoded in a ULID (millisecond precision, UTC).
     */
    public static function timestamp(string $ulid): DateTimeImmutable
    {
        if (!self::isValid($ulid)) {
            throw new InvalidArgumentException('Invalid ULID.');
        }

        $milliseconds = 0;
        for ($i = 0; $i < 10; $i++) {
            $milliseconds = $milliseconds * 32 + (int) strpos(self::ALPHABET, $ulid[$i]);
        }

        $time = DateTimeImmutable::createFromFormat('U.v', sprintf('%d.%03d', intdiv($milliseconds, 1000), $milliseconds % 1000));

        if ($time === false) {
            throw new InvalidArgumentException('Invalid ULID timestamp.');
        }

        return $time;
    }

    private static function encodeTime(int $milliseconds): string
    {
        $chars = '';
        for ($i = 0; $i < 10; $i++) {
            $chars = self::ALPHABET[$milliseconds % 32] . $chars;
            $milliseconds = intdiv($milliseconds, 32);
        }

        return $chars;
    }

    private static function encodeRandom(string $bytes): string
    {
        $chars = '';
        $buffer = 0;
        $bits = 0;

        foreach (str_split($bytes) as $byte) {
            $buffer = ($buffer << 8) | ord($byte);
            $bits += 8;

            while ($bits >= 5) {
                $bits -= 5;
                $chars .= self::ALPHABET[($buffer >> $bits) & 31];
            }

            $buffer &= (1 << $bits) - 1;
        }

        return $chars;
    }
}
