<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Unit\Database;

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
use VeyroPHP\Database\Schema\Blueprint;
use VeyroPHP\Database\Schema\Grammar\MySqlSchemaGrammar;
use VeyroPHP\Database\Schema\Grammar\PostgresSchemaGrammar;
use VeyroPHP\Database\Schema\Grammar\SqliteSchemaGrammar;

/**
 * Row locks, JSON paths, full-text search and grouped filters: SQL only, no connection.
 */
#[CoversClass(Builder::class)]
#[CoversClass(Compiler::class)]
#[CoversClass(Dialect::class)]
final class QueryAdditionsCompilationTest extends TestCase
{
    private static function table(Dialect $dialect, string $table = 'users'): Builder
    {
        return (new Connection('test', $dialect, static function (): never {
            throw new \LogicException('SQL compilation must not open a connection.');
        }))->table($table);
    }

    /**
     * @return iterable<string, array{Dialect, string, string, string}>
     */
    public static function locks(): iterable
    {
        yield 'mysql update' => [new MySqlDialect(), 'update', 'select * from `users` where `id` = ? for update', ''];
        yield 'mysql update skip locked' => [new MySqlDialect(), 'skip', 'select * from `users` where `id` = ? for update skip locked', ''];
        yield 'mysql update nowait' => [new MySqlDialect(), 'nowait', 'select * from `users` where `id` = ? for update nowait', ''];
        yield 'mysql share' => [new MySqlDialect(), 'share', 'select * from `users` where `id` = ? lock in share mode', ''];
        yield 'pgsql update' => [new PostgresDialect(), 'update', 'select * from "users" where "id" = ? for update', ''];
        yield 'pgsql skip locked' => [new PostgresDialect(), 'skip', 'select * from "users" where "id" = ? for update skip locked', ''];
        yield 'pgsql share' => [new PostgresDialect(), 'share', 'select * from "users" where "id" = ? for share', ''];
        yield 'sqlite has no row locks' => [new SqliteDialect(), 'update', 'select * from "users" where "id" = ?', ''];
    }

    #[DataProvider('locks')]
    public function testRowLocks(Dialect $dialect, string $mode, string $sql): void
    {
        $query = self::table($dialect)->where('id', 1);
        match ($mode) {
            'update' => $query->lockForUpdate(),
            'skip' => $query->lockForUpdate(skipLocked: true),
            'nowait' => $query->lockForUpdate(noWait: true),
            'share' => $query->sharedLock(),
            default => throw new \LogicException('Unknown lock mode ' . $mode),
        };

        self::assertSame($sql, $query->toSql());
    }

    public function testLockComesAfterLimit(): void
    {
        self::assertSame('select * from "jobs" order by "id" asc limit 1 for update skip locked', self::table(new PostgresDialect(), 'jobs')->orderBy('id')->limit(1)->lockForUpdate(skipLocked: true)->toSql());
    }

    public function testSkipLockedAndNoWaitTogetherAreRejected(): void
    {
        $this->expectException(DatabaseException::class);
        self::table(new PostgresDialect())->lockForUpdate(skipLocked: true, noWait: true);
    }

    public function testCountAndExistsOfALockedQueryCarryNoLock(): void
    {
        $query = self::table(new PostgresDialect())->where('id', 1)->lockForUpdate();
        $compiler = new Compiler(new PostgresDialect());

        self::assertStringNotContainsString('for update', $compiler->aggregate($query, 'count', '*')[0]);
        self::assertStringNotContainsString('for update', $compiler->exists($query)[0]);
    }

    /**
     * @return iterable<string, array{Dialect, string, mixed, string, list<mixed>}>
     */
    public static function jsonPaths(): iterable
    {
        yield 'mysql text' => [new MySqlDialect(), 'address.city', 'Jaipur', 'select * from `docs` where json_unquote(json_extract(`meta`, ?)) = ?', ['$.address.city', 'Jaipur']];
        yield 'mysql array index' => [new MySqlDialect(), 'tags[1]', 'php', 'select * from `docs` where json_unquote(json_extract(`meta`, ?)) = ?', ['$.tags[1]', 'php']];
        yield 'pgsql text' => [new PostgresDialect(), 'address.city', 'Jaipur', 'select * from "docs" where ("meta" #>> ?::text[]) = ?', ['{address,city}', 'Jaipur']];
        yield 'pgsql number' => [new PostgresDialect(), 'stock', 5, 'select * from "docs" where ("meta" #>> ?::text[])::numeric = ?', ['{stock}', 5]];
        yield 'pgsql array index' => [new PostgresDialect(), 'tags[1]', 'php', 'select * from "docs" where ("meta" #>> ?::text[]) = ?', ['{tags,1}', 'php']];
        yield 'sqlite' => [new SqliteDialect(), 'address.city', 'Jaipur', 'select * from "docs" where json_extract("meta", ?) = ?', ['$.address.city', 'Jaipur']];
    }

