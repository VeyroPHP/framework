<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Unit\Testing;

use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\TestCase;
use VeyroPHP\Database\Connection\Connection;
use VeyroPHP\Database\Dialect\SqliteDialect;
use VeyroPHP\Testing\RollsBackTransactions;

/**
 * The trait's #[Before]/#[After] hooks run in a real PHPUnit test: the first test's
 * row is gone in the second.
 */
final class RollsBackTransactionsHooksTest extends TestCase
{
    use RollsBackTransactions;

    private static ?Connection $connection = null;

    private static function connection(): Connection
    {
        if (self::$connection === null) {
            self::$connection = new Connection('hooks', new SqliteDialect(), static fn(): \PDO => new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]));
            self::$connection->statement('CREATE TABLE notes (id INTEGER PRIMARY KEY, body TEXT)');
        }

        return self::$connection;
    }

    protected function connectionsToRollBack(): array
    {
        return [self::connection()];
    }

    public function testWritesInsideTheTestTransaction(): void
    {
        self::assertSame(1, self::connection()->transactionLevel(), 'the hook began a transaction');
        self::connection()->table('notes')->insert(['body' => 'temporary']);
        self::assertEquals(1, self::connection()->selectOne('SELECT COUNT(*) AS n FROM notes')['n'] ?? null);
    }

    #[Depends('testWritesInsideTheTestTransaction')]
    public function testTheNextTestStartsClean(): void
    {
        self::assertEquals(0, self::connection()->selectOne('SELECT COUNT(*) AS n FROM notes')['n'] ?? null);
    }
}
