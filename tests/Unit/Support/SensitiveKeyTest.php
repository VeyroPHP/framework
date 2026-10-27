<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VeyroPHP\Support\SensitiveKey;

/**
 * HTTP-8, MISC-11, VQ-023: one matcher for log redaction (broad) and flashed form input
 * (whole segments for short words).
 */
#[CoversClass(SensitiveKey::class)]
final class SensitiveKeyTest extends TestCase
{
    /**
     * Secret in both modes. The hyphenated names used to be flashed (HTTP-8).
     *
     * @return iterable<string, array{string}>
     */
    public static function secretNames(): iterable
    {
        foreach ([
            'password', 'user_password', 'userPassword', 'passwd', 'passphrase', 'client_secret',
            'api_token', 'api-key', 'X-Api-Key', 'apiKey', 'private-key', 'card-number', 'cardNumber',
            'credit_card', 'APP_KEY', 'aws_access_key', 'Authorization',
            'otp', 'user-otp', 'userOTP', 'cvv', 'cvc', 'ssn', 'pwd', 'pass', 'db_pass', 'DB_DSN', 'jwt',
        ] as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('secretNames')]
    public function testSecretNamesMatchInBothModes(string $name): void
    {
        self::assertTrue(SensitiveKey::matches($name, false), 'flash');
        self::assertTrue(SensitiveKey::matches($name, true), 'logs');
    }

    /**
     * Ordinary form fields that contain a short word inside another word: they keep their
     * old input after a validation error, but logs still hide them (broad matching).
     *
     * @return iterable<string, array{string}>
     */
    public static function shortWordInsideAWord(): iterable
    {
        foreach (['business_name', 'footprint', 'passport_no', 'compass', 'bypass_reason'] as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('shortWordInsideAWord')]
    public function testShortWordsMatchWholeSegmentsOnlyForFlashing(string $name): void
    {
        self::assertFalse(SensitiveKey::matches($name, false), 'flash');
        self::assertTrue(SensitiveKey::matches($name, true), 'logs');
    }

    public function testHarmlessNamesMatchInNeitherMode(): void
    {
        foreach (['name', 'email', 'city', 'pin_code', 'session_year', 'order_id', 'key', 'path'] as $name) {
            self::assertFalse(SensitiveKey::matches($name, true), $name);
            self::assertFalse(SensitiveKey::matches($name, false), $name);
        }
    }

    public function testExtraWordsAreMatchedLikeLongWords(): void
    {
        self::assertTrue(SensitiveKey::matches('Aadhaar-Number', false, ['aadhaarnumber']));
        self::assertFalse(SensitiveKey::matches('Aadhaar-Number', false));
    }
}
