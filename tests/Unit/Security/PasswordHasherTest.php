<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Unit\Security;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use VeyroPHP\Security\PasswordHasher;

#[CoversClass(PasswordHasher::class)]
final class PasswordHasherTest extends TestCase
{
    public function testHashAndVerify(): void
    {
        $hasher = new PasswordHasher('bcrypt', ['cost' => 4]);
        $hash = $hasher->hash('correct horse battery staple');

        self::assertStringStartsWith('$2y$04$', $hash);
        self::assertTrue($hasher->verify('correct horse battery staple', $hash));
        self::assertFalse($hasher->verify('wrong', $hash));
        self::assertFalse($hasher->verify('', $hash));
        self::assertFalse($hasher->verify('x', 'not-a-hash'));
        self::assertFalse($hasher->verify('x', ''));
        self::assertFalse($hasher->verify("a\0b", $hash));
    }

    public function testBcryptRejectsPasswordsItWouldTruncate(): void
    {
        $hasher = new PasswordHasher('bcrypt', ['cost' => 4]);
        $hasher->hash(str_repeat('a', 72));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('72 bytes');
        $hasher->hash(str_repeat('a', 73));
    }

    public function testArgon2idAcceptsLongPasswords(): void
    {
        if (!defined('PASSWORD_ARGON2ID')) {
            self::markTestSkipped('argon2id not available.');
        }

        $hasher = new PasswordHasher('argon2id', ['memory_cost' => 8192, 'time_cost' => 1, 'threads' => 1]);
        $long = str_repeat('a', 72) . 'b';
        $hash = $hasher->hash($long);

        self::assertStringStartsWith('$argon2id$', $hash);
        self::assertTrue($hasher->verify($long, $hash));
        self::assertFalse($hasher->verify(str_repeat('a', 72) . 'c', $hash), 'no truncation');
    }

    public function testRejectsEmptyAndNullBytePasswords(): void
    {
        $hasher = new PasswordHasher('bcrypt', ['cost' => 4]);

        foreach (['', "abc\0def"] as $password) {
            try {
                $hasher->hash($password);
                self::fail('Expected InvalidArgumentException.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testNeedsRehashWhenCostChanges(): void
    {
        $old = (new PasswordHasher('bcrypt', ['cost' => 4]))->hash('pw');

        self::assertTrue((new PasswordHasher('bcrypt', ['cost' => 5]))->needsRehash($old));
        self::assertFalse((new PasswordHasher('bcrypt', ['cost' => 4]))->needsRehash($old));
    }

    public function testDummyHashUsesConfiguredCostAndIsStable(): void
    {
        $hasher = new PasswordHasher('bcrypt', ['cost' => 5]);

        self::assertSame(5, password_get_info($hasher->dummyHash())['options']['cost'] ?? null);
        self::assertSame($hasher->dummyHash(), $hasher->dummyHash());
    }

    /**
     * VQ-012: every web request creates a new hasher, so building the dummy hash must not
     * cost a password_hash() call. Otherwise "unknown user" (hash + verify) is measurably
     * slower than "wrong password" (verify only), and timing reveals which accounts exist.
     */
    public function testDummyHashIsBuiltWithoutHashing(): void
    {
        $configurations = [['bcrypt', ['cost' => 12]]];
        if (defined('PASSWORD_ARGON2ID')) {
            $configurations[] = ['argon2id', []];
        }

        foreach ($configurations as [$algorithm, $options]) {
            $start = hrtime(true);
            (new PasswordHasher($algorithm, $options))->hash('a real password');
            $hashing = hrtime(true) - $start;

            $hasher = new PasswordHasher($algorithm, $options);
            $start = hrtime(true);
            $dummy = $hasher->dummyHash();
            $building = hrtime(true) - $start;

            self::assertLessThan(0.1 * $hashing, $building, $algorithm . ': building the dummy hash must not hash');
            self::assertFalse($hasher->needsRehash($dummy), $algorithm . ': same algorithm and cost as real hashes');
            self::assertFalse($hasher->verify('anything', $dummy));
            self::assertSame(password_get_info($hasher->hash('x'))['algo'], password_get_info($dummy)['algo']);
            self::assertNotSame($dummy, (new PasswordHasher($algorithm, $options))->dummyHash(), 'random per instance');
        }
    }

    public function testUnknownAlgorithm(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PasswordHasher('md5');
    }
}
