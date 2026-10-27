<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use VeyroPHP\Auth\Passwords\PasswordBroker;

/**
 * Runs only when VEYROPHP_TEST_PGSQL_HOST is set (see PostgresDatabaseTest).
 */
#[CoversClass(PasswordBroker::class)]
final class PostgresPasswordResetConcurrencyTest extends PasswordResetConcurrencyTestCase
{
    protected static function settings(): array
    {
        $host = getenv('VEYROPHP_TEST_PGSQL_HOST');

        if (!is_string($host) || $host === '' || !in_array('pgsql', \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('Set VEYROPHP_TEST_PGSQL_HOST (and pdo_pgsql) to run these tests.');
        }

        return [
            'driver' => 'pgsql',
            'host' => $host,
            'port' => getenv('VEYROPHP_TEST_PGSQL_PORT') ?: '5432',
            'database' => getenv('VEYROPHP_TEST_PGSQL_DATABASE') ?: 'veyrophp_test',
            'username' => getenv('VEYROPHP_TEST_PGSQL_USERNAME') ?: 'postgres',
            'password' => getenv('VEYROPHP_TEST_PGSQL_PASSWORD') ?: '',
        ];
    }
}
