<?php

declare(strict_types=1);

// A SQLite PDO that can pretend the connection broke, for the reconnect tests.

namespace VeyroPHP\Tests\Fixtures\Database;

use PDO;
use PDOException;
use PDOStatement;

final class FlakyPdo extends PDO
{
    /** Number of prepare() calls that fail with "server has gone away". */
    public static int $failPrepares = 0;

    /** Number of execute() calls that fail with "server has gone away". */
    public static int $failExecutes = 0;

    /** Number of beginTransaction() calls that fail with "server has gone away". */
    public static int $failBegins = 0;

    public static function reset(): void
    {
        self::$failPrepares = 0;
        self::$failExecutes = 0;
        self::$failBegins = 0;
    }

    public function __construct(string $file)
    {
        parent::__construct('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->setAttribute(PDO::ATTR_STATEMENT_CLASS, [FlakyStatement::class, []]);
    }

    public static function goneAway(): PDOException
    {
        $e = new PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away');
        $e->errorInfo = ['HY000', 2006, 'MySQL server has gone away'];

        return $e;
    }

    /**
     * @param array<int, mixed> $options
     */
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if (self::$failPrepares > 0) {
            self::$failPrepares--;

            throw self::goneAway();
        }

        return parent::prepare($query, $options);
    }

    public function beginTransaction(): bool
    {
        if (self::$failBegins > 0) {
            self::$failBegins--;

            throw self::goneAway();
        }

        return parent::beginTransaction();
    }
}

class FlakyStatement extends PDOStatement
{
    protected function __construct() {}

    /**
     * @param array<array-key, mixed>|null $params
     */
    public function execute(?array $params = null): bool
    {
        if (FlakyPdo::$failExecutes > 0) {
            FlakyPdo::$failExecutes--;

            throw FlakyPdo::goneAway();
        }

        return parent::execute($params);
    }
}
