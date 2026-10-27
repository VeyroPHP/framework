<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Integration\Orm;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use VeyroPHP\Container\Container;
use VeyroPHP\Database\Connection\Connection;
use VeyroPHP\Database\Connection\ConnectionConfig;
use VeyroPHP\Database\DatabaseManager;
use VeyroPHP\Database\Exception\DatabaseException;
use VeyroPHP\Database\Profiling\QueryProfiler;
use VeyroPHP\Database\Query\Builder;
use VeyroPHP\Database\Schema\Blueprint;
use VeyroPHP\Database\Schema\Schema;
use VeyroPHP\Events\EventDispatcher;
use VeyroPHP\Orm\Events\ModelDeleted;
use VeyroPHP\Orm\Events\ModelDeleting;
use VeyroPHP\Orm\Events\ModelRestored;
use VeyroPHP\Orm\Events\ModelSaved;
use VeyroPHP\Orm\Events\ModelSaving;
use VeyroPHP\Orm\Exception\OrmException;
use VeyroPHP\Orm\Model;
use VeyroPHP\Orm\Orm;
use VeyroPHP\Orm\Relation;
use VeyroPHP\Tests\Fixtures\Orm\Article;
use VeyroPHP\Tests\Fixtures\Orm\Author;
use VeyroPHP\Tests\Fixtures\Orm\Book;
use VeyroPHP\Tests\Fixtures\Orm\Club;
use VeyroPHP\Tests\Fixtures\Orm\Comment;
use VeyroPHP\Tests\Fixtures\Orm\Country;
use VeyroPHP\Tests\Fixtures\Orm\CurrentTeam;
use VeyroPHP\Tests\Fixtures\Orm\Library;
use VeyroPHP\Tests\Fixtures\Orm\Member;
use VeyroPHP\Tests\Fixtures\Orm\Photo;
use VeyroPHP\Tests\Fixtures\Orm\Reader;
use VeyroPHP\Tests\Fixtures\Orm\Remark;
use VeyroPHP\Tests\Fixtures\Orm\Shelf;
use VeyroPHP\Tests\Fixtures\Orm\Story;
use VeyroPHP\Tests\Fixtures\Orm\TeamScope;
use VeyroPHP\Tests\Fixtures\Orm\Video;
use VeyroPHP\Tests\Fixtures\Orm\Visible;

/**
 * Batch B: soft deletes, scopes, model events, hasManyThrough and morph relations,
 * on every supported database. Subclasses provide the connection settings.
 */
abstract class OrmFeaturesTestCase extends TestCase
{
    private const TABLES = ['articles', 'countries', 'authors', 'stories', 'photos', 'videos', 'remarks', 'libraries', 'shelves', 'books', 'readers', 'book_reader', 'members', 'clubs', 'club_member', 'club_member_names'];

    private Connection $db;

    private Orm $orm;

    private EventDispatcher $events;

    private QueryProfiler $profiler;

    private DateTimeImmutable $now;

    /**
     * @return array<string, string>
     */
    abstract protected static function settings(): array;

