<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Unit\Database;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VeyroPHP\Database\Connection\Connection;
use VeyroPHP\Database\Dialect\Dialect;
use VeyroPHP\Database\Dialect\MySqlDialect;
use VeyroPHP\Database\Dialect\PostgresDialect;
use VeyroPHP\Database\Dialect\SqliteDialect;
use VeyroPHP\Database\Exception\DatabaseException;
use VeyroPHP\Database\Query\Builder;
use VeyroPHP\Database\Query\Compiler;
use VeyroPHP\Database\Query\Expression;

/**
 * SQL generation only. The connector throws, which proves no connection is opened.
 */
#[CoversClass(Builder::class)]
#[CoversClass(Compiler::class)]
#[CoversClass(Dialect::class)]
#[CoversClass(MySqlDialect::class)]
#[CoversClass(PostgresDialect::class)]
#[CoversClass(SqliteDialect::class)]
#[CoversClass(Expression::class)]
#[CoversClass(DatabaseException::class)]
final class QueryCompilationTest extends TestCase
{
    private static function connection(Dialect $dialect = new SqliteDialect()): Connection
    {
        return new Connection('test', $dialect, static function (): never {
            throw new \LogicException('SQL compilation must not open a connection.');
        });
    }

    private static function table(string $table = 'users', Dialect $dialect = new SqliteDialect()): Builder
    {
        return self::connection($dialect)->table($table);
    }

    // Identifier quoting

    /**
     * @return iterable<string, array{Dialect, string, string}>
     */
    public static function quoting(): iterable
    {
        $mysql = new MySqlDialect();
        $pgsql = new PostgresDialect();
        $sqlite = new SqliteDialect();

        yield 'mysql column' => [$mysql, 'email', '`email`'];
        yield 'mysql table.column' => [$mysql, 'users.email', '`users`.`email`'];
        yield 'mysql star' => [$mysql, '*', '*'];
        yield 'mysql table.star' => [$mysql, 'users.*', '`users`.*'];
        yield 'mysql alias' => [$mysql, 'users.email as address', '`users`.`email` as `address`'];
        yield 'mysql alias uppercase AS' => [$mysql, 'email AS address', '`email` as `address`'];
        yield 'pgsql schema.table.column' => [$pgsql, 'public.users.id', '"public"."users"."id"'];
        yield 'sqlite column' => [$sqlite, 'name', '"name"'];
    }

