<?php

declare(strict_types=1);

namespace VeyroPHP\Support;

/**
 * @internal one list of secret-looking names for log redaction and for input that must
 * not be flashed to the session (HTTP-8, MISC-11, VQ-023).
 *
 * Names are compared after lowercasing and removing everything except letters and digits,
 * so "api_key", "X-Api-Key", "api-key" and "apiKey" are the same name. Long words match
 * anywhere in the name ("userPassword"). Short words (otp, ssn, ...) match:
 *
 * - broadly (logs): anywhere, like the long words, because a hidden harmless log value
 *   costs little and a leaked secret costs a lot;
 * - as a whole segment (flashed form input): "otp" and "user-otp", but not "footprint",
 *   so ordinary fields keep their old input after a validation error.
 */
final class SensitiveKey
{
    public const WORDS = [
        'password', 'passwd', 'passphrase', 'secret', 'token', 'apikey', 'appkey',
        'privatekey', 'accesskey', 'creditcard', 'cardnumber', 'authorization',
    ];

    public const SHORT_WORDS = ['pwd', 'pass', 'otp', 'ssn', 'cvv', 'cvc', 'dsn', 'jwt'];

    /**
     * @param list<string> $extraWords more words, matched like WORDS
     */
    public static function matches(string $name, bool $broad, array $extraWords = []): bool
    {
        $normalized = self::normalize($name);

        foreach ([...self::WORDS, ...$extraWords] as $word) {
            if ($word !== '' && str_contains($normalized, $word)) {
                return true;
            }
        }

        if ($broad) {
            foreach (self::SHORT_WORDS as $word) {
                if (str_contains($normalized, $word)) {
                    return true;
                }
            }

            return false;
        }

        return array_intersect(self::segments($name), self::SHORT_WORDS) !== [];
    }

    public static function normalize(string $name): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower($name)) ?? '';
    }

    /**
     * "userOTP_code" gives user, otp, code.
     *
     * @return list<string>
     */
    private static function segments(string $name): array
    {
        $spaced = preg_replace(['/([a-z0-9])([A-Z])/', '/([A-Z]+)([A-Z][a-z])/'], '$1 $2', $name) ?? $name;

        return preg_split('/[^a-z0-9]+/', strtolower($spaced), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
}
