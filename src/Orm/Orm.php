<?php

declare(strict_types=1);

namespace VeyroPHP\Orm;

use Closure;
use DateTimeImmutable;
use Psr\EventDispatcher\EventDispatcherInterface;
use VeyroPHP\Database\Connection\Connection;
use VeyroPHP\Database\Query\Builder;
use VeyroPHP\Orm\Events\ModelDeleted;
use VeyroPHP\Orm\Events\ModelDeleting;
use VeyroPHP\Orm\Events\ModelRestored;
use VeyroPHP\Orm\Events\ModelSaved;
use VeyroPHP\Orm\Events\ModelSaving;
use VeyroPHP\Orm\Exception\ModelNotFoundException;
use VeyroPHP\Orm\Exception\OrmException;
use VeyroPHP\Support\Ulid;

/**
 * Entry point of the optional ORM: queries, saves and deletes models, and
 * loads relations. Built entirely on the query builder.
 *
 * Rules:
 * - Relations are loaded only when asked for (ModelQuery::with() or load()),
 *   one query per relation; reading an unloaded relation throws.
 * - save() inserts new models and updates only changed columns of existing ones.
 * - Models with PUBLIC_ID get a ULID on insert; findByPublicId() looks them up.
 * - TIMESTAMPS models get created_at / updated_at ("Y-m-d H:i:s", PHP's default time zone).
 * - SOFT_DELETES models are only marked by delete(); forceDelete() removes the row.
 * - Global scopes and the soft-delete condition apply to every query, find() and
 *   relation loading. save(), delete(), forceDelete() and restore() work on the model
 *   you hold, by its primary key.
 * - With an event dispatcher, ModelSaving/ModelSaved, ModelDeleting/ModelDeleted and
 *   ModelRestored are sent. Listeners run in the caller's transaction, if any; the
 *   ORM opens none. A listener that throws before the write stops it; one that throws
 *   after it leaves the row written and the model up to date (see Events\ModelSaved).
 */
final class Orm
{
    private const CHUNK = 500;

    /** @var Closure(): DateTimeImmutable */
    private readonly Closure $clock;

    /**
     * @param Closure(): DateTimeImmutable|null $clock
     */
    public function __construct(
        private readonly Connection $connection,
        ?Closure $clock = null,
        private readonly ?EventDispatcherInterface $events = null,
    ) {
        $this->clock = $clock ?? static fn(): DateTimeImmutable => new DateTimeImmutable();
    }

    public function connection(): Connection
    {
        return $this->connection;
    }

    /**
     * @template T of Model
     *
     * @param class-string<T> $model
     *
     * @return ModelQuery<T>
     */
    public function query(string $model): ModelQuery
    {
        return new ModelQuery($this, $model, $this->connection->table($model::table()));
    }

    /**
     * @template T of Model
     *
     * @param class-string<T> $model
     *
     * @return T|null
     */
    public function find(string $model, int|string $id): ?Model
    {
        return $this->query($model)->where($model::primaryKey(), $id)->first();
    }

    /**
     * @template T of Model
     *
     * @param class-string<T> $model
     *
     * @return T
     *
     * @throws ModelNotFoundException
     */
    public function findOrFail(string $model, int|string $id): Model
    {
        return $this->find($model, $id) ?? throw new ModelNotFoundException($model);
    }

    /**
     * Returns null without querying when $publicId is not a valid ULID.
     *
     * @template T of Model
     *
     * @param class-string<T> $model
     *
     * @return T|null
     */
    public function findByPublicId(string $model, string $publicId): ?Model
    {
        $column = $model::publicIdColumn() ?? throw OrmException::noPublicId($model);

        if (!Ulid::isValid($publicId)) {
            return null;
        }

        return $this->query($model)->where($column, $publicId)->first();
    }

    /**
     * @template T of Model
     *
     * @param class-string<T> $model
     *
     * @return T
     *
     * @throws ModelNotFoundException
     */
    public function findByPublicIdOrFail(string $model, string $publicId): Model
    {
        return $this->findByPublicId($model, $publicId) ?? throw new ModelNotFoundException($model);
    }

    /**
     * fill() (FILLABLE only) and save().
     *
     * @template T of Model
     *
     * @param class-string<T> $model
     * @param array<string, mixed> $attributes
     *
     * @return T
     */
    public function create(string $model, array $attributes): Model
    {
        $instance = (new $model())->fill($attributes);
        $this->save($instance);

        return $instance;
    }

