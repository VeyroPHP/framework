<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Unit\Config\Env;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VeyroPHP\Config\Env\Env;
use VeyroPHP\Config\Exception\EnvException;

#[CoversClass(Env::class)]
#[CoversClass(EnvException::class)]
final class EnvTest extends TestCase
{
    public function testStringReturnsValueOrDefault(): void
    {
        $env = new Env(['NAME' => 'VeyroPHP', 'EMPTY' => '']);

        self::assertSame('VeyroPHP', $env->string('NAME'));
        self::assertSame('', $env->string('EMPTY', 'default'));
        self::assertSame('default', $env->string('MISSING', 'default'));
    }

    public function testMissingWithoutDefaultThrows(): void
    {
        $env = new Env();

        foreach (['string', 'int', 'float', 'bool'] as $method) {
            try {
                $env->{$method}('MISSING');
                self::fail("Expected {$method}() to throw.");
            } catch (EnvException $e) {
                self::assertStringContainsString('"MISSING" is not set', $e->getMessage());
            }
        }
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function validInts(): iterable
    {
        yield 'zero' => ['0', 0];
        yield 'positive' => ['8080', 8080];
        yield 'negative' => ['-5', -5];
        yield 'leading zero' => ['007', 7];
        yield 'negative leading zero' => ['-007', -7];
        yield 'only zeros' => ['000', 0];
        yield 'negative zero' => ['-0', 0];
        yield 'max' => [(string) PHP_INT_MAX, PHP_INT_MAX];
        yield 'min' => [(string) PHP_INT_MIN, PHP_INT_MIN];
    }

    #[DataProvider('validInts')]
    public function testIntParsesIntegers(string $raw, int $expected): void
    {
        self::assertSame($expected, (new Env(['N' => $raw]))->int('N'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidInts(): iterable
    {
        yield 'empty' => [''];
        yield 'float' => ['1.5'];
        yield 'spaces' => [' 1'];
        yield 'plus sign' => ['+1'];
        yield 'hex' => ['0x1A'];
        yield 'word' => ['abc'];
        yield 'trailing text' => ['10px'];
        yield 'overflow' => ['99999999999999999999999'];
    }

    #[DataProvider('invalidInts')]
    public function testIntRejectsNonIntegers(string $raw): void
    {
        $this->expectException(EnvException::class);
        $this->expectExceptionMessage('"N" must be an integer');

        (new Env(['N' => $raw]))->int('N');
    }

    public function testIntDefaultOnlyWhenMissing(): void
    {
        self::assertSame(3, (new Env())->int('N', 3));

        $this->expectException(EnvException::class);
        (new Env(['N' => '']))->int('N', 3);
    }

    /**
     * @return iterable<string, array{string, float}>
     */
    public static function validFloats(): iterable
    {
        yield 'int' => ['2', 2.0];
        yield 'decimal' => ['0.75', 0.75];
        yield 'negative' => ['-1.5', -1.5];
        yield 'leading dot' => ['.5', 0.5];
        yield 'exponent' => ['2.5e3', 2500.0];
    }

    #[DataProvider('validFloats')]
    public function testFloatParsesNumbers(string $raw, float $expected): void
    {
        self::assertSame($expected, (new Env(['F' => $raw]))->float('F'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidFloats(): iterable
    {
        yield 'empty' => [''];
        yield 'word' => ['fast'];
        yield 'comma decimal' => ['1,5'];
        yield 'nan' => ['NAN'];
        yield 'inf' => ['INF'];
        yield 'dot only' => ['.'];
    }

    #[DataProvider('invalidFloats')]
    public function testFloatRejectsNonNumbers(string $raw): void
    {
        $this->expectException(EnvException::class);
        $this->expectExceptionMessage('"F" must be a number');

        (new Env(['F' => $raw]))->float('F');
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function validBools(): iterable
    {
        yield 'true' => ['true', true];
        yield 'TRUE' => ['TRUE', true];
        yield 'one' => ['1', true];
        yield 'false' => ['false', false];
        yield 'False' => ['False', false];
        yield 'zero' => ['0', false];
    }

    #[DataProvider('validBools')]
    public function testBoolParsesStrictValues(string $raw, bool $expected): void
    {
        self::assertSame($expected, (new Env(['B' => $raw]))->bool('B'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidBools(): iterable
    {
        yield 'empty' => [''];
        yield 'yes' => ['yes'];
        yield 'no' => ['no'];
        yield 'on' => ['on'];
        yield 'two' => ['2'];
        yield 'padded' => [' true'];
    }

    #[DataProvider('invalidBools')]
    public function testBoolRejectsAnythingElse(string $raw): void
    {
        $this->expectException(EnvException::class);
        $this->expectExceptionMessage('"B" must be one of true, false, 1, 0');

        (new Env(['B' => $raw]))->bool('B');
    }

    public function testBoolDefaultFalseIsUsedWhenMissing(): void
    {
        self::assertFalse((new Env())->bool('B', false));
    }

    public function testHasAndGet(): void
    {
        $env = new Env(['A' => '', 'B' => 'x']);

        self::assertTrue($env->has('A'));
        self::assertFalse($env->has('C'));
        self::assertSame('', $env->get('A'));
        self::assertNull($env->get('C'));
    }

    public function testInvalidValueMessageDoesNotContainValue(): void
    {
        try {
            (new Env(['DB_PORT' => 'p4ssw0rd']))->int('DB_PORT');
            self::fail('Expected an EnvException.');
        } catch (EnvException $e) {
            self::assertStringNotContainsString('p4ssw0rd', $e->getMessage());
        }
    }
}
