# Schema Builder and Migrations

Status: **implemented** (Phase 5). Design decisions are in [ADR 0013](../architecture/adr/0013-schema-and-migrations.md).

## Running migrations (starter app)

```bash
php veyro migrate                  # run pending migrations as one batch
php veyro migrate:status           # Ran / Pending, with batch numbers
php veyro migrate:rollback         # undo the last batch
php veyro migrate:rollback --step=2
```

With `APP_ENV=production`, `migrate` and `migrate:rollback` refuse to run without `--force`.

The starter app includes `database/migrations/2026_10_26_000001_create_users_table.php`:

```php
return new class extends Migration {
    public function up(Schema $schema): void
    {
        $schema->create('users', static function (Blueprint $table): void {
            $table->id();
            $table->ulid()->unique();          // public_id for URLs
            $table->string('name');
            $table->string('email')->unique();
            $table->dateTime('email_verified_at')->nullable();
            $table->string('password');        // password_hash() output only
            $table->timestamps();
        });
    }

    public function down(Schema $schema): void
    {
        $schema->drop('users');
    }
};
```

## Writing a migration

Create `database/migrations/YYYY_MM_DD_HHMMSS_description.php` (lowercase letters, digits and `_` after the timestamp). Files run in name order. `down()` must undo `up()`.

## Column types

| Method | MySQL / MariaDB | PostgreSQL | SQLite |
| --- | --- | --- | --- |
| `id()` | bigint unsigned auto_increment PK | bigserial PK | integer PK autoincrement |
| `ulid($name = 'public_id')` | char(26) | char(26) | varchar(26) |
| `string($name, $length = 255)` | varchar(n) | varchar(n) | varchar(n) |
| `char($name, $length = 255)` | char(n) | char(n) | varchar(n) |
| `text`, `mediumText`, `longText` | text, mediumtext, longtext | text | text |
| `tinyInteger`, `smallInteger` | tinyint, smallint | smallint | integer |
| `integer`, `bigInteger` | int, bigint | integer, bigint | integer |
| `unsignedTinyInteger`, `unsignedSmallInteger`, `unsignedInteger`, `unsignedBigInteger` | unsigned versions | as signed | integer |
| `boolean` | tinyint(1) | boolean | integer |
| `decimal($name, $precision = 10, $scale = 2)` | decimal(p, s) | decimal(p, s) | numeric |
| `float`, `double` | float, double | real, double precision | real |
| `date`, `dateTime` | date, datetime | date, timestamp(0) | date, datetime |
| `timestamp` | datetime | timestamp(0) | datetime |
| `enum($name, ['draft', 'live'])` | varchar(255) + CHECK | varchar(255) + CHECK | varchar(255) + CHECK |
| `json` | json | jsonb | text |
| `foreignId($name)` | bigint unsigned | bigint | integer |
| `timestamps()` | nullable `created_at`, `updated_at` | | |
| `softDeletes($name = 'deleted_at')` | nullable date-time | | |
| `rememberToken()` | nullable varchar(100) `remember_token` | | |

- **`timestamp()` is the same type as `dateTime()`.** MySQL's TIMESTAMP converts time zones, ends in 2038, and in MariaDB can silently get `ON UPDATE CURRENT_TIMESTAMP`. The portable date-time type avoids all three.
- **`enum()` is a string with a CHECK constraint, not MySQL's native ENUM.** The database rejects other values on every engine (MySQL 8.0.16+, MariaDB 10.2+, PostgreSQL, SQLite), and changing the allowed values is a normal migration.
- **`softDeletes()` only creates the column.** The ORM does not hide deleted rows: add `whereNull('deleted_at')` to queries (`where('deleted_at', null)` is refused, because `= NULL` never matches).

Modifiers:

| Modifier | Notes |
| --- | --- |
| `nullable()` | |
| `default($value)` | a scalar, `null`, or `new Expression('CURRENT_TIMESTAMP')` |
| `useCurrent()` | defaults to the current date and time |
| `unique()`, `index()` | |
| `unsigned()` | MySQL only |
| `after('column')` | when adding a column; MySQL and MariaDB only, ignored elsewhere |

**Columns are NOT NULL unless you call `nullable()`.** Adding a NOT NULL column to a table that already has rows needs a `default()`.

## Indexes and foreign keys

```php
$table->index(['last_name', 'first_name']);
$table->unique('email');
$table->foreignId('user_id')->constrained('users')->cascadeOnDelete();   // or nullOnDelete(), onDelete('restrict')
$table->foreign('team_id')->references('id')->on('teams');
$table->primary(['role_id', 'user_id']);   // composite primary key, for tables without id()
```

SQLite can set a primary key only when the table is created; adding one to an existing table throws a clear error.

Index names default to `{table}_{columns}_{index|unique|foreign}`, shortened to at most 60 characters.

## Changing tables

```php
$schema->table('posts', function (Blueprint $table): void {
    $table->string('slug')->nullable()->unique();
    $table->renameColumn('title', 'headline');   // MySQL 8.0+, MariaDB 10.5+, PostgreSQL, SQLite
    $table->dropIndex('posts_views_index');
    $table->dropColumn('views');
});

$schema->rename('posts', 'articles');
$schema->dropIfExists('articles');
$schema->hasTable('users');
$schema->hasColumn('users', 'email');
```

SQLite cannot add a foreign key to an existing column. Add the column together with `constrained()`, or recreate the table.

To review the SQL before running it, use `$schema->createSql(...)` or `$schema->alterSql(...)`.

## Atomicity

| Database | A failing migration |
| --- | --- |
| PostgreSQL, SQLite | is rolled back completely and not recorded |
| MySQL, MariaDB | may leave earlier statements applied (DDL commits implicitly); it is not recorded, and the error says so |

Keep MySQL migrations small: one table change per migration.
