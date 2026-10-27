<?php

declare(strict_types=1);

namespace VeyroPHP\Support;

/**
 * @internal URL-safe base64 without padding (RFC 4648 §5), and random secrets in that form
 * (SVC-7). Tokens, signatures, cursors and ETags all use this one encoding.
 */
final class Base64Url
{
    public static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /**
     * Strict: returns false for characters outside the alphabet.
     */
    public static function decode(string $text): string|false
    {
        return base64_decode(strtr($text, '-_', '+/'), true);
    }

    /**
     * A random secret of $bytes bytes from the CSPRNG (43 characters for the default 32).
     *
     * @param positive-int $bytes
     */
    public static function secret(int $bytes = 32): string
    {
        return self::encode(random_bytes($bytes));
    }
}
