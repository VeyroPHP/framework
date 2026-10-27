<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VeyroPHP\Support\Base64Url;

/**
 * SVC-7: the one URL-safe base64 encoding used by tokens, signatures, cursors and ETags.
 */
#[CoversClass(Base64Url::class)]
final class Base64UrlTest extends TestCase
{
    /**
     * RFC 4648 §10 vectors, without padding, plus the two characters that differ from base64.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function vectors(): iterable
    {
        yield 'empty' => ['', ''];
        yield 'f' => ['f', 'Zg'];
        yield 'fo' => ['fo', 'Zm8'];
        yield 'foo' => ['foo', 'Zm9v'];
        yield 'foobar' => ['foobar', 'Zm9vYmFy'];
        yield '62 and 63' => ["\xfb\xff\xbf", '-_-_'];
    }

    #[DataProvider('vectors')]
    public function testEncodesAndDecodes(string $bytes, string $text): void
    {
        self::assertSame($text, Base64Url::encode($bytes));
        self::assertSame($bytes, Base64Url::decode($text));
    }

    public function testDecodingRejectsCharactersOutsideTheAlphabet(): void
    {
        self::assertFalse(Base64Url::decode('Zm9v!'));
        self::assertFalse(Base64Url::decode('Zm 9v' . "\0"));
    }

    public function testSecretsAreUrlSafeAndUnique(): void
    {
        $first = Base64Url::secret();

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $first);
        self::assertSame(32, strlen((string) Base64Url::decode($first)));
        self::assertNotSame($first, Base64Url::secret());
        self::assertSame(22, strlen(Base64Url::secret(16)));
    }
}