    #[DataProvider('quoting')]
    public function testQuotesIdentifiers(Dialect $dialect, string $identifier, string $expected): void
    {
        self::assertSame($expected, $dialect->quoteIdentifier($identifier));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidIdentifiers(): iterable
    {
        yield 'injection' => ['email; drop table users'];
        yield 'quote' => ['email"'];
        yield 'backtick' => ['email`'];
        yield 'comment' => ['email--'];
        yield 'space' => ['first name'];
        yield 'function' => ['count(*)'];
        yield 'empty' => [''];
        yield 'leading digit' => ['1col'];
        yield 'empty segment' => ['users..email'];
        yield 'star in middle' => ['*.email'];
        yield 'too many segments' => ['a.b.c.d'];
        yield 'bad alias' => ['email as "x"'];
        yield 'trailing newline' => ["email\n"];
        yield 'newline in a segment' => ["users\n.email"];
    }

    #[DataProvider('invalidIdentifiers')]
    public function testRejectsInvalidIdentifiers(string $identifier): void
    {
        foreach ([new MySqlDialect(), new PostgresDialect(), new SqliteDialect()] as $dialect) {
            try {
                $dialect->quoteIdentifier($identifier);
                self::fail(sprintf('%s accepted "%s".', $dialect->name(), $identifier));
            } catch (DatabaseException $e) {
                self::assertStringContainsString('Invalid SQL identifier', $e->getMessage());
            }
        }
    }

    public function testExpressionIsNotQuoted(): void
    {
        self::assertSame('count(*) as total', (new MySqlDialect())->quoteIdentifier(new Expression('count(*) as total')));
    }

    // Select

    public function testSimpleSelect(): void
    {
        self::assertSame('select * from "users"', self::table()->toSql());
        self::assertSame('select "id", "email" as "address" from "users"', self::table()->select('id', 'email as address')->toSql());
        self::assertSame('select distinct "city" from "users"', self::table()->select('city')->distinct()->toSql());
    }

    public function testWhereClausesAndBindings(): void
    {
        $date = new DateTimeImmutable('2026-01-01');
        $query = self::table()
            ->where('active', true)
            ->where('age', '>=', 18)
            ->orWhere('role', 'admin')
            ->whereIn('city', ['Alwar', 'Jaipur'])
            ->whereNotIn('id', [1])
            ->whereNull('deleted_at')
            ->whereNotNull('email')
            ->whereBetween('created_at', [$date, $date])
            ->whereColumn('updated_at', '>', 'created_at');

        self::assertSame(
            'select * from "users" where "active" = ? and "age" >= ? or "role" = ? and "city" in (?, ?) and "id" not in (?)'
            . ' and "deleted_at" is null and "email" is not null and "created_at" between ? and ? and "updated_at" > "created_at"',
            $query->toSql(),
        );
        self::assertSame([true, 18, 'admin', 'Alwar', 'Jaipur', 1, $date, $date], $query->getBindings());
    }

    public function testNestedWhereGroups(): void
    {
        $query = self::table()
            ->where('active', true)
            ->where(static function (Builder $q): void {
                $q->where('role', 'admin')->orWhere(static fn(Builder $inner) => $inner->where('age', '>', 60)->whereNotNull('verified_at'));
            });

        self::assertSame(
            'select * from "users" where "active" = ? and ("role" = ? or ("age" > ? and "verified_at" is not null))',
            $query->toSql(),
        );
        self::assertSame([true, 'admin', 60], $query->getBindings());
    }

    public function testEmptyNestedGroupIsSkipped(): void
    {
        self::assertSame('select * from "users" where "a" = ?', self::table()->where('a', 1)->where(static fn(Builder $q) => $q)->toSql());
    }

    public function testEmptyInLists(): void
    {
        self::assertSame('select * from "users" where 1 = 0', self::table()->whereIn('id', [])->toSql());
        self::assertSame('select * from "users" where 1 = 1', self::table()->whereNotIn('id', [])->toSql());
    }

    public function testRawWhereKeepsBindingOrder(): void
    {
        $query = self::table()->where('a', 1)->whereRaw('lower(email) = ?', ['x@y.z'])->orWhereRaw('score > ?', [5])->where('b', 2);

        self::assertSame('select * from "users" where "a" = ? and (lower(email) = ?) or (score > ?) and "b" = ?', $query->toSql());
        self::assertSame([1, 'x@y.z', 5, 2], $query->getBindings());
    }

    public function testJoinsGroupingHavingOrderingAndPaging(): void
    {
        $query = self::table('users', new MySqlDialect())
            ->select('users.id', new Expression('count(`posts`.`id`) as `posts_count`'))
            ->leftJoin('posts', 'posts.user_id', '=', 'users.id')
            ->join('teams', 'teams.id', '=', 'users.team_id')
            ->groupBy('users.id')
            ->having('posts_count', '>', 2)
            ->orderByDesc('posts_count')
            ->orderBy('users.id')
            ->forPage(3, 20);

        self::assertSame(
            'select `users`.`id`, count(`posts`.`id`) as `posts_count` from `users`'
            . ' left join `posts` on `posts`.`user_id` = `users`.`id` inner join `teams` on `teams`.`id` = `users`.`team_id`'
            . ' group by `users`.`id` having `posts_count` > ? order by `posts_count` desc, `users`.`id` asc limit 20 offset 40',
            $query->toSql(),
        );
        self::assertSame([2], $query->getBindings());
    }

    public function testOffsetWithoutLimitPerDialect(): void
    {
        self::assertSame('select * from `t` limit 18446744073709551615 offset 5', self::table('t', new MySqlDialect())->offset(5)->toSql());
        self::assertSame('select * from "t" limit -1 offset 5', self::table('t', new SqliteDialect())->offset(5)->toSql());
        self::assertSame('select * from "t" offset 5', self::table('t', new PostgresDialect())->offset(5)->toSql());
        self::assertSame('select * from "t" limit 10', self::table('t')->limit(10)->offset(0)->toSql());
    }

    // Write statements (compiled through the Compiler directly)

    public function testInsertCompilation(): void
    {
        $compiler = new Compiler(new PostgresDialect());

        [$sql, $bindings] = $compiler->insert(self::table('users', new PostgresDialect()), [
            ['name' => 'Ravi', 'age' => 30],
            ['name' => 'Asha', 'age' => 25],
        ]);

        self::assertSame('insert into "users" ("name", "age") values (?, ?), (?, ?)', $sql);
        self::assertSame(['Ravi', 30, 'Asha', 25], $bindings);
    }

    public function testUpsertCompilationPerDialect(): void
    {
        $rows = [['email' => 'a@x.test', 'name' => 'A']];

        [$mysql] = (new Compiler(new MySqlDialect()))->upsert(self::table('users', new MySqlDialect()), $rows, ['email'], ['name']);
        [$pgsql] = (new Compiler(new PostgresDialect()))->upsert(self::table('users', new PostgresDialect()), $rows, ['email'], ['name']);

        self::assertSame('insert into `users` (`email`, `name`) values (?, ?) on duplicate key update `name` = values(`name`)', $mysql);
        self::assertSame('insert into "users" ("email", "name") values (?, ?) on conflict ("email") do update set "name" = excluded."name"', $pgsql);

        [$sqlite] = (new Compiler(new SqliteDialect()))->upsert(self::table('users', new SqliteDialect()), $rows, ['email'], ['name']);
        self::assertSame('insert into "users" ("email", "name") values (?, ?) on conflict ("email") do update set "name" = excluded."name"', $sqlite);
    }

    public function testUpdateAndDeleteCompilation(): void
    {
        $compiler = new Compiler(new SqliteDialect());

        [$update, $updateBindings] = $compiler->update(self::table()->where('id', 5), ['name' => 'New', 'visits' => new Expression('"visits" + 1')]);
        [$delete, $deleteBindings] = $compiler->delete(self::table()->where('id', 5));

        self::assertSame('update "users" set "name" = ?, "visits" = "visits" + 1 where "id" = ?', $update);
        self::assertSame(['New', 5], $updateBindings);
        self::assertSame('delete from "users" where "id" = ?', $delete);
        self::assertSame([5], $deleteBindings);
    }

    public function testCountQueryDropsOrderingAndPaging(): void
    {
        [$sql] = (new Compiler(new SqliteDialect()))->aggregate(self::table()->where('a', 1)->orderBy('id')->limit(5), 'count', '*');

        self::assertSame('select count(*) as "aggregate" from "users" where "a" = ?', $sql);
    }

    public function testCountWithGroupingUsesSubquery(): void
    {
        [$sql] = (new Compiler(new SqliteDialect()))->aggregate(self::table()->select('city')->groupBy('city'), 'count', '*');

        self::assertSame('select count(*) as "aggregate" from (select "city" from "users" group by "city") as "aggregate_table"', $sql);
    }

    // Safety rules

    public function testWhereWithNullValueIsRejected(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('use whereNull()');

        self::table()->where('deleted_at', null);
    }

    public function testWhereWithOperatorAndNullIsRejected(): void
    {
        $this->expectException(DatabaseException::class);

        self::table()->where('deleted_at', '=', null);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badOperators(): iterable
    {
        yield 'injection' => ['= 1 or 1 = 1 --'];
        yield 'semicolon' => [';'];
        yield 'regexp' => ['regexp'];
        yield 'is' => ['is'];
    }

    #[DataProvider('badOperators')]
    public function testRejectsUnknownOperators(string $operator): void
    {
        $this->expectException(DatabaseException::class);

        self::table()->where('a', $operator, 1);
    }

    public function testOperatorsAreCaseInsensitive(): void
    {
        self::assertSame('select * from "users" where "name" not like ?', self::table()->where('name', 'NOT LIKE', '%x%')->toSql());
    }

    public function testRejectsArrayAsSingleValue(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('use whereIn()');

        self::table()->where('id', [1, 2]);
    }

    public function testRejectsInvalidOrderDirection(): void
    {
        $this->expectException(DatabaseException::class);

        self::table()->orderBy('id', 'desc; drop table users');
    }

    public function testRejectsNegativeLimitOffsetAndPages(): void
    {
        foreach ([fn() => self::table()->limit(-1), fn() => self::table()->offset(-1), fn() => self::table()->forPage(0, 10), fn() => self::table()->forPage(1, 0)] as $call) {
            try {
                $call();
                self::fail('Expected DatabaseException.');
            } catch (DatabaseException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testUpdateAndDeleteRequireWhere(): void
    {
        foreach ([fn() => self::table()->update(['a' => 1]), fn() => self::table()->delete()] as $call) {
            try {
                $call();
                self::fail('Expected DatabaseException.');
            } catch (DatabaseException $e) {
                self::assertStringContainsString('without a where clause', $e->getMessage());
            }
        }
    }

    public function testUpdateRejectsJoinsAndPaging(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('does not support joins, order by, limit or offset');

        (new Compiler(new SqliteDialect()))->update(self::table()->where('a', 1)->limit(1), ['a' => 2]);
    }

    public function testBulkInsertRequiresSameColumns(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('same columns');

        self::table()->insert([['a' => 1], ['b' => 2]]);
    }

    public function testInsertRejectsEmptyAndNonAssociativeRows(): void
    {
        foreach ([[], [[]], [[1, 2]]] as $rows) {
            try {
                self::table()->insert($rows);
                self::fail('Expected DatabaseException.');
            } catch (DatabaseException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testMissingTable(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('No table given');

        (new Builder(self::connection()))->toSql();
    }

    public function testBuildingQueriesDoesNotConnect(): void
    {
        $connection = self::connection();
        $connection->table('users')->where('a', 1)->toSql();

        self::assertFalse($connection->isConnected());
    }
}
