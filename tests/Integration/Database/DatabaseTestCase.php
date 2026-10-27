<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Integration\Database;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use VeyroPHP\Database\Connection\Connection;
use VeyroPHP\Database\Exception\DatabaseException;
use VeyroPHP\Database\Exception\InvalidCursorException;
use VeyroPHP\Database\Exception\QueryException;
use VeyroPHP\Database\Exception\TransactionException;
use VeyroPHP\Database\Migration\MigrationException;
use VeyroPHP\Database\Migration\Migrator;
use VeyroPHP\Database\Query\Builder;
use VeyroPHP\Database\Schema\Blueprint;
use VeyroPHP\Database\Schema\Schema;
use VeyroPHP\Support\Ulid;
use VeyroPHP\Tests\Fixtures\Database\Priority;
use VeyroPHP\Tests\Fixtures\Database\UserStatus;

/**
 * Behavior every supported database must share. Subclasses provide the
 * connection and the schema in their own SQL dialect.
 */
abstract class DatabaseTestCase extends TestCase
{
    protected Connection $db;

    abstract protected function createConnection(): Connection;

    /**
     * Creates "users" (id, public_id, name, email unique, age, active, status, priority, created_at)
     * and "posts" (id, user_id, title, views).
     */
    abstract protected function createSchema(): void;

    protected function setUp(): void
    {
        $this->db = $this->createConnection();
        $this->db->statement('drop table if exists posts');
        $this->db->statement('drop table if exists users');
        $this->createSchema();
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return; // skipped before a connection was created
        }

        if ($this->db->transactionLevel() > 0) {
            while ($this->db->transactionLevel() > 0) {
                $this->db->rollBack();
            }
        }

