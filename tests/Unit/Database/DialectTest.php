<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Unit\Database;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use VeyroPHP\Database\Dialect\Dialect;
use VeyroPHP\Database\Dialect\MySqlDialect;
use VeyroPHP\Database\Dialect\PostgresDialect;
use VeyroPHP\Database\Dialect\SqliteDialect;
use VeyroPHP\Database\Schema\Grammar\MySqlSchemaGrammar;
use VeyroPHP\Database\Schema\Grammar\PostgresSchemaGrammar;
use VeyroPHP\Database\Schema\Grammar\SqliteSchemaGrammar;

/**
 * DB-8: per-database choices come from the dialect, not from driver-name switches.
 */
#[CoversClass(Dialect::class)]
final class DialectTest extends TestCase
{
    public function testEachDialectBringsItsOwnSchemaGrammar(): void
    {
        self::assertInstanceOf(MySqlSchemaGrammar::class, (new MySqlDialect())->schemaGrammar());
        self::assertInstanceOf(PostgresSchemaGrammar::class, (new PostgresDialect())->schemaGrammar());
        self::assertInstanceOf(SqliteSchemaGrammar::class, (new SqliteDialect())->schemaGrammar());
    }

    public function testBooleansAreBoundAsIntegersExceptOnPostgres(): void
    {
        self::assertSame([1, PDO::PARAM_INT], (new MySqlDialect())->bindBoolean(true));
        self::assertSame([0, PDO::PARAM_INT], (new SqliteDialect())->bindBoolean(false));
        self::assertSame([true, PDO::PARAM_BOOL], (new PostgresDialect())->bindBoolean(true));
    }

    public function testDisplayNames(): void
    {
        self::assertSame(['MySQL', 'PostgreSQL', 'SQLite'], [(new MySqlDialect())->displayName(), (new PostgresDialect())->displayName(), (new SqliteDialect())->displayName()]);
    }
}