    public function save(Model $model): void
    {
        $creating = !$model->exists();
        $this->events?->dispatch(new ModelSaving($model, $creating));

        if ($creating ? $this->insert($model) : $this->update($model)) {
            $this->events?->dispatch(new ModelSaved($model, $creating));
        }
    }

    /**
     * Soft-deletes a SOFT_DELETES model (sets its column to now; the row stays), and
     * removes the row of any other model. Deleting a model that is already trashed
     * does nothing and sends no events.
     */
    public function delete(Model $model): void
    {
        $column = $model::softDeleteColumn();

        if ($column === null) {
            $this->forceDelete($model);

            return;
        }

        $key = $this->persistedKey($model, 'delete');

        if ($model->trashed()) {
            return;
        }

        $this->events?->dispatch(new ModelDeleting($model, false));
        $this->writeColumn($model, $key, $column, ($this->clock)()->format('Y-m-d H:i:s'));
        $this->events?->dispatch(new ModelDeleted($model, false));
    }

    /**
     * Removes the row, whether or not the model uses soft deletes.
     */
    public function forceDelete(Model $model): void
    {
        $key = $this->persistedKey($model, 'delete');

        $this->events?->dispatch(new ModelDeleting($model, true));
        $this->connection->table($model::table())->where($model::primaryKey(), $key)->delete();
        $model->markDeleted();
        $this->events?->dispatch(new ModelDeleted($model, true));
    }

    /**
     * Clears the soft-delete column. Restoring a model that is not trashed does nothing
     * and sends no event.
     *
     * @throws OrmException when the model does not use soft deletes
     */
    public function restore(Model $model): void
    {
        $column = $model::softDeleteColumn() ?? throw OrmException::noSoftDeletes($model::class, 'restore');
        $key = $this->persistedKey($model, 'restore');

        if (!$model->trashed()) {
            return;
        }

        $this->writeColumn($model, $key, $column, null);
        $this->events?->dispatch(new ModelRestored($model));
    }

    /**
     * Eager loads relations on already fetched models. Nested relations use dots:
     * load($users, 'posts.comments'). All models must be of the same class.
     *
     * @param Model|list<Model> $models
     */
    public function load(Model|array $models, string ...$relations): void
    {
        $models = $models instanceof Model ? [$models] : $models;

        if ($models === [] || $relations === []) {
            return;
        }

        foreach ($models as $model) {
            if ($model::class !== $models[0]::class) {
                throw OrmException::relationMismatch('load()', 'models of a single class');
            }
        }

        $this->loadTree($models, self::relationTree(array_values($relations)));
    }

    /**
     * Adds rows to a belongsToMany pivot table.
     *
     * @param int|string|list<int|string> $ids
     */
    public function attach(Model $parent, string $relation, int|string|array $ids): void
    {
        $definition = $this->pivotRelation($parent, $relation);
        $parentKey = self::pivotParentKey($parent, $definition, 'attach');
        $ids = is_array($ids) ? $ids : [$ids];

        if ($ids === []) {
            return;
        }

        $rows = array_map(static fn(int|string $id): array => [
            $definition->foreignKey => $parentKey,
            (string) $definition->relatedPivotKey => $id,
        ], array_values(array_unique($ids)));

        $this->connection->table((string) $definition->pivotTable)->insert($rows);
        $this->forgetRelation($parent, $relation);
    }

    /**
     * Removes pivot rows; with null, removes all of them for this parent.
     *
     * @param int|string|list<int|string>|null $ids
     *
     * @return int rows removed
     */
    public function detach(Model $parent, string $relation, int|string|array|null $ids = null): int
    {
        $definition = $this->pivotRelation($parent, $relation);
        $parentKey = self::pivotParentKey($parent, $definition, 'detach');
        $query = $this->connection->table((string) $definition->pivotTable)->where($definition->foreignKey, $parentKey);

        if ($ids !== null) {
            $query->whereIn((string) $definition->relatedPivotKey, is_array($ids) ? $ids : [$ids]);
        }

        $removed = $query->delete();
        $this->forgetRelation($parent, $relation);

        return $removed;
    }

