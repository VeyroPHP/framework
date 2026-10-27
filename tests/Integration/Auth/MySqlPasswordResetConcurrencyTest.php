<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use VeyroPHP\Auth\Passwords\PasswordBroker;

/**
 * Runs only when VEYROPHP_TEST_MYSQL_HOST is set (see MySqlDatabaseTest).
 */
#[CoversClass(PasswordBroker::class)]
final class MySqlPasswordResetConcurrencyTest extends PasswordResetConcurrencyTestCase
{
    protected static function settings(): array
    {
        $host = getenv('VEYROPHP_TEST_MYSQL_HOST');

        if (!is_string($host) || $host === '' || !in_array('mysql', \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('Set VEYROPHP_TEST_MYSQL_HOST (and pdo_mysql) to run these tests.');
        }

        return [
            'driver' => 'mysql',
            'host' => $host,
            'port' => getenv('VEYROPHP_TEST_MYSQL_PORT') ?: '3306',
            'database' => getenv('VEYROPHP_TEST_MYSQL_DATABASE') ?: 'veyrophp_test',
            'username' => getenv('VEYROPHP_TEST_MYSQL_USERNAME') ?: 'root',
            'password' => getenv('VEYROPHP_TEST_MYSQL_PASSWORD') ?: '',
        ];
    }
}
