<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Unit\Encryption;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VeyroPHP\Application\Application;
use VeyroPHP\Config\Config;
use VeyroPHP\Encryption\AppKeys;
use VeyroPHP\Encryption\DecryptException;
use VeyroPHP\Encryption\Encrypter;
use VeyroPHP\Encryption\EncryptionException;
use VeyroPHP\Encryption\EncryptionServiceProvider;

#[CoversClass(Encrypter::class)]
#[CoversClass(EncryptionServiceProvider::class)]
#[CoversClass(EncryptionException::class)]
#[CoversClass(DecryptException::class)]
final class EncrypterTest extends TestCase
{
    private static function encrypter(?string $key = null, string ...$previous): Encrypter
    {
        return new Encrypter($key ?? str_repeat('k', 32), array_values($previous));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function values(): iterable
    {
        yield 'array' => [['user' => 5, 'roles' => ['admin'], 'name' => 'Ásha ✓', 'url' => 'a/b']];
        yield 'string' => ['secret'];
        yield 'empty string' => [''];
        yield 'int' => [42];
        yield 'float keeps .0' => [1.0];
        yield 'bool' => [false];
        yield 'null' => [null];
    }

    #[DataProvider('values')]
    public function testRoundTrip(mixed $value): void
    {
        $e = self::encrypter();

        self::assertSame($value, $e->decrypt($e->encrypt($value)));
        self::assertSame($value, $e->decrypt($e->encrypt($value, 'invite'), 'invite'));
    }

    public function testStringRoundTripIncludingBinary(): void
    {
        $e = self::encrypter();
        $binary = random_bytes(1000) . "\0";

        self::assertSame($binary, $e->decryptString($e->encryptString($binary)));
        self::assertSame('', $e->decryptString($e->encryptString('')));
    }

    public function testOutputIsUrlSafeAndDifferentEveryTime(): void
    {
        $e = self::encrypter();
        $seen = [];

        for ($i = 0; $i < 200; $i++) {
            $token = $e->encrypt('same');
            self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $token);
            $seen[$token] = true;
        }

        self::assertCount(200, $seen, 'random nonce: equal values must not give equal ciphertexts');
    }