    protected function setUp(): void
    {
        CurrentTeam::$id = 1;
        $this->now = new DateTimeImmutable('2026-10-27 10:00:00');
        $this->db = (new DatabaseManager(['test' => ConnectionConfig::fromArray('test', static::settings())], 'test'))->connection();
        $schema = new Schema($this->db);
        $this->dropTables();

        $schema->create('articles', static function (Blueprint $t): void {
            $t->id();
            $t->string('title');
            $t->string('slug')->nullable();
            $t->string('status')->default('draft');
            $t->integer('team_id');
            $t->softDeletes();
            $t->timestamps();
        });
        $schema->create('countries', static function (Blueprint $t): void {
            $t->id();
            $t->string('name');
        });
        $schema->create('authors', static function (Blueprint $t): void {
            $t->id();
            $t->integer('country_id');
            $t->string('name');
            $t->softDeletes();
        });
        $schema->create('stories', static function (Blueprint $t): void {
            $t->id();
            $t->integer('author_id');
            $t->string('title');
        });
        foreach (['photos' => 'url', 'videos' => 'title'] as $table => $column) {
            $schema->create($table, static function (Blueprint $t) use ($column): void {
                $t->id();
                $t->string($column);
            });
        }
        $schema->create('remarks', static function (Blueprint $t): void {
            $t->id();
            $t->string('remarkable_type');
            $t->integer('remarkable_id');
            $t->string('body');
        });
        foreach (['libraries', 'readers'] as $table) {
            $schema->create($table, static function (Blueprint $t): void {
                $t->id();
                $t->string('name');
            });
        }
        $schema->create('shelves', static function (Blueprint $t): void {
            $t->id();
            $t->integer('library_id');
            $t->integer('team_id');
        });
        $schema->create('books', static function (Blueprint $t): void {
            $t->id();
            $t->integer('shelf_id');
            $t->integer('team_id');
            $t->string('title');
            $t->softDeletes();
        });
        $schema->create('book_reader', static function (Blueprint $t): void {
            $t->id();
            $t->integer('reader_id');
            $t->integer('book_id');
            $t->integer('team_id');
        });
        $schema->create('members', static function (Blueprint $t): void {
            $t->string('code', 20);
            $t->string('name')->unique();
            $t->primary('code');
        });
        $schema->create('clubs', static function (Blueprint $t): void {
            $t->string('uid', 20);
            $t->string('name')->unique();
            $t->primary('uid');
        });
        foreach (['club_member' => ['member_code', 'club_uid'], 'club_member_names' => ['member_name', 'club_name']] as $table => [$memberColumn, $clubColumn]) {
            $schema->create($table, static function (Blueprint $t) use ($memberColumn, $clubColumn): void {
                $t->string($memberColumn);
                $t->string($clubColumn);
            });
        }

        $this->events = new EventDispatcher(new Container());
        $this->orm = new Orm($this->db, fn(): DateTimeImmutable => $this->now, $this->events);
        $this->profiler = new QueryProfiler();
        $this->db->listen($this->profiler->listener());
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return; // skipped before a connection was made
        }

        while ($this->db->transactionLevel() > 0) {
            $this->db->rollBack();
        }