    /**
     * Makes the pivot rows match $ids exactly, in one transaction.
     *
     * @param list<int|string> $ids
     *
     * @return array{attached: list<int|string>, detached: list<int|string>}
     */
    public function sync(Model $parent, string $relation, array $ids): array
    {
        $definition = $this->pivotRelation($parent, $relation);
        $parentKey = self::pivotParentKey($parent, $definition, 'sync');

        return $this->connection->transaction(function () use ($parent, $relation, $definition, $parentKey, $ids): array {
            $current = $this->connection->table((string) $definition->pivotTable)
                ->where($definition->foreignKey, $parentKey)
                ->pluck((string) $definition->relatedPivotKey);

            $currentKeys = array_map(self::keyString(...), $current);
            $wanted = array_values(array_unique($ids));
            $wantedKeys = array_map(self::keyString(...), $wanted);

            $attach = [];
            foreach ($wanted as $id) {
                if (!in_array(self::keyString($id), $currentKeys, true)) {
                    $attach[] = $id;
                }
            }

            $detach = [];
            foreach ($current as $id) {
                if ((is_int($id) || is_string($id)) && !in_array(self::keyString($id), $wantedKeys, true)) {
                    $detach[] = $id;
                }
            }

            if ($detach !== []) {
                $this->detach($parent, $relation, $detach);
            }

            if ($attach !== []) {
                $this->attach($parent, $relation, $attach);
            }

            return ['attached' => $attach, 'detached' => $detach];
        });
    }

    /**
     * @template R
     *
     * @param Closure(Connection): R $callback
     *
     * @return R
     */
    public function transaction(Closure $callback): mixed
    {
        return $this->connection->transaction($callback);
    }

    private function persistedKey(Model $model, string $action): int|string
    {
        $key = $model->getKey();

        if ($key === null || !$model->exists()) {
            throw OrmException::notPersisted($model::class, $action);
        }

        return $key;
    }

    /**
     * Writes one column by primary key, then sets it on the model: if the write fails, the
     * model is unchanged. Other unsaved changes stay unsaved.
     */
    private function writeColumn(Model $model, int|string $key, string $column, ?string $value): void
    {
        $this->connection->table($model::table())->where($model::primaryKey(), $key)->update([$column => $value]);
        $model->set($column, $value);
        $model->markPersisted($column);
    }

    private function insert(Model $model): bool
    {
        $now = ($this->clock)()->format('Y-m-d H:i:s');
        $publicId = $model::publicIdColumn();

        if ($publicId !== null && $model->raw($publicId) === null) {
            $model->set($publicId, Ulid::generate());
        }

        if ($model::usesTimestamps()) {
            $model->raw('created_at') ?? $model->set('created_at', $now);
            $model->raw('updated_at') ?? $model->set('updated_at', $now);
        }

        $table = $this->connection->table($model::table());
        $primaryKey = $model::primaryKey();

        if ($model->raw($primaryKey) === null) {
            $model->set($primaryKey, $table->insertGetId($model->getAttributes()));
        } else {
            $table->insert($model->getAttributes());
        }

        $model->markPersisted();

        return true;
    }

    /**
     * @return bool whether anything was written
     */
    private function update(Model $model): bool
    {
        $dirty = $model->getDirty();

        if ($dirty === []) {
            return false;
        }

        if ($model::usesTimestamps() && !array_key_exists('updated_at', $dirty)) {
            $model->set('updated_at', ($this->clock)()->format('Y-m-d H:i:s'));
            $dirty = $model->getDirty();
        }

        $key = $model->getKey() ?? throw OrmException::notPersisted($model::class, 'update');
        $this->connection->table($model::table())->where($model::primaryKey(), $key)->update($dirty);
        $model->markPersisted();

        return true;
    }

    /**
     * Groups dotted paths by their first segment: ['posts', 'posts.comments'] → ['posts' => ['comments']].
     *
     * @param list<string> $relations
     *
     * @return array<string, list<string>> relation => nested paths below it
     */
    private static function relationTree(array $relations): array
    {
        $tree = [];

        foreach ($relations as $path) {
            $parts = explode('.', $path, 2);
            $tree[$parts[0]] ??= [];

            if (isset($parts[1])) {
                $tree[$parts[0]][] = $parts[1];
            }
        }

        return $tree;
    }

    /**
     * @param list<Model> $models
     * @param array<string, list<string>> $tree
     */
    private function loadTree(array $models, array $tree): void
    {
        // After a morphTo the related models can be of several classes: each class loads
        // its own relations, so nested paths keep one query per relation and class.
        $byClass = [];

        foreach ($models as $model) {
            $byClass[$model::class][] = $model;
        }

        foreach ($byClass as $class => $group) {
            foreach ($tree as $name => $nestedPaths) {
                $related = $this->loadRelation($group, $name, $class::relation($name));

                if ($nestedPaths !== [] && $related !== []) {
                    $this->loadTree($related, self::relationTree($nestedPaths));
                }
            }
        }
    }