    public function testFormatIsStandardAes256GcmAsDocumented(): void
    {
        $key = random_bytes(32);
        $token = self::encrypter($key)->encryptString('hello', 'p');

        // Decrypt independently with OpenSSL: version | 12-byte nonce | 16-byte tag | ciphertext.
        $raw = (string) base64_decode(strtr($token, '-_', '+/'), true);
        self::assertSame("\x01", $raw[0]);
        self::assertSame(1 + 12 + 16 + 5, strlen($raw));
        $plain = openssl_decrypt(substr($raw, 29), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 1, 12), substr($raw, 13, 16), 'veyrophp:v1:p');
        self::assertSame('hello', $plain);
    }

    public function testEveryChangedByteIsDetected(): void
    {
        $e = self::encrypter();
        $raw = (string) base64_decode(strtr($e->encryptString('pay 100 to A'), '-_', '+/'), true);

        for ($i = 0; $i < strlen($raw); $i++) {
            $changed = $raw;
            $changed[$i] = chr(ord($changed[$i]) ^ 0x01);
            $token = rtrim(strtr(base64_encode($changed), '+/', '-_'), '=');

            try {
                $e->decryptString($token);
                self::fail('changed byte ' . $i . ' was not detected');
            } catch (DecryptException) {
                $this->addToAssertionCount(1);
            }
        }

        // Cut off or extended.
        foreach ([substr($raw, 0, -1), $raw . 'x'] as $changed) {
            $this->expectDecryptFailure($e, rtrim(strtr(base64_encode($changed), '+/', '-_'), '='));
        }
    }

    public function testPurposeIsBound(): void
    {
        $e = self::encrypter();
        $invite = $e->encrypt(['user' => 5], 'invite');

        $this->expectDecryptFailure($e, $invite, 'password-reset');
        $this->expectDecryptFailure($e, $invite);
        $this->expectDecryptFailure($e, $e->encrypt(['user' => 5]), 'invite');
    }

    public function testOtherKeyCannotDecrypt(): void
    {
        $this->expectDecryptFailure(self::encrypter(str_repeat('b', 32)), self::encrypter(str_repeat('a', 32))->encrypt('x'));
    }

    public function testKeyRotation(): void
    {
        $old = str_repeat('o', 32);
        $new = str_repeat('n', 32);
        $oldToken = self::encrypter($old)->encrypt('from before');

        $rotated = self::encrypter($new, $old);
        self::assertSame('from before', $rotated->decrypt($oldToken), 'old data stays readable');

        $newToken = $rotated->encrypt('after');
        self::assertSame('after', self::encrypter($new)->decrypt($newToken), 'new data uses the new key');
        $this->expectDecryptFailure(self::encrypter($old), $newToken);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidPayloads(): iterable
    {
        yield 'empty' => [''];
        yield 'not base64' => ['%%%***'];
        yield 'too short' => ['AQ'];
        yield 'wrong version' => [rtrim(strtr(base64_encode("\x02" . str_repeat('a', 40)), '+/', '-_'), '=')];
        yield 'plain text' => ['hello world'];
    }

    #[DataProvider('invalidPayloads')]
    public function testInvalidPayloadsThrowDecryptException(string $payload): void
    {
        $this->expectDecryptFailure(self::encrypter(), $payload);
    }

    public function testDecryptOfEncryptStringValueThatIsNotJson(): void
    {
        $e = self::encrypter();

        $this->expectException(DecryptException::class);
        $this->expectExceptionMessage('not valid JSON');
        $e->decrypt($e->encryptString('not json'));
    }

    public function testValuesThatAreNotJsonEncodableAreRejected(): void
    {
        $this->expectException(EncryptionException::class);
        $this->expectExceptionMessage('JSON-encodable');
        self::encrypter()->encrypt(NAN);
    }

    public function testObjectsAreNeverRecreated(): void
    {
        $e = self::encrypter();

        self::assertSame(['name' => 'x'], $e->decrypt($e->encrypt((object) ['name' => 'x'])));
    }

    public function testErrorMessagesNeverContainKeyOrValue(): void
    {
        $key = random_bytes(32);
        $e = self::encrypter($key);
        $token = $e->encrypt('top-secret-value', 'a');

        try {
            $e->decrypt($token, 'b');
            self::fail('expected failure');
        } catch (DecryptException $ex) {
            self::assertStringNotContainsString('top-secret-value', $ex->getMessage());
            self::assertStringNotContainsString($token, $ex->getMessage());
            self::assertStringNotContainsString(base64_encode($key), $ex->getMessage());
        }
    }

    public function testGenerateAndParseKey(): void
    {
        $key = Encrypter::generateKey();

        self::assertMatchesRegularExpression('#^base64:[A-Za-z0-9+/]{43}=$#', $key);
        self::assertSame(32, strlen(Encrypter::parseKey($key)));
        self::assertNotSame($key, Encrypter::generateKey());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function badKeys(): iterable
    {
        yield 'empty' => ['', 'key:generate'];
        yield 'no prefix' => [base64_encode(random_bytes(32)), 'must start with "base64:"'];
        yield 'too short' => ['base64:' . base64_encode(random_bytes(16)), 'must be 32 bytes'];
        yield 'not base64' => ['base64:@@@', 'must be 32 bytes'];
    }

    #[DataProvider('badKeys')]
    public function testParseKeyRejectsBadKeys(string $key, string $message): void
    {
        $this->expectException(EncryptionException::class);
        $this->expectExceptionMessage($message);
        Encrypter::parseKey($key);
    }

    public function testRawKeysMustBe32Bytes(): void
    {
        foreach ([[str_repeat('a', 31), []], [str_repeat('a', 32), ['short']]] as [$key, $previous]) {
            try {
                new Encrypter($key, $previous);
                self::fail('expected EncryptionException');
            } catch (EncryptionException $e) {
                self::assertStringContainsString('exactly 32 bytes', $e->getMessage());
            }
        }
    }

    public function testServiceProviderReadsKeyAndCommaSeparatedPreviousKeys(): void
    {
        $old = Encrypter::generateKey();
        $oldToken = (new Encrypter(Encrypter::parseKey($old)))->encrypt('old');
        $app = $this->app(['key' => Encrypter::generateKey(), 'previous_keys' => Encrypter::generateKey() . ', ' . $old]);

        $e = $app->container()->make(Encrypter::class);
        self::assertSame($e, $app->container()->make(Encrypter::class));
        self::assertSame('old', $e->decrypt($oldToken));

        $list = $this->app(['key' => Encrypter::generateKey(), 'previous_keys' => [$old]]);
        self::assertSame('old', $list->container()->make(Encrypter::class)->decrypt($oldToken));
    }

    public function testATrailingCommaOrBlankEntryInPreviousKeysIsIgnored(): void
    {
        $old = Encrypter::generateKey();
        $oldToken = (new Encrypter(Encrypter::parseKey($old)))->encrypt('old');

        // MISC-10: a trailing comma used to fail with the misleading "APP_KEY is missing".
        $app = $this->app(['key' => ' ' . Encrypter::generateKey() . ' ', 'previous_keys' => $old . ', ,']);
        self::assertSame('old', $app->container()->make(Encrypter::class)->decrypt($oldToken));

        self::assertSame([$old, $old], AppKeys::fromConfig(new Config(['app' => ['previous_keys' => [" {$old}", '', $old]]]))[1]);
    }

    public function testWronglyTypedKeyConfigFailsForEveryUserOfTheKeys(): void
    {
        foreach ([['key' => 123], ['previous_keys' => 5], ['previous_keys' => [Encrypter::generateKey(), 7]]] as $appConfig) {
            try {
                AppKeys::fromConfig(new Config(['app' => $appConfig]));
                self::fail('EncryptionException expected');
            } catch (EncryptionException $e) {
                self::assertStringContainsString('config app.', $e->getMessage());
            }
        }
    }

    public function testMissingKeyOnlyFailsWhenEncryptionIsUsed(): void
    {
        $app = $this->app([]);
        self::assertTrue($app->isBooted());

        $this->expectException(EncryptionException::class);
        $this->expectExceptionMessage('php veyro key:generate');
        $app->container()->make(Encrypter::class);
    }

    /**
     * @param array<string, mixed> $appConfig
     */
    private function app(array $appConfig): Application
    {
        $app = new Application(sys_get_temp_dir());
        $app->container()->instance(Config::class, new Config(['app' => $appConfig]));
        $app->register(new EncryptionServiceProvider());
        $app->boot();

        return $app;
    }

    private function expectDecryptFailure(Encrypter $e, string $payload, string $purpose = ''): void
    {
        try {
            $e->decryptString($payload, $purpose);
            self::fail('DecryptException expected');
        } catch (DecryptException) {
            $this->addToAssertionCount(1);
        }
    }
}
