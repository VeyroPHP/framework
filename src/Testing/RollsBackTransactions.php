<?php

declare(strict_types=1);

namespace VeyroPHP\Testing;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use VeyroPHP\Database\Connection\Connection;

/**
 * For PHPUnit tests against a real database: each test runs inside a transaction
 * that is rolled back afterwards, so tests leave no rows behind and need no
 * re-migration. Transactions of the code under test become savepoints.
 *
 *     final class OrderTest extends TestCase
 *     {
 *         use RollsBackTransactions;
 *
 *         protected function connectionsToRollBack(): array
 *         {
 *             return [$this->app->container()->make(Connection::class)];
 *         }
 *     }
 *
 * Limits: statements that commit implicitly (CREATE/ALTER/DROP TABLE on MySQL and
 * MariaDB) end the transaction, so keep schema changes in migrations run once before
 * the tests. Other connections (a queue worker, a second process) do not see the
 * uncommitted rows.
 */
trait RollsBackTransactions
{
    /**
     * @return list<Connection>
     */
    abstract protected function connectionsToRollBack(): array;

    #[Before]
    protected function beginTestTransactions(): void
    {
        foreach ($this->connectionsToRollBack() as $connection) {
            $connection->beginTransaction();
        }
    }

    #[After]
    protected function rollBackTestTransactions(): void
    {
        foreach ($this->connectionsToRollBack() as $connection) {
            while ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
        }
    }
}
