# ORM

Status: **implemented** (Phase 5), optional. Design decisions are in [ADR 0014](../architecture/adr/0014-orm.md).

The ORM sits on top of the query builder. **Models hold data; the `Orm` service runs queries.** There is no global state and no lazy loading, so every query is visible in your code.

Register `OrmServiceProvider` after `DatabaseServiceProvider` and `HttpServiceProvider`, then inject `VeyroPHP\Orm\Orm`.

## Defining a model

```php
namespace App\Models;

use VeyroPHP\Orm\Model;
use VeyroPHP\Orm\Relation;

/**
 * @property string $name
 * @property string $email
 * @property-read list<Post> $posts
 * @property-read list<Role> $roles
 */
final class User extends Model
{
    protected const TABLE = 'users';            // default: snake_case class name + "s"
    protected const PUBLIC_ID = 'public_id';    // ULID column for URLs; null to disable
    protected const FILLABLE = ['name', 'email'];
    protected const HIDDEN = ['password'];      // never in toArray()/JSON
    protected const CASTS = ['email_verified_at' => 'datetime', 'is_admin' => 'bool', 'settings' => 'json'];
    protected const TIMESTAMPS = true;          // created_at / updated_at

    public static function relations(): array
    {
        return [
            'posts' => Relation::hasMany(Post::class, 'user_id'),
            'profile' => Relation::hasOne(Profile::class, 'user_id'),
            'roles' => Relation::belongsToMany(Role::class, 'role_user', 'user_id', 'role_id'),
        ];
    }
}
```

The `@property` annotations are optional, but give IDE autocompletion and let static analysis check `$user->name`.

Casts: `int`, `float`, `bool`, `string`, `datetime` (`Y-m-d H:i:s` ↔ `DateTimeImmutable`), `date`, `json` (array).

## Querying

```php
$user = $orm->find(User::class, 5);                      // or null
$user = $orm->findOrFail(User::class, 5);                // 404 if missing
$user = $orm->findByPublicId(User::class, $ulid);        // invalid ULIDs return null without a query

$admins = $orm->query(User::class)
    ->where('is_admin', true)
    ->orderBy('name')
    ->with('posts.comments', 'roles')                    // eager load
    ->get();                                             // list<User>

$orm->query(User::class)->where('email', $email)->firstOrFail();
$orm->query(User::class)->count();
$orm->query(User::class)->cursorPaginate(50, $cursor);      // large tables: no COUNT, no OFFSET
$orm->query(User::class)->with('posts')->chunkById(500, function (array $users): void { /* ... */ });
foreach ($orm->query(User::class)->lazyById() as $user) { /* one model at a time */ }
$orm->query(User::class)->toBase();                      // a query builder copy, scopes included
```

Running a query does not change it: `first()` on a query and then `get()` on the same query returns every row again.

## Creating, updating, deleting

```php
$user = (new User())->fill($request->getParsedBody());   // only FILLABLE keys allowed
$user->set('password', password_hash($plain, PASSWORD_DEFAULT));
$orm->save($user);                                       // insert: id, public_id, timestamps

$user->name = 'New name';
$orm->save($user);                                       // update: only changed columns + updated_at

$orm->delete($user);

$role = $orm->create(Role::class, ['name' => 'editor']); // fill + save
```

`fill()` **throws** `MassAssignmentException` for keys outside `FILLABLE` (for example `is_admin` from a form). Use `set()` or `forceFill()` only with trusted data.

Saving a model without changes runs no query.

## Soft deletes

```php
final class Article extends Model
{
    protected const SOFT_DELETES = 'deleted_at';   // a nullable timestamp column: $table->softDeletes()
}

$orm->delete($article);        // sets deleted_at; the row stays, $article->trashed() is true
$orm->restore($article);       // clears deleted_at
$orm->forceDelete($article);   // removes the row

$orm->query(Article::class)->get();                 // deleted rows are left out
$orm->query(Article::class)->withTrashed()->get();  // all rows
$orm->query(Article::class)->onlyTrashed()->get();  // only deleted rows
```

- Deleted rows are left out of every query: `find()`, `findByPublicId()`, route binding (a deleted record is a 404), counts, chunks, cursor pages and relation loading. This holds even with `orWhere()`.
- `delete()` writes only `deleted_at`. Other unsaved changes stay unsaved, and `updated_at` is not touched.
- Deleting a model that is already deleted, or restoring one that is not, does nothing.
- `restore()`, `withTrashed()` and `onlyTrashed()` throw `OrmException` on models without `SOFT_DELETES`. On those models `delete()` removes the row, as before.

## Scopes

A scope is a reusable set of conditions:

```php
use VeyroPHP\Database\Query\Builder;
use VeyroPHP\Orm\Scope;

final class Published implements Scope
{
    public function apply(Builder $query): void
    {
        $query->where('status', 'published')->orWhere('status', 'featured');
    }
}

$orm->query(Article::class)->where('team_id', 3)->apply(new Published())->get();
$orm->query(Article::class)->apply(fn (Builder $q) => $q->where('views', '>', 100))->get();
```

`apply()` adds the scope as one group, `team_id = 3 and (status = published or status = featured)`, so an `or` inside a scope never widens the query.

**Global scopes** apply to every query of a model, including `find()`, route binding and relation loading:

```php
final class Article extends Model
{
    public static function globalScopes(): array
    {
        return [new TenantScope($tenantId)];   // or a scope that reads the current tenant
    }
}

$orm->query(Article::class)->withoutGlobalScopes(TenantScope::class)->get();  // lift one
$orm->query(Article::class)->withoutGlobalScopes()->get();                    // lift all
```

