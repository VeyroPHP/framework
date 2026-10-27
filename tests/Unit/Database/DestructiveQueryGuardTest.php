<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Unit\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VeyroPHP\Database\Connection\Connection;
use VeyroPHP\Database\Dialect\SqliteDialect;
use VeyroPHP\Database\Exception\DatabaseException;
use VeyroPHP\Database\Query\Builder;

/**
 * DB-1: update() and delete() refuse queries without an effective condition, not
 * only queries where no where() method was called. The connector throws a
 * LogicException, so "reached the database" and "refused" are told apart without
 * a connection.
 */
#[CoversClass(Builder::class)]
final class DestructiveQueryGuardTest extends TestCase
{
    private static function users(): Builder
    {
        return (new Connection('test', new SqliteDialect(), static function (): never {
            throw new \LogicException('reached the database');
        }))->table('users');
    }

    /**
     * @return iterable<string, array{\Closure(Builder): Builder}>
     */
    public static function unfiltered(): iterable
    {
        yield 'no where at all' => [static fn(Builder $q): Builder => $q];
        yield 'empty group' => [static fn(Builder $q): Builder => $q->where(static fn(Builder $g) => $g)];
        yield 'empty or-group' => [static fn(Builder $q): Builder => $q->orWhere(static fn(Builder $g) => $g)];
        yield 'nested empty groups' => [static fn(Builder $q): Builder => $q->where(static fn(Builder $g) => $g->where(static fn(Builder $h) => $h)->orWhere(static fn(Builder $h) => $h))];
        yield 'several empty groups' => [static fn(Builder $q): Builder => $q->where(static fn(Builder $g) => $g)->orWhere(static fn(Builder $g) => $g)];
        yield 'filter loop with no filters' => [static function (Builder $q): Builder {
            $filters = self::requestFilters();

            return $q->where(static function (Builder $g) use ($filters): void {
                foreach ($filters as $column => $value) {
                    $g->where($column, $value);
                }
            });
        }];
    }

    /**
     * Filters a request asked for: none, as when every optional filter was left blank.
     *
     * @return array<string, string>
     */
    private static function requestFilters(): array
    {
        return [];
    }

    /**
     * @param \Closure(Builder): Builder $build
     */
    #[DataProvider('unfiltered')]
    public function testDeleteRefusesQueriesWithoutAnEffectiveCondition(\Closure $build): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('Refusing to delete without a where clause');
        $build(self::users())->delete();
    }

    /**
     * @param \Closure(Builder): Builder $build
     */
    #[DataProvider('unfiltered')]
    public function testUpdateRefusesQueriesWithoutAnEffectiveCondition(\Closure $build): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('Refusing to update without a where clause');
        $build(self::users())->update(['name' => 'x']);
    }

    /**
     * @return iterable<string, array{\Closure(Builder): Builder}>
     */
    public static function filtered(): iterable
    {
        yield 'plain where' => [static fn(Builder $q): Builder => $q->where('id', 1)];
        yield 'condition inside a group' => [static fn(Builder $q): Builder => $q->where(static fn(Builder $g) => $g->where('id', 1))];
        yield 'deeply nested condition' => [static fn(Builder $q): Builder => $q->where(static fn(Builder $g) => $g->where(static fn(Builder $h) => $h->whereNull('deleted_at')))];
        yield 'empty group next to a condition' => [static fn(Builder $q): Builder => $q->where(static fn(Builder $g) => $g)->where('id', 1)];
        yield 'whereIn with an empty list (matches nothing)' => [static fn(Builder $q): Builder => $q->whereIn('id', [])];
        yield 'explicit whereNotIn with an empty list (documented: matches all)' => [static fn(Builder $q): Builder => $q->whereNotIn('id', [])];
        yield 'raw condition' => [static fn(Builder $q): Builder => $q->whereRaw('"id" > ?', [5])];
        yield 'column comparison' => [static fn(Builder $q): Builder => $q->whereColumn('updated_at', '>', 'created_at')];
        yield 'json path' => [static fn(Builder $q): Builder => $q->whereJsonPath('meta', 'a', 'x')];
    }

    /**
     * @param \Closure(Builder): Builder $build
     */
    #[DataProvider('filtered')]
    public function testFilteredQueriesPassTheGuard(\Closure $build): void
    {
        foreach (['delete' => static fn(Builder $q): int => $q->delete(), 'update' => static fn(Builder $q): int => $q->update(['name' => 'x'])] as $operation => $run) {
            try {
                $run($build(self::users()));
                self::fail($operation . ' did not reach the database');
            } catch (\LogicException $e) {
                self::assertSame('reached the database', $e->getMessage(), $operation);
            }
        }
    }

    public function testUpdateAllAndDeleteAllStillTakeEveryRowOnPurpose(): void
    {
        foreach ([static fn(Builder $q): int => $q->deleteAll(), static fn(Builder $q): int => $q->updateAll(['name' => 'x'])] as $run) {
            try {
                $run(self::users()->where(static fn(Builder $g) => $g));
                self::fail('did not reach the database');
            } catch (\LogicException $e) {
                self::assertSame('reached the database', $e->getMessage());
            }
        }
    }
}