    /**
     * @param non-empty-list<Model> $models of one class
     *
     * @return list<Model> all related models that were loaded
     */
    private function loadRelation(array $models, string $name, Relation $relation): array
    {
        if ($relation->type === Relation::BELONGS_TO) {
            return $this->loadBelongsTo($models, $name, $relation);
        }

        if ($relation->type === Relation::MORPH_TO) {
            return $this->loadMorphTo($models, $name, $relation);
        }

        // For belongsToMany, ownerKey is the related model's column; the parent's is parentKey.
        $localKey = $relation->type === Relation::BELONGS_TO_MANY ? ($relation->parentKey ?? $models[0]::primaryKey()) : $relation->ownerKey;
        $keys = self::keys($models, $localKey);
        $grouped = [];
        $all = [];

        $found = match ($relation->type) {
            Relation::BELONGS_TO_MANY => $this->fetchJoined($relation, $keys),
            Relation::HAS_MANY_THROUGH => $this->fetchThrough($relation, $keys),
            default => array_map(
                static fn(Model $model): array => [self::keyString($model->raw($relation->foreignKey)), $model],
                $this->fetchInChunks($relation->related, $relation->foreignKey, $keys, $relation->morphType === null ? [] : [$relation->morphType => (string) $relation->morphAlias]),
            ),
        };

        foreach ($found as [$parentKey, $model]) {
            $grouped[$parentKey][] = $model;
            $all[] = $model;
        }

        $single = $relation->type === Relation::HAS_ONE || $relation->type === Relation::MORPH_ONE;

        foreach ($models as $model) {
            $children = $grouped[self::keyString($model->raw($localKey))] ?? [];
            $model->setRelation($name, $single ? ($children[0] ?? null) : $children);
        }

        return $all;
    }

    /**
     * @param non-empty-list<Model> $models
     *
     * @return list<Model>
     */
    private function loadBelongsTo(array $models, string $name, Relation $relation): array
    {
        $byKey = [];

        foreach ($this->fetchInChunks($relation->related, $relation->ownerKey, self::keys($models, $relation->foreignKey)) as $model) {
            $byKey[self::keyString($model->raw($relation->ownerKey))] = $model;
        }

        foreach ($models as $model) {
            $model->setRelation($name, $byKey[self::keyString($model->raw($relation->foreignKey))] ?? null);
        }

        return array_values($byKey);
    }

    /**
     * One query per type that occurs. The class comes only from the relation's map: a
     * stored type outside it loads null and never names a class to instantiate.
     *
     * @param non-empty-list<Model> $models
     *
     * @return list<Model>
     */
    private function loadMorphTo(array $models, string $name, Relation $relation): array
    {
        $typeColumn = (string) $relation->morphType;
        $byType = [];

        foreach ($models as $model) {
            $type = $model->raw($typeColumn);

            if (is_string($type) && isset($relation->morphMap[$type])) {
                $byType[$type][] = $model;
            }
        }

        $found = [];
        $all = [];

        foreach ($byType as $type => $group) {
            $class = $relation->morphMap[$type];
            $primaryKey = $class::primaryKey();

            foreach ($this->fetchInChunks($class, $primaryKey, self::keys($group, $relation->foreignKey)) as $related) {
                $found[$type][self::keyString($related->raw($primaryKey))] = $related;
                $all[] = $related;
            }
        }

        foreach ($models as $model) {
            $type = $model->raw($typeColumn);
            $model->setRelation($name, is_string($type) ? ($found[$type][self::keyString($model->raw($relation->foreignKey))] ?? null) : null);
        }

        return $all;
    }

    /**
     * @param class-string<Model> $model
     * @param list<int|string> $keys
     * @param array<string, string> $equals further column = value conditions
     *
     * @return list<Model>
     */
    private function fetchInChunks(string $model, string $column, array $keys, array $equals = []): array
    {
        $results = [];

        foreach (array_chunk($keys, self::CHUNK) as $chunk) {
            $query = $this->query($model)->whereIn($column, $chunk);

            foreach ($equals as $equalColumn => $value) {
                $query->where($equalColumn, $value);
            }

            foreach ($query->get() as $found) {
                $results[] = $found;
            }
        }

        return $results;
    }