Global scopes and the soft-delete condition are added when the query runs, as conditions every row must meet. An `orWhere()` cannot get around them, not even one added to the builder from `toBase()`. They do **not** count as the where clause that `update()` and `delete()` require, so `$orm->query(Article::class)->toBase()->delete()` still throws. Use `deleteAll()` when every row the scopes allow is meant. `save()`, `delete()`, `forceDelete()` and `restore()` act on the model you hold, by its primary key.

## Model events

With an event dispatcher (`EventServiceProvider` registered before `OrmServiceProvider`), the `Orm` sends:

| Event | When | A listener that throws |
| --- | --- | --- |
| `ModelSaving` (`$model`, `$creating`) | before every `save()`, even without changes; listeners may set attributes | stops the save: nothing is written |
| `ModelSaved` (`$model`, `$creating`) | after a row was inserted or updated | the row is written; the exception reaches the caller |
| `ModelDeleting` (`$model`, `$force`) | before `delete()` (`$force` false) or `forceDelete()` (true) | stops the delete |
| `ModelDeleted` (`$model`, `$force`) | after the delete | the delete stays; the exception reaches the caller |
| `ModelRestored` (`$model`) | after `restore()` | the restore stays; the exception reaches the caller |

```php
use VeyroPHP\Orm\Events\ModelSaving;

$events->listen(ModelSaving::class, function (ModelSaving $event): void {
    if ($event->model instanceof Article) {
        $event->model->set('slug', strtolower(str_replace(' ', '-', (string) $event->model->raw('title'))));
    }
});
```

- The ORM opens no transaction. Listeners run inside yours, so when the write and a listener's work must succeed together, wrap them: `$orm->transaction(fn () => $orm->save($article))`. A listener that throws then rolls back both. Reload models after a rollback: they keep the state of the write that was undone.
- Each event is sent once per write. A save without changes sends no `ModelSaved`. Deleting a deleted model or restoring a restored one sends nothing. After a failing `ModelSaved` listener, calling `save()` again does not insert a second row.
- `fill()` and `set()` send no events, and `toBase()` writes bypass them.

## Relations

Relations are **only** loaded when you ask:

```php
$users = $orm->query(User::class)->with('posts')->get();  // 2 queries for any number of users
$orm->load($user, 'roles');                               // after fetching
```

Reading a relation that was not loaded throws:

```
Relation "posts" on App\Models\User is not loaded. Load it with ->with('posts') on the query, or $orm->load($model, 'posts').
```

That makes N+1 problems impossible to introduce by accident.

| Type | Declaration | Loaded value |
| --- | --- | --- |
| belongs to | `Relation::belongsTo(User::class, 'user_id')` | model or `null` |
| has one | `Relation::hasOne(Profile::class, 'user_id')` | model or `null` |
| has many | `Relation::hasMany(Post::class, 'user_id')` | list (empty if none) |
| many to many | `Relation::belongsToMany(Role::class, 'role_user', 'user_id', 'role_id')` | list; the pivot holds each model's primary key (`PRIMARY_KEY`), or other columns named with `parentKey:` and `relatedKey:` |
| has many through | `Relation::hasManyThrough(Story::class, Author::class, 'country_id', 'author_id')` | list |
| morph many / one | `Relation::morphMany(Remark::class, 'remarkable', 'photo')`, `morphOne(...)` | list / model or `null` |
| morph to | `Relation::morphTo('remarkable', ['photo' => Photo::class, 'video' => Video::class])` | model or `null` |

The related model's global scopes and soft deletes apply when relations load: a deleted author is `null` through `belongsTo` and missing from `hasMany`.

**Has many through** reaches rows two steps away with one join query: countries ← `authors.country_id`, authors ← `stories.author_id`. Deleted or scoped-out authors hide their stories. In the join queries of `hasManyThrough` and `belongsToMany`, a model's global scopes run in a subquery over its own table. A tenant scope's `team_id` therefore stays unambiguous even when every table has that column, and a `team_id` column on a pivot table plays no part.

**Morph relations** let one table point to several: `remarks.remarkable_type` holds an alias (`'photo'`) and `remarks.remarkable_id` the key.

- `morphTo` loads one query per type that occurs, and only classes in its map. A type that is not in the map loads `null`, so a value in the database can never choose which class is created.
- Aliases may be class names, which keeps data written by another framework working: `['App\Models\Photo' => Photo::class]`.
- Nested loading after a `morphTo` (`with('remarkable.remarks')`) works per class.

Many-to-many changes:

```php
$orm->attach($user, 'roles', [$adminId, $editorId]);
$orm->detach($user, 'roles', $adminId);          // or detach($user, 'roles') for all
$orm->sync($user, 'roles', [$editorId]);         // returns ['attached' => [...], 'detached' => [...]]
```

## JSON output

`toArray()` and `json_encode($model)` include attributes (with casts; dates as ISO 8601) and loaded relations, never `HIDDEN` attributes, and **never run queries**. Returning a model or a list of models from a route handler produces JSON.

## Route model binding

```php
$router->get('/users/{user}', fn (User $user) => $user);
```

- Models with `PUBLIC_ID` are found by their ULID: `/users/01JB8Q3Z9X4V7K2M5N6P8R0T1W`.
- Models without it are found by a positive integer primary key.
- Unknown or malformed values give **404**; malformed ULIDs do not reach the database.
- Soft-deleted records and records outside a global scope give **404** too.

Binding only finds the record. **Check authorization in the handler** (for example that the current user owns it); authentication and policies come in Phase 6.
