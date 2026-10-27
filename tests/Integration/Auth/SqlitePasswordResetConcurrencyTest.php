<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use VeyroPHP\Auth\Passwords\PasswordBroker;

#[CoversClass(PasswordBroker::class)]
final class SqlitePasswordResetConcurrencyTest extends PasswordResetConcurrencyTestCase
{
    protected static function settings(): array
    {
        return ['driver' => 'sqlite', 'database' => ':memory:'];
    }
}
