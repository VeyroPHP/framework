<?php

declare(strict_types=1);

namespace VeyroPHP\Orm;

use Closure;
use Generator;
use VeyroPHP\Database\Query\Builder;
use VeyroPHP\Database\Query\CursorPaginator;
use VeyroPHP\Database\Query\Expression;
use VeyroPHP\Orm\Exception\ModelNotFoundException;
use VeyroPHP\Orm\Exception\OrmException;

/**
 * A query that returns models instead of arrays. Relations named in with()
 * are loaded with one extra query per relation (and per nesting level).
 *
 * The model's global scopes and, for soft-deleting models, "not deleted" are
 * added when the query runs, as conditions every row must meet: an orWhere()
 * cannot widen them. Running a query never changes it, so it can run again.
 *
 * @template T of Model
 */
final class ModelQuery
{
    private const WITHOUT_TRASHED = 'without';
    private const WITH_TRASHED = 'with';
    private const ONLY_TRASHED = 'only';

    /** @var list<string> */
    private array $with = [];

    private string $trashed = self::WITHOUT_TRASHED;

    private bool $globalScopes = true;

    /** @var list<string> global scope classes lifted for this query */
    private array $liftedScopes = [];

    /**
     * @param class-string<T> $model
     */
    public function __construct(
        private readonly Orm $orm,
        private readonly string $model,
        private readonly Builder $builder,
    ) {}

    /**
     * Relations to eager load, e.g. with('posts', 'posts.comments', 'roles').
     *
     * @return $this
     */
    public function with(string ...$relations): self
    {
        foreach ($relations as $relation) {
            $this->with[] = $relation;
        }

        return $this;
    }

    /**
     * Adds a scope's conditions as one group: "where ... and (scope)".
     *
     * @param Scope|Closure(Builder): mixed $scope
     *
     * @return $this
     */
    public function apply(Scope|Closure $scope): self
    {
        $this->builder->where(static function (Builder $group) use ($scope): void {
            $scope instanceof Scope ? $scope->apply($group) : $scope($group);
        });

        return $this;
    }

    /**
     * Lifts the named global scopes for this query, or all of them without arguments.
     *
     * @param class-string<Scope> ...$scopes
     *
     * @return $this
     */
    public function withoutGlobalScopes(string ...$scopes): self
    {
        if ($scopes === []) {
            $this->globalScopes = false;
        }

        foreach ($scopes as $scope) {
            $this->liftedScopes[] = $scope;
        }

        return $this;
    }

    /**
     * Includes soft-deleted rows.
     *
     * @return $this
     */
    public function withTrashed(): self
    {
        $this->trashed = $this->softDeleting('withTrashed()');

        return $this;
    }

    /**
     * Only soft-deleted rows.
     *
     * @return $this
     */
    public function onlyTrashed(): self
    {
        $this->softDeleting('onlyTrashed()');
        $this->trashed = self::ONLY_TRASHED;

        return $this;
    }

    /** @return $this */
    public function select(string|Expression ...$columns): self
    {
        $this->builder->select(...$columns);

        return $this;
    }

    /** @return $this */
    public function where(string|Expression|Closure $column, mixed $operator = null, mixed $value = null): self
    {
        func_num_args() === 2 ? $this->builder->where($column, $operator) : $this->builder->where($column, $operator, $value);

        return $this;
    }

    /** @return $this */
    public function orWhere(string|Expression|Closure $column, mixed $operator = null, mixed $value = null): self
    {
        func_num_args() === 2 ? $this->builder->orWhere($column, $operator) : $this->builder->orWhere($column, $operator, $value);

        return $this;
    }

    /**
     * @param list<mixed> $values
     *
     * @return $this
     */
    public function whereIn(string|Expression $column, array $values): self
    {
        $this->builder->whereIn($column, $values);

        return $this;
    }

    /**
     * @param list<mixed> $values
     *
     * @return $this
     */
    public function whereNotIn(string|Expression $column, array $values): self
    {
        $this->builder->whereNotIn($column, $values);

        return $this;
    }

    /** @return $this */
    public function whereNull(string|Expression $column): self
    {
        $this->builder->whereNull($column);

        return $this;
    }

    /** @return $this */
    public function whereNotNull(string|Expression $column): self
    {
        $this->builder->whereNotNull($column);

        return $this;
    }

    /** @return $this */
    public function orderBy(string|Expression $column, string $direction = 'asc'): self
    {
        $this->builder->orderBy($column, $direction);

        return $this;
    }

    /** @return $this */
    public function orderByDesc(string|Expression $column): self
    {
        $this->builder->orderByDesc($column);

        return $this;
    }

    /** @return $this */
    public function limit(int $limit): self
    {
        $this->builder->limit($limit);

        return $this;
    }

