<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Unit\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use VeyroPHP\Database\Connection\Connection;
use VeyroPHP\Database\Dialect\SqliteDialect;
use VeyroPHP\Database\Exception\DatabaseException;
use VeyroPHP\Database\Query\Builder;

/**
 * Builder::constrain(), used by the ORM for global scopes and soft deletes: the
 * condition is ANDed with every where clause, including ones added after it, and it
 * is never the "where clause" update() and delete() insist on (DB-1).
 */
#[CoversClass(Builder::class)]
final class ConstrainedQueryTest extends TestCase
{
    private static function articles(): Builder
    {
        return (new Connection('test', new SqliteDialect(), static function (): never {
            throw new \LogicException('reached the database');
        }))->table('articles');
    }

    private static function team(Builder $query): Builder
    {
        return $query->constrain(static fn(Builder $q) => $q->where('team_id', 7)->orWhere('shared', true));
    }

    public function testAConstraintIsGroupedAgainstLaterOrWheres(): void
    {
        $query = self::team(self::articles())->where('title', 'a')->orWhere('title', 'b');

        self::assertSame(
            'select * from "articles" where ("title" = ? or "title" = ?) and ("team_id" = ? or "shared" = ?)',
            $query->toSql(),
        );
        self::assertSame(['a', 'b', 7, true], $query->getBindings());
    }

    public function testConstraintsAloneAddOnlyTheirOwnCondition(): void
    {
        $query = self::articles()->constrain(static fn(Builder $q) => $q->whereNull('deleted_at'));

        self::assertSame('select * from "articles" where ("deleted_at" is null)', $query->toSql());
        self::assertSame('select * from "articles"', self::articles()->constrain(static fn(Builder $q) => $q)->toSql(), 'an empty constraint adds nothing');
    }

    public function testAConstraintIsNotTheWhereClauseUpdateAndDeleteNeed(): void
    {
        foreach (['delete' => static fn(Builder $q): int => $q->delete(), 'update' => static fn(Builder $q): int => $q->update(['title' => 'x'])] as $operation => $run) {
            try {
                $run(self::team(self::articles()));
                self::fail($operation . ' ran with only a constraint');
            } catch (DatabaseException $e) {
                self::assertStringContainsString('Refusing to ' . $operation, $e->getMessage());
            }
        }
    }

    public function testConstrainedWritesKeepTheConstraint(): void
    {
        $query = self::team(self::articles())->where('status', 'draft')->orWhere('id', 5);
        [$sql] = (new \VeyroPHP\Database\Query\Compiler(new SqliteDialect()))->delete($query);

        self::assertSame('delete from "articles" where ("status" = ? or "id" = ?) and ("team_id" = ? or "shared" = ?)', $sql);

        try {
            self::team(self::articles())->deleteAll();
            self::fail('deleteAll() did not reach the database');
        } catch (\LogicException $e) {
            self::assertSame('reached the database', $e->getMessage(), 'deleteAll() still means "every row the constraints allow"');
        }
    }

    public function testCopiesKeepTheConstraint(): void
    {
        $query = self::team(self::articles());

        self::assertStringContainsString('"team_id" = ?', $query->withoutPaging()->toSql());
        self::assertStringContainsString('"team_id" = ?', $query->withGroupedWheres()->where('id', 1)->toSql());
    }
}
