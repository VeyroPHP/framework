# ADR 0039: ORM soft deletes, scopes, model events, through and morph relations

- Status: Accepted
- Date: 2026-10-27

## Context

ADR 0014 left out model events, soft deletes and polymorphic relations. Ported projects use them, and the porting assistant had to call them unsupported. The ORM rules of ADR 0014 still hold: models hold data, the `Orm` service queries, nothing loads lazily, and there is no global state.

## Decisions

### Global scopes and soft deletes are constraints, not where clauses

- A model lists scope objects in `globalScopes()`. A `SOFT_DELETES` model adds "deleted column is null".
- `ModelQuery` adds them only when a query runs, to a copy of its builder, through the internal `Builder::constrain()`. The query's own where clauses become one group and each constraint another, so `where a or b` becomes `(a or b) and (scope)`. An `orWhere()` added later, even on the builder `toBase()` returns, cannot widen a tenant scope or bring back deleted rows.
- Constraints are **not** the where clause that `update()` and `delete()` insist on (DB-1). `$orm->query(Article::class)->toBase()->delete()` still throws. `deleteAll()` means "every row the scopes allow".
- They apply to `find()`, `findByPublicId()`, route binding and relation loading, as well as `get()`. Lifting them is explicit: `withoutGlobalScopes()`, `withTrashed()`, `onlyTrashed()`.
- `save()`, `delete()`, `forceDelete()` and `restore()` act on the model you hold, by primary key, and ignore scopes.
- Not chosen:
  - Adding the conditions when the query is created. A later `orWhere()` would escape them.
  - Making `toBase()` return the unscoped builder. Raw writes would then leave the tenant.

### Soft deletes

- `delete()` writes only the soft-delete column, so unsaved changes on the model stay unsaved. The model changes only after the write succeeded.
- Deleting a trashed model and restoring a model that is not trashed are no-ops without events, so nothing is dispatched twice.
- `forceDelete()` removes the row.
- `deleted_at` is not counted as an update, so `updated_at` does not change.

### Model events

- The `Orm` sends five PSR-14 events: `ModelSaving`, `ModelSaved`, `ModelDeleting`, `ModelDeleted` and `ModelRestored`. It sends them only when an `EventDispatcherInterface` is given to it; `OrmServiceProvider` passes one when it is bound.
- **Before** events (`ModelSaving`, `ModelDeleting`) are vetoes. A listener throws to stop the write, and a saving listener may set attributes.
- `ModelSaving` runs even when nothing changed, because a listener may change something. `ModelSaved` runs only when a row was written.
- **After** events run after the model reflects the write. If a listener throws, the exception reaches the caller. The model already knows its row exists, so retrying `save()` cannot insert twice.
- The ORM opens no transaction. Listeners run inside the caller's transaction, so a throwing listener rolls back the whole unit of work only when the caller used `$orm->transaction()`.
- Not chosen:
  - Observers, or event methods on models. Models do not dispatch or query.
  - Swallowing listener errors. That would hide failures of work the caller asked for.

### Relations

- `hasManyThrough` loads in one join query per chunk. The intermediate model's soft deletes and global scopes hide its related rows.
- Morph relations name their columns (`{name}_type`, `{name}_id`). `morphTo` takes an explicit alias → class map. A stored type outside the map loads `null`: a database value never chooses which class is instantiated. Aliases may be class names, so data written by another framework keeps working.
- After a `morphTo`, nested loading groups models by class: one query per relation and class.
- `belongsToMany` and `hasManyThrough` joins apply the related model's scopes and soft deletes. Scopes name columns without a table, so a model with global scopes is narrowed through a subquery over its own table, `books.id in (select id from books where …)`; an unqualified `team_id` cannot become ambiguous. Models without global scopes get only the qualified soft-delete condition, so their SQL is unchanged. (Compatibility review, 2026-10-27: the first version put scope columns into the join directly, which failed with "ambiguous column" as soon as two joined tables both had `team_id`.)

## Consequences

- `ModelQuery::toBase()` returns a copy of the query as it runs, not the live builder. This changes what earlier code got, before any release.
- `ModelQuery::first()` no longer changes the query it is called on (VQ-053).
- Applications that need atomic listener side effects wrap the save in `$orm->transaction()`. After a rollback, models keep the state of the write that was undone: reload them.
- `hasOneThrough`, `morphToMany` and model pagination are still not included.
