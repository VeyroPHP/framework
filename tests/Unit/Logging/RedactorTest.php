<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Unit\Logging;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;
use VeyroPHP\Logging\RedactingLogger;
use VeyroPHP\Logging\Redactor;

#[CoversClass(Redactor::class)]
#[CoversClass(RedactingLogger::class)]
final class RedactorTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function sensitiveKeys(): iterable
    {
        foreach (['password', 'PASSWORD', 'user_password', 'userPassword', 'db-passwd', 'pwd', 'client_secret', 'access_token', 'api_key', 'X-Api-Key', 'Authorization', 'cookie', 'session_id', 'credit_card', 'card_number', 'cvv', 'private_key', 'aws_access_key', 'APP_KEY', 'pass', 'dsn', 'jwt', 'bindings', 'dbpwd'] as $key) {
            yield $key => [$key];
        }
    }

    #[DataProvider('sensitiveKeys')]
    public function testDetectsSensitiveKeys(string $key): void
    {
        self::assertTrue((new Redactor())->isSensitive($key));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function harmlessKeys(): iterable
    {
        foreach (['user', 'email', 'order_id', 'author', 'key', 'exception', 'path'] as $key) {
            yield $key => [$key];
        }
    }

    #[DataProvider('harmlessKeys')]
    public function testKeepsHarmlessKeys(string $key): void
    {
        self::assertFalse((new Redactor())->isSensitive($key));
    }

    public function testRedactsNestedArraysAndKeepsStructure(): void
    {
        $result = (new Redactor())->redact([
            'user' => 'ravi',
            'password' => 'x',
            'request' => ['headers' => ['Authorization' => 'Bearer abc', 'Accept' => 'json']],
            'list' => [['token' => 't'], ['id' => 1]],
            'credentials' => ['password' => 'y'],
        ]);

        self::assertSame([
            'user' => 'ravi',
            'password' => '[REDACTED]',
            'request' => ['headers' => ['Authorization' => '[REDACTED]', 'Accept' => 'json']],
            'list' => [['token' => '[REDACTED]'], ['id' => 1]],
            'credentials' => ['password' => '[REDACTED]'],
        ], $result);
    }

    public function testRedactsCredentialsInsideValues(): void
    {
        $result = (new Redactor())->redact([
            'header' => 'Bearer eyJhbGciOi.payload.sig',
            'raw' => "GET / HTTP/1.1\r\nAuthorization: Basic dXNlcjpwYXNz\r\nAccept: */*",
            'note' => 'Basic plan, bearer of good news',
            'list' => ['curl -H "Authorization: Bearer abc123"'],
        ]);

        self::assertSame([
            'header' => 'Bearer [REDACTED]',
            'raw' => "GET / HTTP/1.1\r\nAuthorization: [REDACTED]\r\nAccept: */*",
            'note' => 'Basic plan, bearer of good news',
            'list' => ['curl -H "Authorization: [REDACTED]'],
        ], $result);
    }

    public function testRedactsWholeArrayUnderSensitiveKey(): void
    {
        self::assertSame(['session' => '[REDACTED]'], (new Redactor())->redact(['session' => ['id' => 'abc', 'user' => 1]]));
    }

    public function testAdditionalWords(): void
    {
        $redactor = new Redactor(['Aadhaar-Number', '']);

        self::assertTrue($redactor->isSensitive('aadhaar_number'));
        self::assertFalse($redactor->isSensitive('name'));
        self::assertTrue($redactor->isSensitive('password'));
    }

    public function testRedactingLoggerPassesRedactedContextToInnerLogger(): void
    {
        $inner = new class extends AbstractLogger {
            /** @var list<array{mixed, string, array<array-key, mixed>}> */
            public array $records = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = [$level, (string) $message, $context];
            }
        };

        (new RedactingLogger($inner))->warning('hi', ['token' => 'abc', 'id' => 7]);

        self::assertSame([['warning', 'hi', ['token' => '[REDACTED]', 'id' => 7]]], $inner->records);
    }
}
