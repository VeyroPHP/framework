<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Unit\Orm;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use VeyroPHP\Database\Connection\Connection;
use VeyroPHP\Database\Connection\ConnectionConfig;
use VeyroPHP\Database\DatabaseManager;
use VeyroPHP\Database\Profiling\QueryExecuted;
use VeyroPHP\Database\Profiling\QueryProfiler;
use VeyroPHP\Database\Schema\Blueprint;
use VeyroPHP\Database\Schema\Schema;
use VeyroPHP\Orm\Exception\MassAssignmentException;
use VeyroPHP\Orm\Exception\ModelNotFoundException;
use VeyroPHP\Orm\Exception\OrmException;
use VeyroPHP\Orm\Exception\RelationNotLoadedException;
use VeyroPHP\Orm\Model;
use VeyroPHP\Orm\ModelQuery;
use VeyroPHP\Orm\Orm;
use VeyroPHP\Orm\Relation;
use VeyroPHP\Support\Ulid;
use VeyroPHP\Tests\Fixtures\Orm\BadTableModel;
use VeyroPHP\Tests\Fixtures\Orm\BlogPost;
use VeyroPHP\Tests\Fixtures\Orm\Comment;
use VeyroPHP\Tests\Fixtures\Orm\Post;
use VeyroPHP\Tests\Fixtures\Orm\Profile;
use VeyroPHP\Tests\Fixtures\Orm\Role;
use VeyroPHP\Tests\Fixtures\Orm\User;

#[CoversClass(Orm::class)]
#[CoversClass(Model::class)]
#[CoversClass(ModelQuery::class)]
#[CoversClass(Relation::class)]
#[CoversClass(OrmException::class)]
#[CoversClass(MassAssignmentException::class)]
#[CoversClass(RelationNotLoadedException::class)]
#[CoversClass(ModelNotFoundException::class)]
final class OrmTest extends TestCase
{
    private Connection $db;

    private Orm $orm;

    private QueryProfiler $profiler;