    /** @return $this */
    public function offset(int $offset): self
    {
        $this->builder->offset($offset);

        return $this;
    }

    /** @return $this */
    public function forPage(int $page, int $perPage): self
    {
        $this->builder->forPage($page, $perPage);

        return $this;
    }

    /**
     * @return list<T>
     */
    public function get(): array
    {
        return $this->hydrateAll($this->prepared()->get());
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<T>
     */
    private function hydrateAll(array $rows): array
    {
        $model = $this->model;
        $models = array_map(static fn(array $row): Model => $model::hydrate($row), $rows);

        if ($this->with !== [] && $models !== []) {
            $this->orm->load($models, ...$this->with);
        }

        return $models;
    }

    /**
     * @return T|null
     */
    public function first(): ?Model
    {
        return $this->hydrateAll($this->prepared()->limit(1)->get())[0] ?? null;
    }

    /**
     * @return T
     *
     * @throws ModelNotFoundException
     */
    public function firstOrFail(): Model
    {
        return $this->first() ?? throw new ModelNotFoundException($this->model);
    }

    /**
     * Calls $callback with chunks of models ordered by the primary key, one query
     * per chunk (memory stays flat on large tables). Relations from with() are
     * loaded per chunk. Return false from the callback to stop.
     *
     * @param Closure(list<T>): (bool|null|void) $callback
     *
     * @return bool false when the callback stopped early
     */
    public function chunkById(int $size, Closure $callback, ?string $column = null): bool
    {
        foreach ($this->prepared()->chunksById($size, $column ?? $this->model::primaryKey()) as $rows) {
            if ($callback($this->hydrateAll($rows)) === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Yields models one by one, loading $chunkSize per query (see chunkById()).
     *
     * @return Generator<int, T>
     */
    public function lazyById(int $chunkSize = 1000, ?string $column = null): Generator
    {
        $index = 0;

        foreach ($this->prepared()->chunksById($chunkSize, $column ?? $this->model::primaryKey()) as $rows) {
            foreach ($this->hydrateAll($rows) as $model) {
                yield $index++ => $model;
            }
        }
    }

    /**
     * Keyset pagination without COUNT(*) or OFFSET (see Builder::cursorPaginate()).
     *
     * @return CursorPaginator<T>
     */
    public function cursorPaginate(int $perPage = 15, ?string $cursor = null, ?string $column = null, string $direction = 'asc'): CursorPaginator
    {
        $page = $this->prepared()->cursorPaginate($perPage, $cursor, $column ?? $this->model::primaryKey(), $direction);

        return new CursorPaginator($this->hydrateAll($page->items), $page->perPage, $page->nextCursor);
    }

    public function count(): int
    {
        return $this->prepared()->count();
    }

    public function exists(): bool
    {
        return $this->prepared()->exists();
    }

    /**
     * A copy of the query as it runs, with global scopes and the soft-delete condition,
     * for anything ModelQuery does not wrap (aggregates, update(), delete()). Changing the
     * copy does not change this query. Its update() and delete() still need a where
     * clause of the query's own: scopes narrow the rows but are not that clause.
     */
    public function toBase(): Builder
    {
        return $this->prepared();
    }

    /**
     * @internal adds a model's global scopes and "not soft-deleted" to a builder; the ORM
     * uses it for relation queries. Columns of the soft-delete condition are qualified
     * with the table, so it also works on joins.
     *
     * @param class-string<Model> $model
     * @param list<string>|null $liftedScopes null lifts every global scope
     */
    public static function constrain(Builder $builder, string $model, string $trashed = self::WITHOUT_TRASHED, ?array $liftedScopes = []): Builder
    {
        if ($liftedScopes !== null) {
            foreach ($model::globalScopes() as $scope) {
                if (!in_array($scope::class, $liftedScopes, true)) {
                    $builder->constrain(static fn(Builder $group) => $scope->apply($group));
                }
            }
        }

        $column = $model::softDeleteColumn();

        if ($column !== null && $trashed !== self::WITH_TRASHED) {
            $qualified = $model::table() . '.' . $column;
            $builder->constrain(static fn(Builder $group) => $trashed === self::ONLY_TRASHED ? $group->whereNotNull($qualified) : $group->whereNull($qualified));
        }

        return $builder;
    }

    /**
     * A copy of the builder with the scopes this query keeps.
     */
    private function prepared(): Builder
    {
        return self::constrain(clone $this->builder, $this->model, $this->trashed, $this->globalScopes ? $this->liftedScopes : null);
    }

    private function softDeleting(string $method): string
    {
        if ($this->model::softDeleteColumn() === null) {
            throw OrmException::noSoftDeletes($this->model, $method);
        }

        return self::WITH_TRASHED;
    }
}
