<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VeyroPHP\Config\Config;
use VeyroPHP\Config\Exception\ConfigException;
use VeyroPHP\Exception\VeyroPHPException;

#[CoversClass(Config::class)]
#[CoversClass(ConfigException::class)]
final class ConfigTest extends TestCase
{
    private Config $config;

    protected function setUp(): void
    {
        $this->config = new Config([
            'app' => [
                'name' => 'VeyroPHP',
                'debug' => false,
                'port' => 8080,
                'ratio' => 0.75,
                'timeout' => 5,
                'nullable' => null,
                'empty' => '',
                'zero' => 0,
                'locales' => ['en', 'hi'],
            ],
            'database' => [
                'connections' => [
                    'mysql' => ['host' => '127.0.0.1', 'password' => 's3cr3t-value'],
                ],
            ],
            'servers' => ['alpha', 'beta'],
        ]);
    }

    public function testReadsNestedValuesWithDotNotation(): void
    {
        self::assertSame('127.0.0.1', $this->config->get('database.connections.mysql.host'));
        self::assertSame(['host' => '127.0.0.1', 'password' => 's3cr3t-value'], $this->config->get('database.connections.mysql'));
    }

    public function testReadsListItemsByIndex(): void
    {
        self::assertSame('beta', $this->config->get('servers.1'));
        self::assertSame('hi', $this->config->string('app.locales.1'));
    }

    public function testGetReturnsDefaultOnlyForMissingKeys(): void
    {
        self::assertSame('fallback', $this->config->get('app.missing', 'fallback'));
        self::assertNull($this->config->get('app.nullable', 'fallback'));
        self::assertFalse($this->config->get('app.debug', true));
        self::assertSame('', $this->config->get('app.empty', 'fallback'));
    }

    public function testHasDistinguishesNullFromMissing(): void
    {
        self::assertTrue($this->config->has('app.nullable'));
        self::assertTrue($this->config->has('app'));
        self::assertFalse($this->config->has('app.missing'));
        self::assertFalse($this->config->has('missing'));
    }

    public function testTraversingIntoScalarIsMissing(): void
    {
        self::assertFalse($this->config->has('app.name.first'));
        self::assertSame('d', $this->config->get('app.port.x', 'd'));
    }

    public function testTypedGettersReturnValues(): void
    {
        self::assertSame('VeyroPHP', $this->config->string('app.name'));
        self::assertSame(8080, $this->config->int('app.port'));
        self::assertSame(0.75, $this->config->float('app.ratio'));
        self::assertFalse($this->config->bool('app.debug'));
        self::assertSame(['en', 'hi'], $this->config->array('app.locales'));
        self::assertSame('', $this->config->string('app.empty'));
        self::assertSame(0, $this->config->int('app.zero'));
    }

    public function testFloatAcceptsIntegers(): void
    {
        self::assertSame(5.0, $this->config->float('app.timeout'));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function wrongTypes(): iterable
    {
        yield 'int as string' => ['string', 'app.port', 'must be of type string, int given'];
        yield 'numeric string is not int' => ['int', 'app.name', 'must be of type int, string given'];
        yield 'float is not int' => ['int', 'app.ratio', 'must be of type int, float given'];
        yield 'int is not bool' => ['bool', 'app.zero', 'must be of type bool, int given'];
        yield 'string is not float' => ['float', 'app.name', 'must be of type float, string given'];
        yield 'string is not array' => ['array', 'app.name', 'must be of type array, string given'];
        yield 'null is not string' => ['string', 'app.nullable', 'must be of type string, null given'];
        yield 'array is not string' => ['string', 'app.locales', 'must be of type string, array given'];
    }

    #[DataProvider('wrongTypes')]
    public function testTypedGettersRejectWrongTypes(string $getter, string $key, string $message): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage(sprintf('Config key "%s" %s.', $key, $message));

        $this->callGetter($getter, $key);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function getters(): iterable
    {
        foreach (['string', 'int', 'float', 'bool', 'array'] as $getter) {
            yield $getter => [$getter];
        }
    }

    #[DataProvider('getters')]
    public function testTypedGettersRejectMissingKeys(string $getter): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('Config key "app.missing" is not defined.');

        $this->callGetter($getter, 'app.missing');
    }

    public function testErrorMessagesDoNotContainValues(): void
    {
        try {
            $this->config->int('database.connections.mysql.password');
            self::fail('Expected a ConfigException.');
        } catch (ConfigException $e) {
            self::assertStringNotContainsString('s3cr3t-value', $e->getMessage());
            self::assertInstanceOf(VeyroPHPException::class, $e);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidKeys(): iterable
    {
        yield 'empty' => [''];
        yield 'leading dot' => ['.app'];
        yield 'trailing dot' => ['app.'];
        yield 'double dot' => ['app..name'];
        yield 'only dot' => ['.'];
    }

    #[DataProvider('invalidKeys')]
    public function testRejectsInvalidKeys(string $key): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('Invalid config key');

        $this->config->get($key);
    }

    #[DataProvider('invalidKeys')]
    public function testHasRejectsInvalidKeys(string $key): void
    {
        $this->expectException(ConfigException::class);

        $this->config->has($key);
    }

    public function testAllReturnsEveryItem(): void
    {
        $config = new Config(['a' => 1, 'b' => ['c' => 2]]);

        self::assertSame(['a' => 1, 'b' => ['c' => 2]], $config->all());
    }

    public function testEmptyConfig(): void
    {
        $config = new Config();

        self::assertSame([], $config->all());
        self::assertFalse($config->has('anything'));
    }

    private function callGetter(string $getter, string $key): void
    {
        match ($getter) {
            'string' => $this->config->string($key),
            'int' => $this->config->int($key),
            'float' => $this->config->float($key),
            'bool' => $this->config->bool($key),
            'array' => $this->config->array($key),
            default => self::fail("Unknown getter {$getter}"),
        };
    }
}