    protected function setUp(): void
    {
        $this->db = (new DatabaseManager(['m' => new ConnectionConfig('m', 'sqlite', ':memory:')], 'm'))->connection();
        $schema = new Schema($this->db);

        $schema->create('users', static function (Blueprint $t): void {
            $t->id();
            $t->ulid()->unique();
            $t->string('name');
            $t->string('email')->unique();
            $t->string('password')->nullable();
            $t->boolean('is_admin')->default(false);
            $t->dateTime('email_verified_at')->nullable();
            $t->json('settings')->nullable();
            $t->double('score')->nullable();
            $t->timestamps();
        });
        $schema->create('posts', static function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->string('title');
            $t->date('published_on')->nullable();
            $t->timestamps();
        });
        $schema->create('comments', static function (Blueprint $t): void {
            $t->id();
            $t->foreignId('post_id')->constrained('posts')->cascadeOnDelete();
            $t->string('body');
        });
        $schema->create('profiles', static function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id')->constrained('users');
            $t->string('bio');
        });
        $schema->create('roles', static function (Blueprint $t): void {
            $t->id();
            $t->string('name')->unique();
        });
        $schema->create('role_user', static function (Blueprint $t): void {
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $t->unique(['user_id', 'role_id']);
        });

        $this->orm = new Orm($this->db, static fn(): DateTimeImmutable => new DateTimeImmutable('2026-10-26 10:00:00'));
        $this->profiler = new QueryProfiler();
        $this->db->listen($this->profiler->listener());
    }

    private function user(string $name): User
    {
        return $this->orm->create(User::class, ['name' => $name, 'email' => strtolower($name) . '@example.test']);
    }

    /**
     * @return list<string>
     */
    private function sql(): array
    {
        return array_map(static fn(QueryExecuted $q): string => $q->sql, $this->profiler->queries());
    }

    // CRUD

    public function testCreateAssignsIdPublicIdAndTimestamps(): void
    {
        $user = $this->user('Ravi');

        self::assertTrue($user->exists());
        self::assertIsInt($user->getKey());
        self::assertNotNull($user->getPublicId());
        self::assertTrue(Ulid::isValid((string) $user->getPublicId()));
        self::assertSame('2026-10-26 10:00:00', $user->raw('created_at'));
        self::assertSame('2026-10-26 10:00:00', $user->raw('updated_at'));
        self::assertFalse($user->isDirty());
    }

    public function testFindAndFindByPublicId(): void
    {
        $user = $this->user('Ravi');

        $byId = $this->orm->find(User::class, (int) $user->getKey());
        $byPublicId = $this->orm->findByPublicId(User::class, (string) $user->getPublicId());

        self::assertInstanceOf(User::class, $byId);
        self::assertSame('Ravi', $byId->get('name'));
        self::assertSame($user->getKey(), $byPublicId?->getKey());
        self::assertNull($this->orm->find(User::class, 999));
        self::assertNull($this->orm->findByPublicId(User::class, Ulid::generate()));
    }

    public function testInvalidPublicIdDoesNotQuery(): void
    {
        $this->profiler->reset();

        self::assertNull($this->orm->findByPublicId(User::class, "1' OR '1'='1"));
        self::assertNull($this->orm->findByPublicId(User::class, '42'));
        self::assertSame(0, $this->profiler->count());
    }

    public function testFindOrFailThrowsNotFoundWith404(): void
    {
        try {
            $this->orm->findOrFail(User::class, 999);
            self::fail('Expected ModelNotFoundException.');
        } catch (ModelNotFoundException $e) {
            self::assertSame(404, $e->getStatusCode());
            self::assertSame('Not Found', $e->getMessage());
            self::assertSame(User::class, $e->getModel());
        }
    }

    public function testUpdateWritesOnlyChangedColumnsAndTouchesUpdatedAt(): void
    {
        $user = $this->user('Ravi');
        $orm = new Orm($this->db, static fn(): DateTimeImmutable => new DateTimeImmutable('2026-10-27 08:30:00'));
        $this->profiler->reset();

        $user->set('name', 'Ravi Kumar');
        $orm->save($user);

        self::assertSame(['update "users" set "name" = ?, "updated_at" = ? where "id" = ?'], $this->sql());
        self::assertSame('2026-10-27 08:30:00', $user->raw('updated_at'));
        self::assertSame('2026-10-26 10:00:00', $user->raw('created_at'));
        self::assertSame('Ravi Kumar', $orm->findOrFail(User::class, (int) $user->getKey())->get('name'));
    }

    public function testSavingAnUnchangedModelRunsNoQuery(): void
    {
        $user = $this->user('Ravi');
        $this->profiler->reset();

        $this->orm->save($user);

        self::assertSame(0, $this->profiler->count());
    }

    public function testDelete(): void
    {
        $user = $this->user('Ravi');

        $this->orm->delete($user);

        self::assertFalse($user->exists());
        self::assertNull($this->orm->find(User::class, (int) $user->getKey()));

        $this->expectException(OrmException::class);
        $this->orm->delete(new User());
    }

    // Mass assignment, casts and output

    public function testFillRejectsAttributesOutsideFillable(): void
    {
        try {
            (new User())->fill(['name' => 'x', 'is_admin' => true, 'password' => 'p']);
            self::fail('Expected MassAssignmentException.');
        } catch (MassAssignmentException $e) {
            self::assertStringContainsString('"is_admin", "password"', $e->getMessage());
        }

        $user = (new User())->forceFill(['is_admin' => true]);
        self::assertTrue($user->get('is_admin'));
    }

    public function testCastsRoundTripThroughTheDatabase(): void
    {
        $user = (new User())->fill(['name' => 'Asha', 'email' => 'asha@example.test', 'settings' => ['theme' => 'dark', 'langs' => ['hi', 'en']]]);
        $user->set('is_admin', true);
        $user->set('email_verified_at', new DateTimeImmutable('2026-01-02 03:04:05'));
        $user->set('score', '9.5');
        $this->orm->save($user);

        $loaded = $this->orm->findOrFail(User::class, (int) $user->getKey());

        self::assertTrue($loaded->get('is_admin'));
        self::assertSame(['theme' => 'dark', 'langs' => ['hi', 'en']], $loaded->get('settings'));
        self::assertSame(9.5, $loaded->get('score'));
        $verified = $loaded->get('email_verified_at');
        self::assertInstanceOf(DateTimeImmutable::class, $verified);
        self::assertSame('2026-01-02 03:04:05', $verified->format('Y-m-d H:i:s'));
        self::assertSame('2026-01-02 03:04:05', $loaded->raw('email_verified_at'));
    }

    public function testInvalidCastValuesThrow(): void
    {
        foreach ([['score', 'abc'], ['settings', 'not-an-array'], ['email_verified_at', 'not a date']] as [$attribute, $value]) {
            try {
                (new User())->set($attribute, $value);
                self::fail("Expected OrmException for {$attribute}.");
            } catch (OrmException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testToArrayHidesHiddenAttributesFormatsDatesAndRunsNoQueries(): void
    {
        $user = $this->user('Ravi');
        $user->set('password', password_hash('secret', PASSWORD_DEFAULT));
        $user->set('email_verified_at', '2026-01-02 03:04:05');
        $this->orm->save($user);
        $post = $this->orm->create(Post::class, ['title' => 'Hello', 'user_id' => $user->getKey()]);
        $post->set('published_on', '2026-03-04');
        $this->orm->save($post);

        $loaded = $this->orm->query(User::class)->with('posts')->firstOrFail();
        $this->profiler->reset();

        $array = $loaded->toArray();
        $json = json_encode($loaded, JSON_THROW_ON_ERROR);

        self::assertSame(0, $this->profiler->count(), 'serialization must never query');
        self::assertArrayNotHasKey('password', $array);
        self::assertStringNotContainsString('password', $json);
        self::assertSame('2026-01-02T03:04:05' . (new DateTimeImmutable('2026-01-02 03:04:05'))->format('P'), $array['email_verified_at']);
        $posts = $array['posts'] ?? null;
        self::assertIsArray($posts);
        self::assertIsArray($posts[0] ?? null);
        self::assertSame('2026-03-04', $posts[0]['published_on'] ?? null);
    }

    public function testMagicPropertiesReadAttributesAndLoadedRelations(): void
    {
        $user = $this->user('Ravi');
        $user->name = 'Changed';

        self::assertSame('Changed', $user->name);
        self::assertTrue(isset($user->name));
        self::assertFalse(isset($user->nothing));
    }

    public function testTableNamesAndInvalidConstants(): void
    {
        self::assertSame('blog_posts', BlogPost::table());

        $this->expectException(OrmException::class);
        $this->expectExceptionMessage('BadTableModel::TABLE must be a string');
        BadTableModel::table();
    }

    // Relations

    public function testReadingAnUnloadedRelationThrowsInsteadOfQuerying(): void
    {
        $user = $this->user('Ravi');
        $this->profiler->reset();

        try {
            $user->getRelation('posts');
            self::fail('Expected RelationNotLoadedException.');
        } catch (RelationNotLoadedException $e) {
            self::assertStringContainsString("->with('posts')", $e->getMessage());
        }

        self::assertSame(0, $this->profiler->count());
    }

    public function testMagicRelationPropertyAlsoThrowsWhenNotLoaded(): void
    {
        $user = $this->user('Ravi');

        $this->expectException(RelationNotLoadedException::class);

        self::assertSame([], $user->posts);
    }

    public function testUnknownRelationThrows(): void
    {
        $this->expectException(OrmException::class);
        $this->expectExceptionMessage('has no relation "followers"');

        $this->orm->query(User::class)->with('followers')->get();
        $this->orm->load($this->user('Ravi'), 'followers');
    }

    public function testEagerLoadingUsesOneQueryPerRelationRegardlessOfRowCount(): void
    {
        foreach (range(1, 10) as $i) {
            $user = $this->user('User' . $i);
            $this->orm->create(Post::class, ['title' => "Post A{$i}", 'user_id' => $user->getKey()]);
            $this->orm->create(Post::class, ['title' => "Post B{$i}", 'user_id' => $user->getKey()]);
        }
        $this->profiler->reset();

        $users = $this->orm->query(User::class)->with('posts')->orderBy('id')->get();

        self::assertCount(10, $users);
        self::assertSame(2, $this->profiler->count(), '1 query for users + 1 for all posts');
        self::assertSame(['select * from "users" order by "id" asc', 'select * from "posts" where "user_id" in (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'], $this->sql());

        $posts = $users[3]->posts;
        self::assertSame(['Post A4', 'Post B4'], array_map(static fn(Model $p): mixed => $p->get('title'), $posts));
    }

    public function testNestedAndMultipleRelations(): void
    {
        $ravi = $this->user('Ravi');
        $asha = $this->user('Asha');
        $post = $this->orm->create(Post::class, ['title' => 'One', 'user_id' => $ravi->getKey()]);
        $this->orm->create(Comment::class, ['post_id' => $post->getKey(), 'body' => 'Nice']);
        $this->orm->create(Comment::class, ['post_id' => $post->getKey(), 'body' => 'Thanks']);
        $this->db->table('profiles')->insert(['user_id' => $ravi->getKey(), 'bio' => 'Developer']);
        $this->profiler->reset();

        $users = $this->orm->query(User::class)->with('posts.comments', 'profile')->orderBy('id')->get();

        self::assertSame(4, $this->profiler->count(), 'users, posts, comments, profiles');
        [$loadedRavi, $loadedAsha] = $users;

        $posts = $loadedRavi->getRelation('posts');
        self::assertIsArray($posts);
        $comments = $posts[0]->getRelation('comments');
        self::assertIsArray($comments);
        self::assertCount(2, $comments);

        self::assertInstanceOf(Profile::class, $loadedRavi->profile);
        self::assertNull($loadedAsha->profile, 'hasOne without row is null');
        self::assertSame([], $loadedAsha->posts, 'hasMany without rows is an empty list');
        self::assertSame($asha->getKey(), $loadedAsha->getKey());
    }

    public function testBelongsToLoadsEachParentOnce(): void
    {
        $ravi = $this->user('Ravi');
        foreach (range(1, 5) as $i) {
            $this->orm->create(Post::class, ['title' => "P{$i}", 'user_id' => $ravi->getKey()]);
        }
        $this->profiler->reset();

        $posts = $this->orm->query(Post::class)->with('author')->get();

        self::assertSame(2, $this->profiler->count());
        self::assertSame('select * from "users" where "id" in (?)', $this->sql()[1], 'duplicate foreign keys are queried once');
        $author = $posts[4]->author;
        self::assertInstanceOf(User::class, $author);
        self::assertSame('Ravi', $author->get('name'));
    }

    public function testLoadAfterFetching(): void
    {
        $user = $this->user('Ravi');
        $this->orm->create(Post::class, ['title' => 'Later', 'user_id' => $user->getKey()]);

        $this->orm->load($user, 'posts');

        $posts = $user->posts;
        self::assertCount(1, $posts);
        $this->orm->load([], 'posts'); // no-op
    }

    public function testLargeKeyListsAreChunked(): void
    {
        $rows = [];
        for ($i = 1; $i <= 600; $i++) {
            $rows[] = ['public_id' => Ulid::generate(), 'name' => "U{$i}", 'email' => "u{$i}@example.test", 'is_admin' => false];
        }
        $this->db->table('users')->insert($rows);
        $this->profiler->reset();

        $users = $this->orm->query(User::class)->with('posts')->get();

        self::assertCount(600, $users);
        self::assertSame(3, $this->profiler->count(), 'users + 2 chunks of at most 500 keys');
    }

    public function testBelongsToManyAttachDetachSync(): void
    {
        $user = $this->user('Ravi');
        $admin = $this->orm->create(Role::class, ['name' => 'admin']);
        $editor = $this->orm->create(Role::class, ['name' => 'editor']);
        $viewer = $this->orm->create(Role::class, ['name' => 'viewer']);

        $this->orm->attach($user, 'roles', [(int) $admin->getKey(), (int) $editor->getKey()]);
        $this->orm->load($user, 'roles');
        self::assertSame(['admin', 'editor'], self::roleNames($user));

        $this->orm->detach($user, 'roles', (int) $admin->getKey());
        self::assertFalse($user->relationLoaded('roles'), 'a changed pivot drops the stale relation');
        $this->orm->load($user, 'roles');
        self::assertSame(['editor'], self::roleNames($user));

        $result = $this->orm->sync($user, 'roles', [(int) $viewer->getKey(), (int) $admin->getKey()]);
        self::assertSame([(int) $viewer->getKey(), (int) $admin->getKey()], $result['attached']);
        self::assertSame([(int) $editor->getKey()], $result['detached']);

        $loaded = $this->orm->query(User::class)->with('roles')->firstOrFail();
        self::assertSame(['admin', 'viewer'], self::roleNames($loaded));

        self::assertSame(['attached' => [], 'detached' => []], $this->orm->sync($user, 'roles', [(int) $viewer->getKey(), (int) $admin->getKey()]));
        self::assertSame(2, $this->orm->detach($user, 'roles'));
    }

    public function testPivotOperationsRequireBelongsToMany(): void
    {
        $this->expectException(OrmException::class);

        $this->orm->attach($this->user('Ravi'), 'posts', 1);
    }

    public function testModelQueryFiltersAndCounts(): void
    {
        foreach (['Asha', 'Bharat', 'Chetan'] as $name) {
            $this->user($name);
        }

        $query = $this->orm->query(User::class)->where('name', '!=', 'Bharat')->orderByDesc('name');

        self::assertSame(2, $this->orm->query(User::class)->whereIn('name', ['Asha', 'Chetan'])->count());
        self::assertTrue($this->orm->query(User::class)->where('name', 'Asha')->exists());
        self::assertSame(['Chetan', 'Asha'], array_map(static fn(User $u): mixed => $u->get('name'), $query->get()));
        self::assertSame('Bharat', $this->orm->query(User::class)->orderBy('name')->forPage(2, 1)->firstOrFail()->get('name'));

        $this->expectException(ModelNotFoundException::class);
        $this->orm->query(User::class)->where('name', 'Nobody')->firstOrFail();
    }

    /**
     * @return list<mixed>
     */
    private static function roleNames(User $user): array
    {
        $roles = $user->getRelation('roles');
        self::assertIsArray($roles);
        $names = array_map(static fn(Model $role): mixed => $role->get('name'), $roles);
        sort($names);

        return $names;
    }
    // Large tables

    private function seedUsersWithPosts(int $users): void
    {
        for ($i = 1; $i <= $users; $i++) {
            $user = $this->user('Bulk' . $i);
            $this->orm->create(Post::class, ['user_id' => $user->get('id'), 'title' => 'Post of ' . $i]);
        }
    }

    public function testChunkByIdHydratesModelsAndEagerLoadsPerChunk(): void
    {
        $this->seedUsersWithPosts(25);
        $this->profiler->reset();
        $sizes = [];
        $titles = [];

        $this->orm->query(User::class)->with('posts')->chunkById(10, static function (array $users) use (&$sizes, &$titles): void {
            $sizes[] = count($users);
            foreach ($users as $user) {
                self::assertInstanceOf(User::class, $user);
                self::assertTrue($user->relationLoaded('posts'));
                $posts = $user->getRelation('posts');
                self::assertIsArray($posts);
                $titles[] = $posts[0]->get('title');
            }
        });

        self::assertSame([10, 10, 5], $sizes);
        self::assertSame('Post of 25', $titles[24]);
        // 3 chunks x (1 user query + 1 posts query); no query per user.
        self::assertSame(6, $this->profiler->count());
    }

    public function testLazyByIdYieldsModels(): void
    {
        $this->seedUsersWithPosts(7);
        $names = [];

        foreach ($this->orm->query(User::class)->where('name', '!=', 'Bulk3')->lazyById(3) as $user) {
            $names[] = $user->get('name');
        }

        self::assertSame(['Bulk1', 'Bulk2', 'Bulk4', 'Bulk5', 'Bulk6', 'Bulk7'], $names);
    }

    public function testCursorPaginateReturnsModels(): void
    {
        $this->seedUsersWithPosts(5);

        $first = $this->orm->query(User::class)->cursorPaginate(2);
        $second = $this->orm->query(User::class)->cursorPaginate(2, $first->nextCursor);
        $last = $this->orm->query(User::class)->cursorPaginate(2, $second->nextCursor);

        self::assertCount(2, $first->items);
        self::assertSame(['Bulk3', 'Bulk4'], array_map(static fn(User $u): mixed => $u->get('name'), $second->items));
        self::assertCount(1, $last->items);
        self::assertNull($last->nextCursor);

        // JSON uses the models' toArray(), so hidden attributes stay hidden.
        $json = (string) json_encode($first);
        self::assertStringContainsString('"next_cursor"', $json);
        self::assertStringNotContainsString('password', $json);
    }
}