        $this->dropTables();
        $this->db->disconnect();
    }

    private function dropTables(): void
    {
        $schema = new Schema($this->db);

        foreach (self::TABLES as $table) {
            $schema->dropIfExists($table);
        }
    }

    private function article(string $title, string $status = 'published', int $team = 1): Article
    {
        return $this->orm->create(Article::class, ['title' => $title, 'status' => $status, 'team_id' => $team]);
    }

    /**
     * @param list<Model> $models
     *
     * @return list<string>
     */
    private static function values(array $models, string $column = 'title'): array
    {
        $values = array_map(static fn(Model $model): string => self::text($model->raw($column)), $models);
        sort($values);

        return $values;
    }

    /**
     * @return list<Model>
     */
    private static function many(Model $model, string $relation): array
    {
        $loaded = $model->getRelation($relation);
        self::assertIsArray($loaded);

        return $loaded;
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private function queries(): int
    {
        return count($this->profiler->queries());
    }

    /**
     * Records each model event as "ShortName:flag", in order.
     *
     * @return \ArrayObject<int, string>
     */
    private function recordEvents(): \ArrayObject
    {
        /** @var \ArrayObject<int, string> $seen */
        $seen = new \ArrayObject();

        foreach ([ModelSaving::class, ModelSaved::class, ModelDeleting::class, ModelDeleted::class, ModelRestored::class] as $class) {
            $this->events->listen($class, static function (object $event) use ($seen): void {
                $flag = match (true) {
                    $event instanceof ModelSaving, $event instanceof ModelSaved => $event->creating ? 'create' : 'update',
                    $event instanceof ModelDeleting, $event instanceof ModelDeleted => $event->force ? 'force' : 'soft',
                    default => '',
                };
                $seen[] = (new \ReflectionClass($event))->getShortName() . ':' . $flag;
            });
        }

        return $seen;
    }

    // Soft deletes

    public function testDeleteHidesTheRowAndRestoreBringsItBack(): void
    {
        $article = $this->article('Hello');
        $id = $article->getKey();
        self::assertNotNull($id);

        $this->orm->delete($article);

        self::assertTrue($article->trashed());
        self::assertTrue($article->exists(), 'the row is still there');
        self::assertSame('2026-10-27 10:00:00', $article->raw('deleted_at'));
        self::assertFalse($article->isDirty(), 'the model matches the row');
        self::assertNull($this->orm->find(Article::class, $id));
        self::assertSame(0, $this->orm->query(Article::class)->count());
        self::assertSame(1, $this->orm->query(Article::class)->withTrashed()->count());
        self::assertSame(['Hello'], self::values($this->orm->query(Article::class)->onlyTrashed()->get()));
        self::assertSame(1, $this->db->table('articles')->count());

        $this->orm->restore($article);

        self::assertFalse($article->trashed());
        self::assertNotNull($this->orm->find(Article::class, $id));
        self::assertSame([], $this->orm->query(Article::class)->onlyTrashed()->get());
    }

    public function testSoftDeleteWritesOnlyItsColumn(): void
    {
        $article = $this->article('Kept');
        $article->set('title', 'Unsaved');
        $this->now = new DateTimeImmutable('2026-10-28 09:00:00');

        $this->orm->delete($article);

        $row = $this->db->table('articles')->where('id', $article->getKey())->first();
        self::assertIsArray($row);
        self::assertSame('Kept', $row['title'], 'an unsaved change is not written by delete()');
        self::assertSame('2026-10-27 10:00:00', $row['updated_at']);
        self::assertSame(['title' => 'Unsaved'], $article->getDirty(), 'and it stays unsaved');
    }

    public function testRepeatedDeletesAndRestoresAreNoOps(): void
    {
        $article = $this->article('Twice');
        $seen = $this->recordEvents();

        $this->orm->delete($article);
        $this->now = new DateTimeImmutable('2026-10-29 00:00:00');
        $this->orm->delete($article);

        self::assertSame('2026-10-27 10:00:00', $this->db->table('articles')->where('id', $article->getKey())->value('deleted_at'), 'the first deletion time is kept');

        $this->orm->restore($article);
        $this->orm->restore($article);

        self::assertSame(['ModelDeleting:soft', 'ModelDeleted:soft', 'ModelRestored:'], $seen->getArrayCopy(), 'no event for an operation that changed nothing');
    }

    public function testForceDeleteRemovesTheRow(): void
    {
        $article = $this->article('Gone');
        $this->orm->delete($article);
        $this->orm->forceDelete($article);

        self::assertFalse($article->exists());
        self::assertSame(0, $this->db->table('articles')->count());

        $country = $this->orm->create(Country::class, ['name' => 'India']);
        $this->orm->delete($country); // no SOFT_DELETES: the row goes
        self::assertSame(0, $this->db->table('countries')->count());
    }

    public function testInvalidSoftDeleteUsageThrows(): void
    {
        $country = $this->orm->create(Country::class, ['name' => 'India']);

        foreach ([
            'restore without SOFT_DELETES' => fn() => $this->orm->restore($country),
            'withTrashed without SOFT_DELETES' => fn() => $this->orm->query(Country::class)->withTrashed(),
            'onlyTrashed without SOFT_DELETES' => fn() => $this->orm->query(Country::class)->onlyTrashed(),
            'delete an unsaved model' => fn() => $this->orm->delete(new Article()),
            'restore an unsaved model' => fn() => $this->orm->restore(new Article()),
            'force delete an unsaved model' => fn() => $this->orm->forceDelete(new Article()),
        ] as $case => $call) {
            try {
                $call();
                self::fail($case . ' was accepted');
            } catch (OrmException) {
                self::assertSame(1, $this->db->table('countries')->count(), $case);
            }
        }
    }

    public function testTrashedRowsStayHiddenWithOrWhere(): void
    {
        $this->article('A');
        $this->orm->delete($this->article('B'));

        $found = $this->orm->query(Article::class)->where('title', 'A')->orWhere('title', 'B')->get();

        self::assertSame(['A'], self::values($found), 'not "title = A or title = B and deleted_at is null"');
    }

    public function testEveryReadingMethodRespectsSoftDeletes(): void
    {
        foreach (['A', 'B', 'C'] as $title) {
            $this->article($title);
        }
        $this->orm->delete($this->orm->query(Article::class)->where('title', 'B')->firstOrFail());

        self::assertTrue($this->orm->query(Article::class)->where('title', 'A')->exists());
        self::assertFalse($this->orm->query(Article::class)->where('title', 'B')->exists());

        $chunked = [];
        $this->orm->query(Article::class)->chunkById(1, static function (array $chunk) use (&$chunked): void {
            foreach ($chunk as $article) {
                $chunked[] = self::text($article->raw('title'));
            }
        });
        self::assertSame(['A', 'C'], $chunked);
        self::assertSame(['A', 'C'], self::values(iterator_to_array($this->orm->query(Article::class)->lazyById(1), false)));
        self::assertCount(2, $this->orm->query(Article::class)->cursorPaginate(10)->items);
        self::assertSame(2, $this->orm->query(Article::class)->toBase()->count());
    }

    public function testFirstDoesNotChangeTheQuery(): void
    {
        $this->article('A');
        $this->article('B');
        $query = $this->orm->query(Article::class)->orderBy('id');

        $query->first();

        self::assertCount(2, $query->get(), 'first() used a copy (VQ-053)');
    }

    // Scopes

    public function testLocalScopesAreGroupedAndReusable(): void
    {
        $this->article('Draft', 'draft');
        $this->article('Published');
        $this->article('Featured', 'featured');

        $visible = $this->orm->query(Article::class)->apply(new Visible())->get();
        self::assertSame(['Featured', 'Published'], self::values($visible));

        // "title = Draft and (status = published or status = featured)" matches nothing.
        self::assertSame([], $this->orm->query(Article::class)->where('title', 'Draft')->apply(new Visible())->get());

        $closure = $this->orm->query(Article::class)->apply(static fn(Builder $q) => $q->where('title', 'like', 'F%'))->get();
        self::assertSame(['Featured'], self::values($closure));
    }

    public function testGlobalScopesApplyEverywhereAndCanBeLifted(): void
    {
        $mine = $this->article('Mine', team: 1);
        $this->article('Theirs', team: 2);
        $theirs = $this->orm->query(Article::class)->withoutGlobalScopes(TeamScope::class)->where('title', 'Theirs')->firstOrFail();
        $theirsId = $theirs->getKey();
        self::assertNotNull($theirsId);

        self::assertSame(['Mine'], self::values($this->orm->query(Article::class)->get()));
        self::assertNull($this->orm->find(Article::class, $theirsId), 'find() is scoped too');
        self::assertSame(['Mine', 'Theirs'], self::values($this->orm->query(Article::class)->withoutGlobalScopes()->get()));
        self::assertSame(['Mine'], self::values($this->orm->query(Article::class)->where('title', 'Mine')->orWhere('title', 'Theirs')->get()), 'grouped against "or"');

        CurrentTeam::$id = 2;
        self::assertSame(['Theirs'], self::values($this->orm->query(Article::class)->get()));
        self::assertNotNull($mine->getKey());
    }

    public function testScopeValuesAreBoundNotInlined(): void
    {
        $sql = $this->orm->query(Article::class)->apply(static fn(Builder $q) => $q->where('title', "x' or '1'='1"))->toBase();

        self::assertStringNotContainsString("'1'='1", $sql->toSql());
        self::assertContains("x' or '1'='1", $sql->getBindings());
        self::assertContains(1, $sql->getBindings(), 'the global scope value is bound too');
    }

    public function testWritesThroughToBaseStayInsideScopesAndStillNeedAWhereClause(): void
    {
        $this->article('Mine A');
        $this->orm->delete($this->article('Mine trashed'));
        $this->article('Theirs', team: 2);

        // DB-1: scopes and the soft-delete condition narrow the rows, but they are not
        // the where clause update() and delete() insist on.
        foreach ([
            'delete' => fn() => $this->orm->query(Article::class)->toBase()->delete(),
            'update' => fn() => $this->orm->query(Article::class)->toBase()->update(['status' => 'x']),
        ] as $operation => $run) {
            try {
                $run();
                self::fail($operation . ' ran without a where clause');
            } catch (DatabaseException $e) {
                self::assertStringContainsString('Refusing to ' . $operation, $e->getMessage());
            }
        }

        // An "or" added to the copy cannot reach another team's rows or trashed rows.
        $changed = $this->orm->query(Article::class)->toBase()->where('title', 'Mine A')->orWhere('title', 'Theirs')->orWhere('title', 'Mine trashed')->update(['status' => 'archived']);
        self::assertSame(1, $changed);
        self::assertSame(['Mine A'], self::values($this->orm->query(Article::class)->withoutGlobalScopes()->withTrashed()->where('status', 'archived')->get()));

        // deleteAll() means every row the scopes allow.
        self::assertSame(1, $this->orm->query(Article::class)->toBase()->deleteAll());
        self::assertSame(2, $this->db->table('articles')->count(), "the trashed row and the other team's row stay");
    }

    // Model events

    public function testSaveAndDeleteDispatchEvents(): void
    {
        $seen = $this->recordEvents();

        $article = $this->article('Events');
        $article->set('title', 'Changed');
        $this->orm->save($article);
        $this->orm->save($article); // nothing changed: no write, no saved event
        $this->orm->delete($article);
        $this->orm->restore($article);
        $this->orm->forceDelete($article);

        self::assertSame([
            'ModelSaving:create', 'ModelSaved:create',
            'ModelSaving:update', 'ModelSaved:update',
            'ModelSaving:update',
            'ModelDeleting:soft', 'ModelDeleted:soft',
            'ModelRestored:',
            'ModelDeleting:force', 'ModelDeleted:force',
        ], $seen->getArrayCopy());
    }

    public function testASavingListenerCanFillAttributesOrStopTheSave(): void
    {
        $this->events->listen(ModelSaving::class, static function (ModelSaving $event): void {
            if (!$event->model instanceof Article) {
                return;
            }

            if ($event->model->raw('title') === 'Forbidden') {
                throw new \DomainException('not allowed');
            }

            $event->model->set('slug', strtolower(self::text($event->model->raw('title'))));
        });

        $article = $this->article('Hello');
        self::assertSame('hello', $this->db->table('articles')->where('id', $article->getKey())->value('slug'));

        try {
            $this->article('Forbidden');
            self::fail('the listener did not stop the save');
        } catch (\DomainException) {
        }

        self::assertSame(1, $this->orm->query(Article::class)->count());
    }

    public function testADeletingListenerCanStopTheDelete(): void
    {
        $article = $this->article('Protected');
        $this->events->listen(ModelDeleting::class, static function (): never {
            throw new \DomainException('kept');
        });

        foreach ([fn() => $this->orm->delete($article), fn() => $this->orm->forceDelete($article)] as $delete) {
            try {
                $delete();
                self::fail('the listener did not stop the delete');
            } catch (\DomainException) {
            }
        }

        self::assertFalse($article->trashed());
        self::assertSame(1, $this->orm->query(Article::class)->count());
    }

    public function testAFailingSavedListenerLeavesTheRowWrittenOnce(): void
    {
        $this->events->listen(ModelSaved::class, static function (): never {
            throw new \DomainException('listener failed');
        });
        $article = (new Article())->fill(['title' => 'Once', 'team_id' => 1]);

        try {
            $this->orm->save($article);
            self::fail('the listener error was swallowed');
        } catch (\DomainException) {
        }

        self::assertTrue($article->exists(), 'the model knows its row was written');
        $this->orm->save($article); // nothing changed: no second insert, no second event

        self::assertSame(1, $this->db->table('articles')->count());
    }

    public function testAListenerErrorRollsBackTheCallersTransaction(): void
    {
        $this->events->listen(ModelSaved::class, static function (): never {
            throw new \DomainException('listener failed');
        });

        $article = (new Article())->fill(['title' => 'Rolled back', 'team_id' => 1]);

        try {
            $this->orm->transaction(fn() => $this->orm->save($article));
            self::fail('the listener error was swallowed');
        } catch (\DomainException) {
        }

        self::assertSame(0, $this->db->table('articles')->count(), 'listeners run inside the transaction');

        // Documented (ADR 0039, orm.md): the model keeps the state of the undone write.
        // It is not reset, because the ORM cannot know which writes a rollback undid.
        $key = $article->getKey();
        self::assertNotNull($key);
        self::assertTrue($article->exists());
        self::assertFalse($article->isDirty());
        self::assertNull($this->orm->find(Article::class, $key), 'reloading shows the truth');
    }

    public function testASoftDeleteRolledBackLeavesTheModelMarkedUntilReloaded(): void
    {
        $article = $this->article('Kept');
        $this->events->listen(ModelDeleted::class, static function (): never {
            throw new \DomainException('listener failed');
        });

        try {
            $this->orm->transaction(fn() => $this->orm->delete($article));
            self::fail('the listener error was swallowed');
        } catch (\DomainException) {
        }

        self::assertTrue($article->trashed(), 'documented: in-memory state is not rolled back');
        $fresh = $this->orm->find(Article::class, (int) $article->getKey());
        self::assertInstanceOf(Article::class, $fresh);
        self::assertFalse($fresh->trashed());
    }

    // Compatibility review (2026-10-27)

    public function testToBaseReturnsAnIndependentCopyEachTime(): void
    {
        $this->article('A');
        $this->article('B');
        $query = $this->orm->query(Article::class)->where('title', 'A');

        $copy = $query->toBase();
        $copy->orWhere('title', 'B');
        $copy->constrain(static fn(Builder $q) => $q->where('id', 0));

        self::assertSame(['A'], self::values($query->get()), 'changing the copy does not change the query');
        self::assertNotSame($copy, $query->toBase());
        self::assertSame(1, $query->toBase()->count(), 'a second copy starts from the query again');
        self::assertSame(1, $query->count());
    }

    public function testScopeAndTrashedModesCombine(): void
    {
        $this->article('A');
        $this->orm->delete($this->article('B'));
        $this->orm->delete($this->article('C', team: 2));
        $this->article('D', team: 2);

        $either = static fn(Builder $q) => $q->where(static fn(Builder $g) => $g->where('title', 'A')->orWhere('title', 'B'))->orWhere('title', 'C')->orWhere('title', 'D');

        self::assertSame(['A'], self::values($this->orm->query(Article::class)->apply($either)->get()));
        self::assertSame(['B'], self::values($this->orm->query(Article::class)->onlyTrashed()->apply($either)->get()));
        self::assertSame(['A', 'B'], self::values($this->orm->query(Article::class)->withTrashed()->apply($either)->get()));
        self::assertSame(['A', 'D'], self::values($this->orm->query(Article::class)->withoutGlobalScopes()->apply($either)->get()), 'lifting scopes keeps the soft-delete condition');
        self::assertSame(['B', 'C'], self::values($this->orm->query(Article::class)->withoutGlobalScopes()->onlyTrashed()->where('title', 'B')->orWhere('title', 'C')->get()));
        self::assertSame(['A', 'B', 'C', 'D'], self::values($this->orm->query(Article::class)->withoutGlobalScopes(TeamScope::class)->withTrashed()->get()));
    }

    public function testJoinedRelationsApplyScopesWhenEveryTableHasTheScopeColumn(): void
    {
        $library = $this->orm->create(Library::class, ['name' => 'City']);
        $mine = $this->orm->create(Shelf::class, ['library_id' => $library->getKey(), 'team_id' => 1]);
        $theirs = $this->orm->create(Shelf::class, ['library_id' => $library->getKey(), 'team_id' => 2]);
        $kept = $this->orm->create(Book::class, ['shelf_id' => $mine->getKey(), 'team_id' => 1, 'title' => 'Kept']);
        $foreign = $this->orm->create(Book::class, ['shelf_id' => $mine->getKey(), 'team_id' => 2, 'title' => 'Other team']);
        $this->orm->create(Book::class, ['shelf_id' => $theirs->getKey(), 'team_id' => 1, 'title' => 'On their shelf']);
        $trashed = $this->orm->create(Book::class, ['shelf_id' => $mine->getKey(), 'team_id' => 1, 'title' => 'Trashed']);
        $this->orm->delete($trashed);

        $reader = $this->orm->create(Reader::class, ['name' => 'Asha']);
        foreach ([$kept, $foreign, $trashed] as $book) {
            $this->db->table('book_reader')->insert(['reader_id' => $reader->getKey(), 'book_id' => $book->getKey(), 'team_id' => 2]);
        }

        $before = $this->queries();
        $this->orm->load($library, 'books');
        $this->orm->load($reader, 'books');

        self::assertSame(2, $this->queries() - $before, 'still one query per relation');
        self::assertSame(['Kept'], self::values(self::many($library, 'books')), 'only my books on my shelves');
        self::assertSame(['Kept'], self::values(self::many($reader, 'books')), "the pivot's own team_id does not decide");
    }

    public function testTheOrmWorksWithoutAnEventDispatcher(): void
    {
        $orm = new Orm($this->db);
        $orm->create(Country::class, ['name' => 'India']);

        self::assertSame(1, $orm->query(Country::class)->count());
    }

    // Relations and soft deletes

    public function testRelationsSkipTrashedRelatedRows(): void
    {
        $india = $this->orm->create(Country::class, ['name' => 'India']);
        $empty = $this->orm->create(Country::class, ['name' => 'Bhutan']);
        $asha = $this->orm->create(Author::class, ['country_id' => $india->getKey(), 'name' => 'Asha']);
        $gone = $this->orm->create(Author::class, ['country_id' => $india->getKey(), 'name' => 'Gone']);
        $kept = $this->orm->create(Story::class, ['author_id' => $asha->getKey(), 'title' => 'Kept']);
        $orphan = $this->orm->create(Story::class, ['author_id' => $gone->getKey(), 'title' => 'Orphan']);
        $this->orm->delete($gone);

        $this->orm->load([$india, $empty], 'authors');
        self::assertSame(['Asha'], self::values(self::many($india, 'authors'), 'name'));
        self::assertSame([], self::many($empty, 'authors'));

        $this->orm->load([$kept, $orphan], 'author');
        self::assertInstanceOf(Author::class, $kept->getRelation('author'));
        self::assertNull($orphan->getRelation('author'), 'a trashed parent loads as missing');

        $before = $this->queries();
        $this->orm->load([], 'authors');
        $nobody = $this->orm->query(Country::class)->where('name', 'Nowhere')->with('authors', 'stories')->get();
        self::assertSame([], $nobody);
        self::assertSame(1, $this->queries() - $before, 'no relation query for no parents');
    }

    public function testHasManyThroughLoadsInOneQueryAndSkipsTrashedIntermediates(): void
    {
        $india = $this->orm->create(Country::class, ['name' => 'India']);
        $nepal = $this->orm->create(Country::class, ['name' => 'Nepal']);
        $this->orm->create(Country::class, ['name' => 'Bhutan']);
        $asha = $this->orm->create(Author::class, ['country_id' => $india->getKey(), 'name' => 'Asha']);
        $gone = $this->orm->create(Author::class, ['country_id' => $india->getKey(), 'name' => 'Gone']);
        $kiran = $this->orm->create(Author::class, ['country_id' => $nepal->getKey(), 'name' => 'Kiran']);
        $this->orm->create(Story::class, ['author_id' => $asha->getKey(), 'title' => 'Desert']);
        $this->orm->create(Story::class, ['author_id' => $asha->getKey(), 'title' => 'Fort']);
        $this->orm->create(Story::class, ['author_id' => $gone->getKey(), 'title' => 'Hidden']);
        $this->orm->create(Story::class, ['author_id' => $kiran->getKey(), 'title' => 'Mountain']);
        $this->orm->delete($gone);

        $before = $this->queries();
        $countries = $this->orm->query(Country::class)->with('stories')->orderBy('id')->get();
        self::assertSame(2, $this->queries() - $before, 'one query for countries, one for all stories');

        self::assertSame(['Desert', 'Fort'], self::values(self::many($countries[0], 'stories')));
        self::assertSame(['Mountain'], self::values(self::many($countries[1], 'stories')));
        self::assertSame([], self::many($countries[2], 'stories'));
        self::assertSame(['id', 'author_id', 'title'], array_keys(self::many($countries[0], 'stories')[0]->getAttributes()), 'no helper column leaks into the model');
    }

    // Morph relations

    public function testMorphManyMorphOneAndMorphTo(): void
    {
        $photo = $this->orm->create(Photo::class, ['url' => '/a.jpg']);
        $video = $this->orm->create(Video::class, ['title' => 'Tour']);
        $this->orm->create(Remark::class, ['remarkable_type' => 'photo', 'remarkable_id' => $photo->getKey(), 'body' => 'Nice photo']);
        $this->orm->create(Remark::class, ['remarkable_type' => 'video', 'remarkable_id' => $video->getKey(), 'body' => 'Nice video']);
        // Same id, other type: must not be mixed up with the photo.
        $this->orm->create(Remark::class, ['remarkable_type' => 'video', 'remarkable_id' => $photo->getKey(), 'body' => 'Other video']);

        $photos = $this->orm->query(Photo::class)->with('remarks', 'firstRemark')->get();
        self::assertSame(['Nice photo'], self::values(self::many($photos[0], 'remarks'), 'body'));
        self::assertInstanceOf(Remark::class, $photos[0]->getRelation('firstRemark'));

        $before = $this->queries();
        $all = $this->orm->query(Remark::class)->with('remarkable')->orderBy('id')->get();
        self::assertSame(3, $this->queries() - $before, 'remarks, then one query per type');
        self::assertInstanceOf(Photo::class, $all[0]->getRelation('remarkable'));
        $tour = $all[1]->getRelation('remarkable');
        self::assertInstanceOf(Video::class, $tour);
        self::assertSame('Tour', $tour->raw('title'));
        self::assertInstanceOf(Video::class, $all[2]->getRelation('remarkable'), 'the type, not the id, picks the class');
    }

    public function testAnUnknownMorphTypeLoadsNothingAndInstantiatesNothing(): void
    {
        $this->db->table('remarks')->insert(['remarkable_type' => Comment::class, 'remarkable_id' => 1, 'body' => 'forged type']);

        $before = $this->queries();
        $remark = $this->orm->query(Remark::class)->with('remarkable')->firstOrFail();

        self::assertNull($remark->getRelation('remarkable'));
        self::assertSame(1, $this->queries() - $before, 'no query for a type outside the map');
    }

    public function testNestedLoadingAfterMorphToHandlesMixedClasses(): void
    {
        $photo = $this->orm->create(Photo::class, ['url' => '/a.jpg']);
        $video = $this->orm->create(Video::class, ['title' => 'Tour']);
        $this->orm->create(Remark::class, ['remarkable_type' => 'photo', 'remarkable_id' => $photo->getKey(), 'body' => 'p']);
        $this->orm->create(Remark::class, ['remarkable_type' => 'video', 'remarkable_id' => $video->getKey(), 'body' => 'v']);

        $all = $this->orm->query(Remark::class)->with('remarkable.remarks')->orderBy('id')->get();

        $video = $all[1]->getRelation('remarkable');
        self::assertInstanceOf(Video::class, $video);
        self::assertSame(['v'], self::values(self::many($video, 'remarks'), 'body'));
    }

    /**
     * DB-4: belongsToMany used "id" on both sides, so models with another primary key
     * loaded empty relations while attach() wrote their real keys.
     */
    public function testBelongsToManyWithCustomPrimaryKeys(): void
    {
        $asha = $this->orm->create(Member::class, ['code' => 'M-1', 'name' => 'asha']);
        $ravi = $this->orm->create(Member::class, ['code' => 'M-2', 'name' => 'ravi']);
        $chess = $this->orm->create(Club::class, ['uid' => 'C-1', 'name' => 'chess']);
        $this->orm->create(Club::class, ['uid' => 'C-2', 'name' => 'cricket']);

        $this->orm->attach($asha, 'clubs', ['C-1', 'C-2']);
        $this->orm->attach($ravi, 'clubs', 'C-1');
        $this->orm->load([$asha, $ravi], 'clubs');
        self::assertSame(['chess', 'cricket'], self::values(self::many($asha, 'clubs'), 'name'));
        self::assertSame(['chess'], self::values(self::many($ravi, 'clubs'), 'name'));

        $this->orm->load($chess, 'members');
        self::assertSame(['asha', 'ravi'], self::values(self::many($chess, 'members'), 'name'), 'the other direction too');

        self::assertSame(['attached' => [], 'detached' => ['C-2']], $this->orm->sync($asha, 'clubs', ['C-1']));
        self::assertSame(1, $this->orm->detach($ravi, 'clubs', 'C-1'));
        $this->orm->load([$asha, $ravi], 'clubs');
        self::assertSame(['chess'], self::values(self::many($asha, 'clubs'), 'name'));
        self::assertSame([], self::many($ravi, 'clubs'));

        // Explicit keys that are not the primary key: the pivot holds names.
        $this->orm->attach($asha, 'clubsByName', 'cricket');
        self::assertSame([['member_name' => 'asha', 'club_name' => 'cricket']], $this->db->table('club_member_names')->get());
        $this->orm->load($asha, 'clubsByName');
        self::assertSame(['cricket'], self::values(self::many($asha, 'clubsByName'), 'name'));
    }

    public function testMorphDefinitionsAreValidated(): void
    {
        $rejected = 0;

        foreach ([
            static fn() => Relation::morphTo('remarkable', ['photo' => \stdClass::class]),
            static fn() => Relation::morphTo('remarkable', ['Photo Type' => Photo::class]),
            static fn() => Relation::morphTo('remarkable', []),
            static fn() => Relation::morphTo('remarkable; drop', ['photo' => Photo::class]),
            static fn() => Relation::morphMany(Remark::class, 'remarkable', 'bad alias'),
            static fn() => Relation::morphOne(Remark::class, 'remarkable', "photo\n"),
        ] as $definition) {
            try {
                $definition();
            } catch (OrmException) {
                $rejected++;
            }
        }

        self::assertSame(6, $rejected);
        self::assertSame('remarkable_type', Relation::morphTo('remarkable', ['App\Models\Photo' => Photo::class])->morphType, 'class names of existing data are valid aliases');
    }
}