    /**
     * @param list<mixed> $bindings
     */
    #[DataProvider('jsonPaths')]
    public function testJsonPathConditions(Dialect $dialect, string $path, mixed $value, string $sql, array $bindings): void
    {
        $query = self::table($dialect, 'docs')->whereJsonPath('meta', $path, '=', $value);

        self::assertSame($sql, $query->toSql());
        self::assertSame($bindings, $query->getBindings());
    }

    public function testJsonPathTwoArgumentFormMeansEquals(): void
    {
        self::assertSame('select * from "docs" where json_extract("meta", ?) = ?', self::table(new SqliteDialect(), 'docs')->whereJsonPath('meta', 'a', 'x')->toSql());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badPaths(): iterable
    {
        foreach (['', '$.a', 'a..b', "a') or 1=1 --", 'a.b c', 'a[x]', '[0]', 'a.', 'a{}'] as $path) {
            yield $path => [$path];
        }
    }

    #[DataProvider('badPaths')]
    public function testUnsafeJsonPathsAreRejected(string $path): void
    {
        $this->expectException(DatabaseException::class);
        self::table(new MySqlDialect(), 'docs')->whereJsonPath('meta', $path, '=', 'x');
    }

    public function testJsonPathRejectsBooleansAndNull(): void
    {
        $rejected = 0;

        foreach ([true, null, [1]] as $value) {
            try {
                self::table(new SqliteDialect(), 'docs')->whereJsonPath('meta', 'a', '=', $value);
            } catch (DatabaseException) {
                $rejected++;
            }
        }

        self::assertSame(3, $rejected);
    }

    public function testFullText(): void
    {
        $mysql = self::table(new MySqlDialect(), 'posts')->whereFullText(['title', 'body'], 'jaipur clinic');
        self::assertSame('select * from `posts` where match (`title`, `body`) against (? in natural language mode)', $mysql->toSql());
        self::assertSame(['jaipur clinic'], $mysql->getBindings());

        $pgsql = self::table(new PostgresDialect(), 'posts')->whereFullText(['title', 'body'], 'jaipur clinic');
        self::assertSame(
            'select * from "posts" where to_tsvector(\'simple\', coalesce("title", \'\') || \' \' || coalesce("body", \'\')) @@ plainto_tsquery(\'simple\', ?)',
            $pgsql->toSql(),
        );
    }

    public function testFullTextIsRefusedOnSqliteInsteadOfFakingIt(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('SQLite');
        self::table(new SqliteDialect(), 'posts')->whereFullText(['title'], 'x')->toSql();
    }

    public function testFullTextNeedsColumnsAndText(): void
    {
        $rejected = 0;

        foreach ([[[], 'x'], [['title'], '']] as [$columns, $text]) {
            try {
                self::table(new MySqlDialect(), 'posts')->whereFullText($columns, $text);
            } catch (DatabaseException) {
                $rejected++;
            }
        }

        self::assertSame(2, $rejected);
    }

    public function testWithGroupedWheresProtectsLaterConditionsFromOr(): void
    {
        $query = self::table(new SqliteDialect())->where('a', 1)->orWhere('b', 2)->withGroupedWheres()->whereNull('deleted_at');

        self::assertSame('select * from "users" where ("a" = ? or "b" = ?) and "deleted_at" is null', $query->toSql());
        self::assertSame([1, 2], $query->getBindings());
        self::assertSame('select * from "users" where "deleted_at" is null', self::table(new SqliteDialect())->withGroupedWheres()->whereNull('deleted_at')->toSql());
    }

    public function testFullTextIndexes(): void
    {
        $blueprint = new Blueprint('posts', true);
        $blueprint->id();
        $blueprint->text('title');
        $blueprint->text('body');
        $blueprint->fullText(['title', 'body']);

        $mysql = (new MySqlSchemaGrammar(new MySqlDialect()))->compileCreate($blueprint);
        self::assertContains('create fulltext index `posts_title_body_fulltext` on `posts` (`title`, `body`)', $mysql);

        $pgsql = (new PostgresSchemaGrammar(new PostgresDialect()))->compileCreate($blueprint);
        self::assertContains('create index "posts_title_body_fulltext" on "posts" using gin (to_tsvector(\'simple\', coalesce("title", \'\') || \' \' || coalesce("body", \'\')))', $pgsql);

        $this->expectException(DatabaseException::class);
        (new SqliteSchemaGrammar(new SqliteDialect()))->compileCreate($blueprint);
    }
}