    /**
     * belongsToMany: the related table joined with the pivot table.
     *
     * @param list<int|string> $keys
     *
     * @return list<array{string, Model}> [parent key, related model]
     */
    private function fetchJoined(Relation $relation, array $keys): array
    {
        $pivot = (string) $relation->pivotTable;

        return $this->fetchJoinedInChunks($relation->related, $keys, $pivot, $pivot . '.' . $relation->relatedPivotKey, $relation->ownerKey, $pivot . '.' . $relation->foreignKey);
    }

    /**
     * hasManyThrough: the related table joined with the intermediate table, whose global
     * scopes and soft deletes apply too.
     *
     * @param list<int|string> $keys
     *
     * @return list<array{string, Model}>
     */
    private function fetchThrough(Relation $relation, array $keys): array
    {
        $through = $relation->through ?? throw OrmException::invalidRelation('hasManyThrough needs an intermediate model.');
        $throughTable = $through::table();

        return $this->fetchJoinedInChunks(
            $relation->related,
            $keys,
            $throughTable,
            $throughTable . '.' . $relation->throughKey,
            (string) $relation->secondKey,
            $throughTable . '.' . $relation->foreignKey,
            fn(Builder $query): Builder => $this->constrainJoined($query, $through),
        );
    }

    /**
     * Adds a model's global scopes and soft-delete condition to a query that joins its
     * table. Scopes name columns without a table, which become ambiguous once two tables
     * have them (every tenant table has team_id), so a model with global scopes is
     * narrowed through a subquery over its own table: "books.id in (select id from books
     * where ...)". Without global scopes only the qualified soft-delete condition is added.
     *
     * @param class-string<Model> $model
     */
    private function constrainJoined(Builder $query, string $model): Builder
    {
        if ($model::globalScopes() === []) {
            return ModelQuery::constrain($query, $model);
        }

        $key = $model::primaryKey();
        $allowed = $this->query($model)->toBase()->select($key);

        return $query->whereRaw(
            $this->connection->dialect()->quoteIdentifier($model::table() . '.' . $key) . ' in (' . $allowed->toSql() . ')',
            $allowed->getBindings(),
        );
    }

    /**
     * @param class-string<Model> $related
     * @param list<int|string> $keys
     * @param string $joinColumn qualified column of $joinTable matched with $relatedColumn
     * @param string $parentColumn qualified column of $joinTable holding the parent key
     * @param (Closure(Builder): Builder)|null $narrow extra conditions on the joined table
     *
     * @return list<array{string, Model}>
     */
    private function fetchJoinedInChunks(string $related, array $keys, string $joinTable, string $joinColumn, string $relatedColumn, string $parentColumn, ?Closure $narrow = null): array
    {
        $table = $related::table();
        $results = [];

        foreach (array_chunk($keys, self::CHUNK) as $chunk) {
            $query = $this->connection->table($table)
                ->select($table . '.*', $parentColumn . ' as veyrophp_parent')
                ->join($joinTable, $joinColumn, '=', $table . '.' . $relatedColumn)
                ->whereIn($parentColumn, $chunk);
            $query = $this->constrainJoined($query, $related);

            foreach (($narrow === null ? $query : $narrow($query))->get() as $row) {
                $parentKey = self::keyString($row['veyrophp_parent'] ?? null);
                unset($row['veyrophp_parent']);
                $results[] = [$parentKey, $related::hydrate($row)];
            }
        }

        return $results;
    }

    /**
     * @param list<Model> $models
     *
     * @return list<int|string>
     */
    private static function keys(array $models, string $column): array
    {
        $keys = [];

        foreach ($models as $model) {
            $value = $model->raw($column);
            if (is_int($value) || is_string($value)) {
                $keys[self::keyString($value)] = $value;
            }
        }

        return array_values($keys);
    }

    private static function keyString(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * The parent's value stored in the pivot table: its primary key, or the relation's
     * parentKey column.
     */
    private static function pivotParentKey(Model $parent, Relation $definition, string $action): int|string
    {
        $value = $parent->raw($definition->parentKey ?? $parent::primaryKey());

        return is_int($value) || is_string($value) ? $value : throw OrmException::notPersisted($parent::class, $action);
    }

    private function pivotRelation(Model $parent, string $relation): Relation
    {
        $definition = $parent::relation($relation);

        if ($definition->type !== Relation::BELONGS_TO_MANY) {
            throw OrmException::relationMismatch($relation, 'a belongsToMany relation');
        }

        return $definition;
    }

    private function forgetRelation(Model $parent, string $relation): void
    {
        // A loaded relation would now be stale; drop it so reading it throws until reloaded.
        $parent->unsetRelation($relation);
    }
}