        $this->db->statement('drop table if exists posts');
        $this->db->statement('drop table if exists users');
        $this->db->disconnect();
    }

    /**
     * Runs the callback and returns what it threw, or null.
     */
    protected static function capture(\Closure $callback): ?\Throwable
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            return $e;
        }

        return null;
    }

    protected function users(): Builder
    {
        return $this->db->table('users');
    }

    /**
     * @return int|string
     */
    protected function addUser(string $name, int $age = 30, string $email = '', bool $active = true): int|string
    {
        return $this->users()->insertGetId([
            'name' => $name,
            'email' => $email !== '' ? $email : strtolower($name) . '@example.test',
            'age' => $age,
            'active' => $active,
        ]);
    }

    // CRUD

    public function testInsertSelectUpdateDelete(): void
    {
        $id = $this->addUser('Ravi', 31);

        self::assertIsInt($id);
        self::assertSame('Ravi', $this->users()->where('id', $id)->value('name'));

        self::assertSame(1, $this->users()->where('id', $id)->update(['name' => 'Ravi Kumar', 'age' => 32]));
        $row = $this->users()->where('id', $id)->first();
        self::assertNotNull($row);
        self::assertSame('Ravi Kumar', $row['name']);
        self::assertEquals(32, $row['age']);

        self::assertSame(1, $this->users()->where('id', $id)->delete());
        self::assertNull($this->users()->where('id', $id)->first());
    }

    public function testBulkInsertAndAggregates(): void
    {
        self::assertSame(3, $this->users()->insert([
            ['name' => 'A', 'email' => 'a@x.test', 'age' => 20, 'active' => true],
            ['name' => 'B', 'email' => 'b@x.test', 'age' => 30, 'active' => false],
            ['name' => 'C', 'email' => 'c@x.test', 'age' => 40, 'active' => true],
        ]));

        self::assertSame(3, $this->users()->count());
        self::assertSame(2, $this->users()->where('active', true)->count());
        self::assertSame(90, $this->users()->sum('age'));
        self::assertSame(30.0, $this->users()->avg('age'));
        self::assertEquals(20, $this->users()->min('age'));
        self::assertEquals(40, $this->users()->max('age'));
        self::assertTrue($this->users()->where('name', 'B')->exists());
        self::assertFalse($this->users()->where('name', 'Z')->exists());
        self::assertSame(['A', 'B', 'C'], $this->users()->orderBy('name')->pluck('name'));
    }

    public function testWhereVariantsAgainstRealData(): void
    {
        $this->addUser('Asha', 22);
        $this->addUser('Bharat', 35, active: false);
        $this->addUser('Chetan', 48);

        self::assertSame(['Asha', 'Chetan'], $this->users()->whereIn('name', ['Asha', 'Chetan', 'Nobody'])->orderBy('name')->pluck('name'));
        self::assertSame([], $this->users()->whereIn('name', [])->pluck('name'));
        self::assertSame(3, $this->users()->whereNotIn('name', [])->count());
        self::assertSame(['Bharat'], $this->users()->whereBetween('age', [30, 40])->pluck('name'));
        self::assertSame(['Bharat', 'Chetan'], $this->users()->where('name', 'like', '%t%')->orderBy('name')->pluck('name'));
        self::assertSame(
            ['Asha', 'Bharat'],
            $this->users()->where(static fn(Builder $q) => $q->where('age', '<', 30)->orWhere('active', false))->orderBy('name')->pluck('name'),
        );
        self::assertSame(3, $this->users()->whereNull('created_at')->count());
    }

    public function testJoinGroupByHaving(): void
    {
        $ravi = $this->addUser('Ravi');
        $asha = $this->addUser('Asha');
        $this->db->table('posts')->insert([
            ['user_id' => $ravi, 'title' => 'One', 'views' => 10],
            ['user_id' => $ravi, 'title' => 'Two', 'views' => 5],
            ['user_id' => $asha, 'title' => 'Three', 'views' => 1],
        ]);

        $rows = $this->users()
            ->select('users.name', $this->db->raw('count(*) as post_count'))
            ->join('posts', 'posts.user_id', '=', 'users.id')
            ->groupBy('users.name')
            ->having($this->db->raw('count(*)'), '>', 1)
            ->get();

        self::assertCount(1, $rows);
        self::assertSame('Ravi', $rows[0]['name']);
        self::assertEquals(2, $rows[0]['post_count']);
        self::assertSame(2, $this->users()->select('users.name')->join('posts', 'posts.user_id', '=', 'users.id')->groupBy('users.name')->count());
    }

    public function testPagination(): void
    {
        foreach (range(1, 7) as $i) {
            $this->addUser('User' . $i, 20 + $i);
        }

        $page = $this->users()->orderBy('age')->paginate(3, 3);

        self::assertSame(7, $page->total);
        self::assertSame(3, $page->lastPage());
        self::assertFalse($page->hasMorePages());
        self::assertCount(1, $page->items);
        self::assertSame('User7', $page->items[0]['name']);
        self::assertSame(['User4', 'User5', 'User6'], $this->users()->orderBy('age')->forPage(2, 3)->pluck('name'));
        self::assertSame(['User6', 'User7'], $this->users()->orderBy('age')->offset(5)->pluck('name'));
    }

    public function testUpsertInsertsAndUpdates(): void
    {
        $this->addUser('Old', 20, 'same@x.test');

        $this->users()->upsert([
            ['email' => 'same@x.test', 'name' => 'Updated', 'age' => 21, 'active' => true],
            ['email' => 'new@x.test', 'name' => 'New', 'age' => 22, 'active' => true],
        ], ['email'], ['name', 'age']);

        self::assertSame(2, $this->users()->count());
        self::assertSame('Updated', $this->users()->where('email', 'same@x.test')->value('name'));
        self::assertSame('New', $this->users()->where('email', 'new@x.test')->value('name'));
    }

    /**
     * DB-2: like insert(), upsert() splits rows that exceed the database's limit on bound
     * values (8,500 rows x 8 columns = 68,000; SQLite allows 32,766, MySQL and PostgreSQL 65,535).
     */
    public function testUpsertAboveTheBindingLimit(): void
    {
        $row = static fn(int $i, string $name): array => ['public_id' => null, 'name' => $name, 'email' => "u{$i}@x.test", 'age' => $i % 90, 'active' => true, 'status' => 'active', 'priority' => 1, 'created_at' => '2026-10-27 10:00:00'];
        $this->users()->insert(array_map(static fn(int $i): array => $row($i, 'Old'), range(1, 100)));

        $this->users()->upsert(array_map(static fn(int $i): array => $row($i, 'New'), range(1, 8500)), ['email'], ['name']);

        self::assertSame(8500, $this->users()->count());
        self::assertSame(0, $this->users()->where('name', 'Old')->count(), 'existing rows were updated');
    }

    public function testUpdateAllAndDeleteAllAreExplicit(): void
    {
        $this->addUser('A');
        $this->addUser('B');

        try {
            $this->users()->update(['age' => 1]);
            self::fail('update() without where must throw.');
        } catch (DatabaseException) {
        }

        self::assertSame(2, $this->users()->updateAll(['age' => 1]));
        self::assertSame(2, $this->users()->deleteAll());
    }

    /**
     * DB-1: a filter group that ended up empty must not turn update() or delete()
     * into a whole-table change ("where 1 = 1").
     */
    public function testAnEmptyFilterGroupNeverChangesEveryRow(): void
    {
        $this->addUser('A');
        $this->addUser('B');
        $filters = self::requestFilters();
        $filtered = fn(): Builder => $this->users()->where(static function (Builder $q) use ($filters): void {
            foreach ($filters as $column => $value) {
                $q->where($column, $value);
            }
        });

        self::assertInstanceOf(DatabaseException::class, self::capture(fn() => $filtered()->delete()));
        self::assertInstanceOf(DatabaseException::class, self::capture(fn() => $filtered()->update(['age' => 99])));
        self::assertSame(2, $this->users()->count(), 'nothing was deleted');
        self::assertSame(0, $this->users()->where('age', 99)->count(), 'nothing was updated');

        self::assertSame(1, $this->users()->where(static fn(Builder $q) => $q->where('name', 'A'))->delete(), 'a real condition inside a group still works');
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

    // Bindings

    public function testTypedBindings(): void
    {
        $this->users()->insert([
            'name' => 'Typed',
            'email' => 'typed@x.test',
            'age' => 40,
            'active' => false,
            'status' => UserStatus::Banned,
            'priority' => Priority::High,
            'created_at' => new DateTimeImmutable('2026-10-25 10:15:00'),
            'public_id' => null,
        ]);

        $row = $this->users()->where('status', UserStatus::Banned)->where('priority', Priority::High)->first();

        self::assertNotNull($row);
        self::assertSame('banned', $row['status']);
        self::assertEquals(9, $row['priority']);
        self::assertFalse((bool) $row['active']);
        self::assertNull($row['public_id']);
        self::assertIsString($row['created_at']);
        self::assertStringStartsWith('2026-10-25 10:15:00', $row['created_at']);
        self::assertSame(1, $this->users()->where('created_at', '>=', new DateTimeImmutable('2026-10-25'))->count());
    }

    public function testUnsupportedBindingIsRejected(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('Cannot bind a value of type stdClass at position 1');

        $this->users()->where('name', new \stdClass())->get();
    }

    // SQL injection regression

    /**
     * @return iterable<string, array{string}>
     */
    public static function maliciousValues(): iterable
    {
        yield 'classic' => ["' OR '1'='1"];
        yield 'stacked' => ["x'; DROP TABLE users; --"];
        yield 'comment' => ['admin\'--'];
        yield 'union' => ["' UNION SELECT name, email FROM users --"];
        yield 'backslash' => ["\\' OR 1=1 #"];
        yield 'unicode quote' => ['ʼ OR 1=1 --'];
    }

    #[DataProvider('maliciousValues')]
    public function testMaliciousValuesAreStoredAndComparedLiterally(string $value): void
    {
        $this->addUser('Victim');

        self::assertSame([], $this->users()->where('name', $value)->get(), 'malicious value must not match other rows');

        $id = $this->users()->insertGetId(['name' => $value, 'email' => 'evil' . md5($value) . '@x.test', 'age' => 1, 'active' => true]);

        self::assertSame($value, $this->users()->where('id', $id)->value('name'));
        self::assertSame(2, $this->users()->count(), 'table must still exist with both rows');
    }

    public function testMaliciousIdentifiersAreRejectedBeforeReachingTheDatabase(): void
    {
        $attempts = [
            fn() => $this->users()->where('name; drop table users', 1)->get(),
            fn() => $this->users()->orderBy('name desc, (select 1)')->get(),
            fn() => $this->users()->select('*, (select password from secrets)')->get(),
            fn() => $this->db->table('users; drop table users')->get(),
        ];

        foreach ($attempts as $attempt) {
            try {
                $attempt();
                self::fail('Malicious identifier was accepted.');
            } catch (DatabaseException $e) {
                self::assertStringContainsString('Invalid SQL identifier', $e->getMessage());
            }
        }

        self::assertSame(0, $this->users()->count());
    }

    // Errors

    public function testQueryExceptionContainsSqlButNotBoundValues(): void
    {
        try {
            $this->db->select('select * from missing_table where token = ?', ['super-secret-token']);
            self::fail('Expected QueryException.');
        } catch (QueryException $e) {
            self::assertStringContainsString('select * from missing_table where token = ?', $e->getMessage());
            self::assertStringContainsString('1 binding', $e->getMessage());
            self::assertStringNotContainsString('super-secret-token', $e->getMessage());
            self::assertSame('select * from missing_table where token = ?', $e->getSql());
            self::assertSame(1, $e->getBindingCount());
            self::assertSame(5, strlen($e->getSqlState()));
        }
    }

    public function testUniqueViolationIsQueryExceptionWithSqlState23(): void
    {
        $this->addUser('A', email: 'dup@x.test');

        try {
            $this->addUser('B', email: 'dup@x.test');
            self::fail('Expected QueryException.');
        } catch (QueryException $e) {
            self::assertStringStartsWith('23', $e->getSqlState());
        }
    }

    // Transactions

    public function testTransactionCommits(): void
    {
        $result = $this->db->transaction(function (Connection $db): string {
            $this->addUser('Committed');

            return 'done';
        });

        self::assertSame('done', $result);
        self::assertSame(1, $this->users()->count());
        self::assertSame(0, $this->db->transactionLevel());
    }

    public function testTransactionRollsBackAndRethrows(): void
    {
        $thrown = self::capture(fn() => $this->db->transaction(function (): never {
            $this->addUser('RolledBack');
            throw new RuntimeException('abort');
        }));

        self::assertInstanceOf(RuntimeException::class, $thrown);
        self::assertSame('abort', $thrown->getMessage());
        self::assertSame(0, $this->users()->count());
        self::assertSame(0, $this->db->transactionLevel());
    }

    public function testNestedTransactionRollsBackOnlyInnerPart(): void
    {
        $this->db->transaction(function (Connection $db): void {
            $this->addUser('Outer');

            try {
                $db->transaction(function (Connection $db): void {
                    self::assertSame(2, $db->transactionLevel());
                    $this->addUser('Inner');
                    throw new RuntimeException('inner failure');
                });
            } catch (RuntimeException) {
            }

            $this->addUser('AfterInner');
        });

        self::assertSame(['AfterInner', 'Outer'], $this->users()->orderBy('name')->pluck('name'));
    }

    public function testOuterRollbackDiscardsCommittedInnerTransaction(): void
    {
        try {
            $this->db->transaction(function (Connection $db): void {
                $db->transaction(fn() => $this->addUser('Inner'));
                throw new RuntimeException('outer failure');
            });
        } catch (RuntimeException) {
        }

        self::assertSame(0, $this->users()->count());
    }

    public function testCallbackThatEndsTransactionItselfIsDetected(): void
    {
        $this->expectException(TransactionException::class);
        $this->expectExceptionMessage('changed the transaction level');

        $this->db->transaction(static function (Connection $db): void {
            $db->commit();
        });
    }

    public function testCommitAndRollbackWithoutTransactionThrow(): void
    {
        try {
            $this->db->commit();
            self::fail('Expected TransactionException.');
        } catch (TransactionException) {
        }

        $this->expectException(TransactionException::class);
        $this->db->rollBack();
    }

    public function testDisconnectInsideTransactionIsRefused(): void
    {
        $this->db->beginTransaction();

        $this->expectException(TransactionException::class);

        $this->db->disconnect();
    }

    // Schema builder

    private function schema(): Schema
    {
        return new Schema($this->db);
    }

    private function supportsRenameColumn(): bool
    {
        if ($this->db->dialect()->name() !== 'mysql') {
            return true;
        }

        $version = $this->db->selectOne('select version() as v')['v'] ?? '';
        $version = is_string($version) ? $version : '';

        // MySQL 8.0+ or MariaDB 10.5+
        return str_contains($version, 'MariaDB') ? version_compare($version, '10.5', '>=') : version_compare($version, '8.0', '>=');
    }

    public function testSchemaCreateInspectAlterAndDrop(): void
    {
        $schema = $this->schema();
        $schema->dropIfExists('schema_probe');

        try {
            $schema->create('schema_probe', static function (Blueprint $table): void {
                $table->id();
                $table->ulid()->unique();
                $table->string('name', 100);
                $table->boolean('active')->default(true);
                $table->decimal('price', 8, 2)->default(0);
                $table->integer('views')->default(0)->index();
                $table->text('note')->nullable();
                $table->json('meta')->nullable();
                $table->date('day')->nullable();
                $table->timestamps();
            });

            self::assertTrue($schema->hasTable('schema_probe'));
            self::assertFalse($schema->hasTable('schema_missing'));
            self::assertTrue($schema->hasColumn('schema_probe', 'public_id'));
            self::assertFalse($schema->hasColumn('schema_probe', 'missing'));

            $id = $this->db->table('schema_probe')->insertGetId(['public_id' => Ulid::generate(), 'name' => 'Widget']);
            $row = $this->db->table('schema_probe')->where('id', $id)->first();
            self::assertNotNull($row);
            self::assertEquals(1, $row['active'], 'boolean default true');
            self::assertEquals(0, $row['views']);

            try {
                $this->db->table('schema_probe')->insert(['public_id' => $row['public_id'], 'name' => 'Duplicate']);
                self::fail('Unique index on public_id must reject duplicates.');
            } catch (QueryException $e) {
                self::assertStringStartsWith('23', $e->getSqlState());
            }

            $schema->table('schema_probe', static function (Blueprint $table): void {
                $table->string('slug')->nullable()->unique();
                $table->dropIndex('schema_probe_views_index');
                $table->dropColumn('views');
            });

            self::assertTrue($schema->hasColumn('schema_probe', 'slug'));
            self::assertFalse($schema->hasColumn('schema_probe', 'views'));

            if ($this->supportsRenameColumn()) {
                $schema->table('schema_probe', static fn(Blueprint $table) => $table->renameColumn('note', 'remarks'));
                self::assertTrue($schema->hasColumn('schema_probe', 'remarks'));
            }

            $schema->rename('schema_probe', 'schema_probe_renamed');
            self::assertTrue($schema->hasTable('schema_probe_renamed'));
            $schema->drop('schema_probe_renamed');
            self::assertFalse($schema->hasTable('schema_probe_renamed'));
        } finally {
            $schema->dropIfExists('schema_probe');
            $schema->dropIfExists('schema_probe_renamed');
        }
    }

    /**
     * Column types and modifiers that Laravel migrations commonly use.
     */
    public function testLaravelStyleColumnTypes(): void
    {
        $schema = $this->schema();
        $schema->dropIfExists('schema_types');
        $schema->dropIfExists('schema_pivot');
        $mysql = $this->db->dialect()->name() === 'mysql';

        try {
            $schema->create('schema_types', static function (Blueprint $table): void {
                $table->id();
                $table->char('country', 2);
                $table->mediumText('summary')->nullable();
                $table->longText('body')->nullable();
                $table->unsignedTinyInteger('level')->default(1);
                $table->unsignedSmallInteger('rank')->default(0);
                $table->unsignedInteger('views')->default(0);
                $table->unsignedBigInteger('bytes')->default(0);
                $table->tinyInteger('mood')->default(0);
                $table->float('ratio')->default(0.5);
                $table->timestamp('published_at')->nullable();
                $table->timestamp('seen_at')->useCurrent();
                $table->enum('status', ['draft', 'live'])->default('draft');
                $table->rememberToken();
                $table->softDeletes();
            });

            $body = str_repeat('Lorem ipsum ', 6000); // 72,000 characters: more than MySQL's text
            $id = $this->db->table('schema_types')->insertGetId(['country' => 'IN', 'body' => $body]);
            $row = $this->db->table('schema_types')->where('id', $id)->first();
            self::assertNotNull($row);
            self::assertSame('draft', $row['status']);
            self::assertSame($body, $row['body']);
            self::assertEquals(1, $row['level']);
            self::assertEquals(0.5, $row['ratio']);
            self::assertNotNull($row['seen_at'], 'useCurrent() fills the date and time');
            self::assertNull($row['remember_token']);
            self::assertNull($row['deleted_at']);

            $this->db->table('schema_types')->where('id', $id)->update(['status' => 'live', 'published_at' => '2026-10-26 10:00:00']);
            self::assertSame('live', $this->db->table('schema_types')->where('id', $id)->value('status'));

            try {
                $this->db->table('schema_types')->insert(['country' => 'IN', 'status' => 'deleted']);
                self::fail('The enum CHECK constraint must reject unknown values.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }

            $schema->table('schema_types', static function (Blueprint $table): void {
                $table->string('nickname')->nullable()->after('country');
            });
            self::assertTrue($schema->hasColumn('schema_types', 'nickname'));

            if ($mysql) {
                $columns = array_column($this->db->select('select column_name as c from information_schema.columns where table_schema = database() and table_name = ? order by ordinal_position', ['schema_types']), 'c');
                self::assertSame(['id', 'country', 'nickname', 'summary'], array_slice($columns, 0, 4), 'after() places the column on MySQL');
            }

            $schema->create('schema_pivot', static function (Blueprint $table): void {
                $table->unsignedBigInteger('role_id');
                $table->unsignedBigInteger('user_id');
                $table->primary(['role_id', 'user_id']);
            });
            $this->db->table('schema_pivot')->insert(['role_id' => 1, 'user_id' => 1]);

            try {
                $this->db->table('schema_pivot')->insert(['role_id' => 1, 'user_id' => 1]);
                self::fail('The composite primary key must reject duplicates.');
            } catch (QueryException $e) {
                self::assertStringStartsWith('23', $e->getSqlState());
            }

            if ($this->db->dialect()->name() === 'sqlite') {
                $error = self::capture(fn() => $schema->table('schema_types', static fn(Blueprint $t) => $t->primary('country')));
                self::assertInstanceOf(DatabaseException::class, $error);
                self::assertStringContainsString('SQLite cannot add a primary key', $error->getMessage());
            }
        } finally {
            $schema->dropIfExists('schema_types');
            $schema->dropIfExists('schema_pivot');
        }
    }

    public function testForeignKeyCascade(): void
    {
        $schema = $this->schema();
        $schema->dropIfExists('schema_child');
        $schema->dropIfExists('schema_parent');

        try {
            $schema->create('schema_parent', static function (Blueprint $table): void {
                $table->id();
                $table->string('name');
            });
            $schema->create('schema_child', static function (Blueprint $table): void {
                $table->id();
                $table->foreignId('parent_id')->constrained('schema_parent')->cascadeOnDelete();
            });

            $parent = $this->db->table('schema_parent')->insertGetId(['name' => 'p']);
            $this->db->table('schema_child')->insert([['parent_id' => $parent], ['parent_id' => $parent]]);

            try {
                $this->db->table('schema_child')->insert(['parent_id' => 999999]);
                self::fail('Foreign key must reject unknown parent.');
            } catch (QueryException $e) {
                self::assertStringStartsWith('23', $e->getSqlState());
            }

            $this->db->table('schema_parent')->where('id', $parent)->delete();
            self::assertSame(0, $this->db->table('schema_child')->count(), 'children must be deleted by cascade');
        } finally {
            $schema->dropIfExists('schema_child');
            $schema->dropIfExists('schema_parent');
        }
    }

    // Migrations

    private function migrationDirectory(): string
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'veyrophp-migrations-' . bin2hex(random_bytes(6));
        mkdir($dir);

        return $dir;
    }

    private static function writeMigration(string $dir, string $name, string $table, bool $failAfterCreate = false): void
    {
        $fail = $failAfterCreate ? "throw new \\RuntimeException('migration broke');" : '';

        file_put_contents($dir . DIRECTORY_SEPARATOR . $name . '.php', <<<PHP
            <?php
            use VeyroPHP\\Database\\Migration\\Migration;
            use VeyroPHP\\Database\\Schema\\Blueprint;
            use VeyroPHP\\Database\\Schema\\Schema;

            return new class extends Migration {
                public function up(Schema \$schema): void
                {
                    \$schema->create('{$table}', static function (Blueprint \$t): void {
                        \$t->id();
                        \$t->string('name');
                    });
                    {$fail}
                }

                public function down(Schema \$schema): void
                {
                    \$schema->drop('{$table}');
                }
            };
            PHP);
    }

    private function cleanUpMigrations(string $dir, string ...$tables): void
    {
        foreach (glob($dir . '/*.php') ?: [] as $file) {
            unlink($file);
        }
        rmdir($dir);

        $schema = $this->schema();
        foreach ([...$tables, 'migrations'] as $table) {
            $schema->dropIfExists($table);
        }
    }

    public function testMigratorRunsRollsBackAndReportsStatus(): void
    {
        $dir = $this->migrationDirectory();
        $this->schema()->dropIfExists('migrations');

        try {
            self::writeMigration($dir, '2026_10_26_000002_create_mig_beta', 'mig_beta');
            self::writeMigration($dir, '2026_10_26_000001_create_mig_alpha', 'mig_alpha');
            $migrator = new Migrator($this->db, $dir);

            self::assertSame(['2026_10_26_000001_create_mig_alpha', '2026_10_26_000002_create_mig_beta'], $migrator->run());
            self::assertSame([], $migrator->run(), 'nothing left to run');
            self::assertTrue($this->schema()->hasTable('mig_alpha'));

            self::writeMigration($dir, '2026_10_26_000003_create_mig_gamma', 'mig_gamma');
            self::assertSame(['2026_10_26_000003_create_mig_gamma'], $migrator->pending());
            $migrator->run();

            self::assertSame([
                ['migration' => '2026_10_26_000001_create_mig_alpha', 'ran' => true, 'batch' => 1],
                ['migration' => '2026_10_26_000002_create_mig_beta', 'ran' => true, 'batch' => 1],
                ['migration' => '2026_10_26_000003_create_mig_gamma', 'ran' => true, 'batch' => 2],
            ], $migrator->status());

            self::assertSame(['2026_10_26_000003_create_mig_gamma'], $migrator->rollback());
            self::assertFalse($this->schema()->hasTable('mig_gamma'));
            self::assertTrue($this->schema()->hasTable('mig_beta'));

            self::assertSame(['2026_10_26_000002_create_mig_beta', '2026_10_26_000001_create_mig_alpha'], $migrator->rollback());
            self::assertFalse($this->schema()->hasTable('mig_alpha'));
            self::assertSame([], $migrator->rollback());
        } finally {
            $this->cleanUpMigrations($dir, 'mig_alpha', 'mig_beta', 'mig_gamma');
        }
    }

    public function testFailingMigrationIsReportedAndRolledBackWhereSupported(): void
    {
        $dir = $this->migrationDirectory();
        $this->schema()->dropIfExists('migrations');

        try {
            self::writeMigration($dir, '2026_10_26_000001_create_mig_broken', 'mig_broken', failAfterCreate: true);
            $transactional = $this->db->dialect()->supportsTransactionalDdl();

            try {
                (new Migrator($this->db, $dir))->run();
                self::fail('Expected MigrationException.');
            } catch (MigrationException $e) {
                self::assertStringContainsString('mig_broken', $e->getMessage());
                self::assertStringContainsString('migration broke', $e->getMessage());
                self::assertStringContainsString($transactional ? 'were rolled back' : 'may have been applied', $e->getMessage());
            }

            self::assertSame(!$transactional, $this->schema()->hasTable('mig_broken'));
            self::assertSame(0, $this->db->table('migrations')->count(), 'a failed migration is never recorded');
        } finally {
            $this->cleanUpMigrations($dir, 'mig_broken');
        }
    }

    // Diagnostics

    public function testExplainReturnsAPlan(): void
    {
        $this->addUser('Plan');

        $plan = $this->users()->where('email', 'plan@example.test')->explain();

        self::assertNotEmpty($plan);
        self::assertNotEmpty($plan[0], 'each plan row has database-specific columns');
    }

    public function testQueryCountIsObservable(): void
    {
        $count = 0;
        $this->db->listen(static function () use (&$count): void {
            $count++;
        });

        $this->addUser('Counted');
        $this->users()->where('name', 'Counted')->first();
        $this->users()->count();

        self::assertSame(3, $count);
    }

    public function testReconnectsLazilyAfterDisconnect(): void
    {
        $this->addUser('Before');
        $this->db->disconnect();

        self::assertFalse($this->db->isConnected());
        self::assertGreaterThanOrEqual(0, $this->db->table('posts')->count());
        self::assertTrue($this->db->isConnected());
    }
    /**
     * @return list<array<string, mixed>>
     */
    private static function manyUsers(int $count, string $prefix = 'bulk'): array
    {
        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $rows[] = ['name' => "User $i", 'email' => "$prefix$i@example.test", 'age' => $i % 90, 'status' => 'active'];
        }

        return $rows;
    }

    public function testBulkInsertAboveTheBindingLimitIsSplit(): void
    {
        // 70,000 values: more than SQLite (999), MySQL and PostgreSQL (65,535) allow in one statement.
        self::assertSame(17_500, $this->db->table('users')->insert(self::manyUsers(17_500)));
        self::assertSame(17_500, $this->db->table('users')->count());
        self::assertSame('User 17500', $this->db->table('users')->where('email', 'bulk17500@example.test')->value('name'));
    }

    public function testSplitBulkInsertIsAllOrNothing(): void
    {
        $this->db->table('users')->insert(['name' => 'Existing', 'email' => 'bulk17000@example.test']);

        // The duplicate email is in a late chunk; the earlier chunks must be rolled back.
        $error = self::capture(fn() => $this->db->table('users')->insert(self::manyUsers(17_500)));

        self::assertInstanceOf(QueryException::class, $error);
        self::assertSame(1, $this->db->table('users')->count());
        self::assertSame(0, $this->db->transactionLevel());
    }

    public function testChunkByIdReadsEveryRowOnceInOrder(): void
    {
        $this->db->table('users')->insert(self::manyUsers(1_005));
        $seen = [];
        $sizes = [];

        $completed = $this->db->table('users')->where('age', '>=', 0)->chunkById(100, static function (array $rows) use (&$seen, &$sizes): void {
            $sizes[] = count($rows);
            foreach ($rows as $row) {
                $seen[] = is_string($row['email'] ?? null) ? $row['email'] : '';
            }
        });

        self::assertTrue($completed);
        self::assertSame([100, 100, 100, 100, 100, 100, 100, 100, 100, 100, 5], $sizes);
        self::assertCount(1_005, array_unique($seen));
        self::assertSame('bulk1@example.test', $seen[0]);
        self::assertSame('bulk1005@example.test', $seen[1_004]);
    }

    public function testChunkByIdStopsWhenTheCallbackReturnsFalse(): void
    {
        $this->db->table('users')->insert(self::manyUsers(50));
        $calls = 0;

        self::assertFalse($this->db->table('users')->chunkById(10, static function () use (&$calls): bool {
            $calls++;

            return $calls < 2;
        }));
        self::assertSame(2, $calls);
    }

    public function testChunkByIdKeepsFiltersAndToleratesDeletes(): void
    {
        $this->db->table('users')->insert(self::manyUsers(300));
        $count = 0;

        // Deleting rows of the current chunk must not skip rows (no OFFSET is used).
        $this->db->table('users')->where('age', '<', 45)->chunkById(25, function (array $rows) use (&$count): void {
            $count += count($rows);
            $this->db->table('users')->whereIn('id', array_column($rows, 'id'))->delete();
        });

        // Ages are i % 90 for i = 1..300: 3 full cycles of 45 plus 30 more.
        self::assertSame(165, $count);
        self::assertSame(0, $this->db->table('users')->where('age', '<', 45)->count());
    }

    public function testLazyByIdYieldsRowsWithFlatMemory(): void
    {
        $this->db->table('users')->insert(self::manyUsers(2_000));
        $count = 0;
        $last = 0;

        foreach ($this->db->table('users')->lazyById(250) as $index => $row) {
            self::assertSame($count, $index);
            $id = $row['id'] ?? null;
            self::assertTrue(is_int($id) || (is_string($id) && ctype_digit($id)), 'numeric id');
            self::assertGreaterThan($last, (int) $id);
            $last = (int) $id;
            $count++;
        }

        self::assertSame(2_000, $count);
    }

    public function testCursorPaginateWalksAllPagesWithoutCount(): void
    {
        $this->db->table('users')->insert(self::manyUsers(45));
        $emails = [];
        $cursor = null;
        $pages = 0;

        do {
            $page = $this->db->table('users')->cursorPaginate(20, $cursor);
            $emails = [...$emails, ...array_column($page->items, 'email')];
            $cursor = $page->nextCursor;
            $pages++;
        } while ($cursor !== null);

        self::assertSame(3, $pages);
        self::assertCount(45, array_unique(array_map(static fn(mixed $email): string => is_string($email) ? $email : '', $emails)));
        self::assertSame(['data', 'per_page', 'next_cursor'], array_keys($page->jsonSerialize()));

        // An exact multiple: the last full page has no next cursor.
        $this->db->table('users')->where('id', '>', 0)->delete();
        $this->db->table('users')->insert(self::manyUsers(40, 'even'));
        $first = $this->db->table('users')->cursorPaginate(20);
        $second = $this->db->table('users')->cursorPaginate(20, $first->nextCursor);
        self::assertNotNull($first->nextCursor);
        self::assertCount(20, $second->items);
        self::assertNull($second->nextCursor);
    }

    public function testCursorPaginateDescendingAndInvalidCursors(): void
    {
        $this->db->table('users')->insert(self::manyUsers(5));

        $page = $this->db->table('users')->cursorPaginate(2, null, 'id', 'desc');
        self::assertSame(['bulk5@example.test', 'bulk4@example.test'], array_column($page->items, 'email'));
        $next = $this->db->table('users')->cursorPaginate(2, $page->nextCursor, 'id', 'desc');
        self::assertSame(['bulk3@example.test', 'bulk2@example.test'], array_column($next->items, 'email'));

        foreach (['', 'not base64!', base64_encode('{"k":[1]}'), 'eyJ4IjoxfQ', str_repeat('A', 600)] as $bad) {
            $error = self::capture(fn() => $this->db->table('users')->cursorPaginate(2, $bad));
            self::assertInstanceOf(InvalidCursorException::class, $error, $bad);
            self::assertSame(400, $error->getStatusCode());
        }
    }

    /**
     * "bulk7@example.test" becomes "bulk7".
     */
    private static function localPart(mixed $email): string
    {
        return is_string($email) ? explode('@', $email)[0] : '';
    }

    /**
     * The key condition ("id > last") must apply to the whole filter. Without
     * parentheses, "a or b and id > ?" applies it to b only, and the rows matched
     * by a come back in every chunk (VQ-040).
     *
     * @param \Closure(Builder): Builder $filter
     *
     * @return list<string> the local parts of the emails per chunk, joined by ","
     */
    private function chunkEmails(\Closure $filter, int $size): array
    {
        $chunks = [];

        $filter($this->db->table('users'))->chunkById($size, static function (array $rows) use (&$chunks): bool {
            $chunks[] = implode(',', array_map(static fn(array $row): string => self::localPart($row['email'] ?? null), $rows));

            return count($chunks) < 10; // a loop that never ends would repeat here
        });

        return $chunks;
    }

    public function testKeysetMethodsApplyTheKeyToTheWholeFilterWithOrWhere(): void
    {
        $this->db->table('users')->insert(self::manyUsers(30)); // ages 1..30

        $orFilter = static fn(Builder $query): Builder => $query->where('age', '<', 5)->orWhere('age', '>', 25);
        $expected = ['bulk1,bulk2,bulk3', 'bulk4,bulk26,bulk27', 'bulk28,bulk29,bulk30'];

        self::assertSame($expected, $this->chunkEmails($orFilter, 3), 'chunkById with orWhere');
        self::assertSame(['bulk1,bulk2', 'bulk3,bulk4'], $this->chunkEmails(static fn(Builder $query): Builder => $query->orWhere('age', '<', 3)->orWhere('age', '>', 2)->where('age', '<', 5), 2), 'orWhere first; "a or (b and c)" keeps its meaning');
        self::assertSame($expected, $this->chunkEmails(static fn(Builder $query): Builder => $query->where(static fn(Builder $q): Builder => $q->where('age', '<', 5))->orWhere(static fn(Builder $q): Builder => $q->where('age', '>', 25)), 3), 'nested groups joined by or');
        self::assertSame($expected, $this->chunkEmails(static fn(Builder $query): Builder => $query->whereRaw('age < ?', [5])->orWhereRaw('age > ?', [25]), 3), 'raw conditions joined by or');
        self::assertSame(['bulk1,bulk2,bulk3', 'bulk4'], $this->chunkEmails(static fn(Builder $query): Builder => $query->where('age', '<', 5)->where('age', '>', 0), 3), 'and-only filter unchanged');

        $lazy = [];
        foreach ($orFilter($this->db->table('users'))->lazyById(4) as $row) {
            $lazy[] = self::localPart($row['email'] ?? null);
            if (count($lazy) > 20) {
                break;
            }
        }
        self::assertSame(['bulk1', 'bulk2', 'bulk3', 'bulk4', 'bulk26', 'bulk27', 'bulk28', 'bulk29', 'bulk30'], $lazy, 'lazyById with orWhere');

        $pages = [];
        $cursor = null;
        do {
            $page = $orFilter($this->db->table('users'))->cursorPaginate(4, $cursor);
            $pages[] = implode(',', array_map(self::localPart(...), array_column($page->items, 'email')));
            $cursor = $page->nextCursor;
        } while ($cursor !== null && count($pages) < 10);
        self::assertSame(['bulk1,bulk2,bulk3,bulk4', 'bulk26,bulk27,bulk28,bulk29', 'bulk30'], $pages, 'cursorPaginate with orWhere');

        $descending = $orFilter($this->db->table('users'))->cursorPaginate(4, null, 'id', 'desc');
        $second = $orFilter($this->db->table('users'))->cursorPaginate(4, $descending->nextCursor, 'id', 'desc');
        self::assertSame(['bulk26@example.test', 'bulk4@example.test', 'bulk3@example.test', 'bulk2@example.test'], array_column($second->items, 'email'), 'descending cursor with orWhere');

        // The original query is not changed by the grouping.
        $query = $orFilter($this->db->table('users'));
        $query->chunkById(100, static fn() => null);
        self::assertSame(9, $query->count());
    }

    public function testKeysetMethodsRejectOwnOrderingAndLimits(): void
    {
        foreach ([
            fn() => $this->db->table('users')->orderBy('name')->chunkById(10, static fn() => null),
            fn() => iterator_to_array($this->db->table('users')->limit(5)->lazyById()),
            fn() => $this->db->table('users')->offset(5)->cursorPaginate(),
            fn() => $this->db->table('users')->chunkById(0, static fn() => null),
            fn() => $this->db->table('users')->cursorPaginate(0),
        ] as $call) {
            self::assertInstanceOf(DatabaseException::class, self::capture($call));
        }
    }
    /**
     * Ends this test's own database session from the server side, like a server
     * restart or an idle timeout would. SQLite has no server, so it is skipped.
     */
    private function killOwnConnection(): void
    {
        $driver = $this->db->dialect()->name();
        if ($driver === 'sqlite') {
            self::markTestSkipped('SQLite has no server connection to lose.');
        }

        $pdo = $this->db->pdo();
        try {
            if ($driver === 'mysql') {
                $statement = $pdo->query('SELECT CONNECTION_ID()');
                $id = $statement === false ? null : $statement->fetchColumn();
                self::assertIsNumeric($id);
                $pdo->exec('KILL ' . (int) $id);
            } else {
                $pdo->query('SELECT pg_terminate_backend(pg_backend_pid())');
            }
        } catch (\PDOException) {
            // The session may end while this statement runs.
        }
    }

    public function testReadsSurviveALostConnection(): void
    {
        $this->db->table('users')->insert(['name' => 'Asha', 'email' => 'asha@example.test']);
        $this->killOwnConnection();

        self::assertSame(1, $this->db->table('users')->count());
    }

    public function testWritesAreNeverAppliedTwiceAfterALostConnection(): void
    {
        $this->killOwnConnection();

        $error = self::capture(fn() => $this->db->table('users')->insert(['name' => 'Ravi', 'email' => 'ravi@example.test']));

        // Either it was retried safely (the statement never reached the server) or it failed
        // with a lost-connection error; in both cases there is at most one row.
        if ($error !== null) {
            self::assertInstanceOf(QueryException::class, $error);
            self::assertTrue($error->isLostConnection());
        }
        self::assertLessThanOrEqual(1, $this->db->table('users')->where('email', 'ravi@example.test')->count());

        // And the connection works again.
        $this->db->table('users')->insert(['name' => 'Mira', 'email' => 'mira@example.test']);
        self::assertSame(1, $this->db->table('users')->where('email', 'mira@example.test')->count());
    }

    public function testATransactionLostMidwayLeavesNoPartialData(): void
    {
        if ($this->db->dialect()->name() === 'sqlite') {
            self::markTestSkipped('SQLite has no server connection to lose.');
        }

        $error = self::capture(fn() => $this->db->transaction(function (Connection $db): void {
            $db->table('users')->insert(['name' => 'Half', 'email' => 'half@example.test']);
            $this->killOwnConnection();
            $db->table('users')->insert(['name' => 'Other', 'email' => 'other@example.test']);
        }));

        self::assertInstanceOf(QueryException::class, $error);
        self::assertTrue($error->isLostConnection());
        self::assertSame(0, $this->db->transactionLevel());
        self::assertSame(0, $this->db->table('users')->count(), 'nothing of the lost transaction was committed');
    }

    // Transaction retry (batch B): the real serialization failure test is in PostgresDatabaseTest.

    // Row locks, JSON paths, full-text search (batch B)

    public function testRowLocksAreRefusedOutsideATransactionAndWorkInside(): void
    {
        $this->users()->insert([['name' => 'Asha', 'email' => 'asha@example.test'], ['name' => 'Ravi', 'email' => 'ravi@example.test']]);

        $outside = self::capture(fn() => $this->users()->where('name', 'Asha')->lockForUpdate()->get());
        self::assertInstanceOf(DatabaseException::class, $outside);
        self::assertStringContainsString('inside transaction()', $outside->getMessage());

        $rows = $this->db->transaction(fn(Connection $db): array => [
            $db->table('users')->where('name', 'Asha')->lockForUpdate()->get(),
            $db->table('users')->sharedLock()->count(),
            $db->table('users')->where('name', 'Ravi')->lockForUpdate()->exists(),
        ]);

        self::assertCount(1, $rows[0]);
        self::assertSame(2, $rows[1], 'count() of a locked query runs (no lock clause in aggregates)');
        self::assertTrue($rows[2]);
    }

    public function testALockedRowIsSkippedOrRefusedByAnotherTransaction(): void
    {
        if ($this->db->dialect()->name() === 'sqlite') {
            self::markTestSkipped('SQLite has no row locks; a writing transaction locks the whole file.');
        }

        $this->users()->insert([['name' => 'Locked', 'email' => 'locked@example.test'], ['name' => 'Free', 'email' => 'free@example.test']]);
        $other = $this->createConnection();

        $this->db->beginTransaction();
        $this->db->table('users')->where('name', 'Locked')->lockForUpdate()->get();

        try {
            $other->beginTransaction();
            $noWait = self::capture(fn() => $other->table('users')->where('name', 'Locked')->lockForUpdate(noWait: true)->get());
            self::assertInstanceOf(QueryException::class, $noWait, 'noWait fails at once on a locked row');
            $other->rollBack();

            if (!$this->supportsSkipLocked()) {
                self::markTestIncomplete('SKIP LOCKED needs MySQL 8.0.1+ or MariaDB 10.6+; this server is older. noWait was verified.');
            }

            $other->beginTransaction();
            $visible = $other->table('users')->orderBy('id')->lockForUpdate(skipLocked: true)->pluck('name');
            self::assertSame(['Free'], $visible, 'the locked row is left out');
            $other->rollBack();
        } finally {
            while ($other->transactionLevel() > 0) {
                $other->rollBack();
            }

            $other->disconnect();
            $this->db->rollBack();
        }
    }

    private function supportsSkipLocked(): bool
    {
        if ($this->db->dialect()->name() !== 'mysql') {
            return true;
        }

        $version = $this->db->selectOne('select version() as v')['v'] ?? '';
        $version = is_string($version) ? $version : '';

        if (preg_match('/^(\d+)\.(\d+)\.(\d+)/', $version, $m) !== 1) {
            return false;
        }

        return str_contains(strtolower($version), 'mariadb')
            ? version_compare($m[0], '10.6.0', '>=')
            : version_compare($m[0], '8.0.1', '>=');
    }

    public function testJsonPathQueries(): void
    {
        $this->schema()->dropIfExists('docs');
        $this->schema()->create('docs', static function (Blueprint $table): void {
            $table->id();
            $table->json('meta');
        });

        try {
            $this->db->table('docs')->insert([
                ['meta' => json_encode(['address' => ['city' => 'Jaipur', 'pin' => '302001'], 'stock' => 5, 'tags' => ['sale', 'new'], 'name' => 'दीया'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)],
                ['meta' => json_encode(['address' => ['city' => 'Alwar'], 'stock' => 12, 'tags' => ['new']], JSON_THROW_ON_ERROR)],
                ['meta' => json_encode(['address' => ['city' => 'Jaipur'], 'stock' => 0, 'tags' => []], JSON_THROW_ON_ERROR)],
            ]);

            $ids = function (\Closure $filter): array {
                $query = $filter($this->db->table('docs'));
                self::assertInstanceOf(Builder::class, $query);

                return array_map(static fn(mixed $id): int => is_numeric($id) ? (int) $id : -1, $query->orderBy('id')->pluck('id'));
            };

            self::assertSame([1, 3], $ids(static fn(Builder $q): Builder => $q->whereJsonPath('meta', 'address.city', 'Jaipur')));
            self::assertSame([1], $ids(static fn(Builder $q): Builder => $q->whereJsonPath('meta', 'address.pin', '302001')));
            self::assertSame([2], $ids(static fn(Builder $q): Builder => $q->whereJsonPath('meta', 'stock', '>', 5)), 'numbers compare as numbers (12 > 5, not "12" < "5")');
            self::assertSame([1, 2], $ids(static fn(Builder $q): Builder => $q->whereJsonPath('meta', 'stock', '>=', 5)));
            self::assertSame([1], $ids(static fn(Builder $q): Builder => $q->whereJsonPath('meta', 'tags[0]', 'sale')));
            self::assertSame([2], $ids(static fn(Builder $q): Builder => $q->whereJsonPath('meta', 'tags[0]', 'new')));
            self::assertSame([1], $ids(static fn(Builder $q): Builder => $q->whereJsonPath('meta', 'name', 'दीया')));
            self::assertSame([], $ids(static fn(Builder $q): Builder => $q->whereJsonPath('meta', 'address.missing', 'x')));
            self::assertSame([1, 2], $ids(static fn(Builder $q): Builder => $q->where('id', 2)->orWhere('id', 1)->withGroupedWheres()->whereJsonPath('meta', 'stock', '>', 0)));
        } finally {
            $this->schema()->dropIfExists('docs');
        }
    }

    public function testFullTextSearch(): void
    {
        $this->schema()->dropIfExists('articles');

        if ($this->db->dialect()->name() === 'sqlite') {
            $error = self::capture(fn() => $this->schema()->create('articles', static function (Blueprint $table): void {
                $table->id();
                $table->text('title');
                $table->fullText(['title']);
            }));
            self::assertInstanceOf(DatabaseException::class, $error);
            self::assertStringContainsString('SQLite', $error->getMessage());
            $this->schema()->dropIfExists('articles');

            $query = self::capture(fn() => $this->users()->whereFullText(['name'], 'asha')->get());
            self::assertInstanceOf(DatabaseException::class, $query, 'refused, not imitated with LIKE');

            return;
        }

        $this->schema()->create('articles', static function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->text('body');
            $table->fullText(['title', 'body']);
        });

        try {
            $this->db->table('articles')->insert([
                ['title' => 'Dental clinic opens in Jaipur', 'body' => 'Appointments can now be booked online.'],
                ['title' => 'School results', 'body' => 'The clinic camp for students starts on Monday.'],
                ['title' => 'Handicrafts fair', 'body' => 'Artisans from Alwar show pottery.'],
            ]);

            $titles = $this->db->table('articles')->whereFullText(['title', 'body'], 'clinic')->orderBy('id')->pluck('title');
            self::assertSame(['Dental clinic opens in Jaipur', 'School results'], $titles);
            self::assertSame(['Handicrafts fair'], $this->db->table('articles')->whereFullText(['title', 'body'], 'pottery')->pluck('title'));
            self::assertSame([], $this->db->table('articles')->whereFullText(['title', 'body'], 'cricket')->pluck('title'));
            self::assertSame(1, $this->db->table('articles')->whereFullText(['title', 'body'], 'clinic')->where('title', 'like', 'School%')->count());
        } finally {
            $this->schema()->dropIfExists('articles');
        }
    }
}
