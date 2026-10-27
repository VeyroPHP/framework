<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Integration\Orm;

use PHPUnit\Framework\Attributes\CoversClass;
use VeyroPHP\Orm\ModelQuery;
use VeyroPHP\Orm\Orm;
use VeyroPHP\Orm\Relation;

/**
 * Runs only when VEYROPHP_TEST_MYSQL_HOST is set (see MySqlDatabaseTest).
 */
#[CoversClass(Orm::class)]
#[CoversClass(ModelQuery::class)]
#[CoversClass(Relation::class)]
final class MySqlOrmFeaturesTest extends OrmFeaturesTestCase
{
    protected static function settings(): array
    {
        $host = getenv('VEYROPHP_TEST_MYSQL_HOST');

        if (!is_string($host) || $host === '' || !in_array('mysql', \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('Set VEYROPHP_TEST_MYSQL_HOST (and pdo_mysql) to run MySQL ORM tests.');
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
