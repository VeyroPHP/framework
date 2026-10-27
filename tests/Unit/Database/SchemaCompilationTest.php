<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Unit\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use VeyroPHP\Database\Connection\Connection;
use VeyroPHP\Database\Dialect\Dialect;
use VeyroPHP\Database\Dialect\MySqlDialect;
use VeyroPHP\Database\Dialect\PostgresDialect;
use VeyroPHP\Database\Dialect\SqliteDialect;
use VeyroPHP\Database\Exception\DatabaseException;
use VeyroPHP\Database\Query\Expression;
use VeyroPHP\Database\Schema\Blueprint;
use VeyroPHP\Database\Schema\ColumnDefinition;
use VeyroPHP\Database\Schema\ForeignKeyDefinition;
use VeyroPHP\Database\Schema\Grammar\MySqlSchemaGrammar;
use VeyroPHP\Database\Schema\Grammar\PostgresSchemaGrammar;
use VeyroPHP\Database\Schema\Grammar\SchemaGrammar;
use VeyroPHP\Database\Schema\Grammar\SqliteSchemaGrammar;
use VeyroPHP\Database\Schema\Schema;

/**
 * DDL generation only; the connector throws to prove nothing is executed.
 */
#[CoversClass(Schema::class)]
#[CoversClass(Blueprint::class)]
#[CoversClass(ColumnDefinition::class)]
#[CoversClass(ForeignKeyDefinition::class)]
#[CoversClass(SchemaGrammar::class)]
#[CoversClass(MySqlSchemaGrammar::class)]
#[CoversClass(PostgresSchemaGrammar::class)]
#[CoversClass(SqliteSchemaGrammar::class)]
final class SchemaCompilationTest extends TestCase
{
    private static function schema(Dialect $dialect): Schema
    {
        return new Schema(new Connection('test', $dialect, static function (): never {
            throw new \LogicException('DDL compilation must not connect.');
        }));
    }

    private static function postsTable(Blueprint $table): void
    {
        $table->id();
        $table->ulid()->unique();
        $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
        $table->string('title', 200);
        $table->text('body')->nullable();
        $table->boolean('published')->default(false);
        $table->decimal('price', 8, 2)->default(0);
        $table->integer('views')->default(0)->index();
        $table->json('meta')->nullable();
        $table->timestamps();
    }

    public function testMySqlCreateTable(): void
    {
        self::assertSame([
            'create table `posts` (`id` bigint unsigned not null auto_increment primary key, `public_id` char(26) not null, '
            . '`user_id` bigint unsigned not null, `title` varchar(200) not null, `body` text null, `published` tinyint(1) not null default 0, '
            . '`price` decimal(8, 2) not null default 0, `views` int not null default 0, `meta` json null, `created_at` datetime null, `updated_at` datetime null, '
            . 'constraint `posts_user_id_foreign` foreign key (`user_id`) references `users` (`id`) on delete cascade on update restrict)'
            . ' engine=InnoDB default charset=utf8mb4 collate=utf8mb4_unicode_ci',
            'create unique index `posts_public_id_unique` on `posts` (`public_id`)',
            'create index `posts_views_index` on `posts` (`views`)',
        ], self::schema(new MySqlDialect())->createSql('posts', self::postsTable(...)));
    }

    public function testPostgresCreateTable(): void
    {
        $sql = self::schema(new PostgresDialect())->createSql('posts', self::postsTable(...));

        self::assertStringStartsWith('create table "posts" ("id" bigserial primary key, "public_id" char(26) not null, "user_id" bigint not null', $sql[0]);
        self::assertStringContainsString('"published" boolean not null default false', $sql[0]);
        self::assertStringContainsString('"meta" jsonb null', $sql[0]);
        self::assertStringContainsString('"created_at" timestamp(0) without time zone null', $sql[0]);
        self::assertStringNotContainsString('unsigned', $sql[0]);
    }

    public function testSqliteCreateTable(): void
    {
        $sql = self::schema(new SqliteDialect())->createSql('posts', self::postsTable(...));

        self::assertStringStartsWith('create table "posts" ("id" integer primary key autoincrement not null, "public_id" varchar(26) not null', $sql[0]);
        self::assertStringContainsString('"published" integer not null default 0', $sql[0]);
        self::assertStringContainsString('constraint "posts_user_id_foreign" foreign key ("user_id") references "users" ("id") on delete cascade', $sql[0]);
    }

    public function testAlterStatementsPerDialect(): void
    {
        $alter = static function (Blueprint $table): void {
            $table->string('slug')->nullable()->unique();
            $table->renameColumn('title', 'headline');
            $table->dropIndex('posts_views_index');
            $table->dropColumn('views', 'meta');
        };

        self::assertSame([
            'alter table `posts` add column `slug` varchar(255) null',
            'alter table `posts` rename column `title` to `headline`',
            'drop index `posts_views_index` on `posts`',
            'alter table `posts` drop column `views`',
            'alter table `posts` drop column `meta`',
            'create unique index `posts_slug_unique` on `posts` (`slug`)',
        ], self::schema(new MySqlDialect())->alterSql('posts', $alter));

        self::assertContains('drop index "posts_views_index"', self::schema(new SqliteDialect())->alterSql('posts', $alter));
    }

    public function testForeignKeyOnAddedColumn(): void
    {
        $addAuthor = static fn(Blueprint $table) => $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();

        self::assertSame([
            'alter table `posts` add column `author_id` bigint unsigned null',
            'alter table `posts` add constraint `posts_author_id_foreign` foreign key (`author_id`) references `users` (`id`) on delete set null on update restrict',
        ], self::schema(new MySqlDialect())->alterSql('posts', $addAuthor));

        self::assertSame(
            ['alter table "posts" add column "author_id" integer null references "users" ("id") on delete set null on update restrict'],
            self::schema(new SqliteDialect())->alterSql('posts', $addAuthor),
        );
    }

    public function testSqliteRejectsForeignKeyOnExistingColumn(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('SQLite cannot add a foreign key to the existing column');

        self::schema(new SqliteDialect())->alterSql('posts', static fn(Blueprint $t) => $t->foreign('user_id')->on('users'));
    }

    public function testDefaultLiteralsAreEscaped(): void
    {
        $defaults = static function (Blueprint $table): void {
            $table->string('quote')->default("it's");
            $table->string('backslash')->default('C:\\path\\');
            $table->string('injection')->default("x'); drop table users; --");
            $table->double('ratio')->default(1.5);
            $table->dateTime('seen_at')->default(new Expression('CURRENT_TIMESTAMP'));
            $table->string('maybe')->nullable()->default(null);
        };

        $mysql = self::schema(new MySqlDialect())->createSql('t', $defaults)[0];
        self::assertStringContainsString("`quote` varchar(255) not null default 'it''s'", $mysql);
        self::assertStringContainsString("`backslash` varchar(255) not null default 'C:\\\\path\\\\'", $mysql);
        self::assertStringContainsString("default 'x''); drop table users; --'", $mysql);
        self::assertStringContainsString('`ratio` double not null default 1.5', $mysql);
        self::assertStringContainsString('`seen_at` datetime not null default CURRENT_TIMESTAMP', $mysql);
        self::assertStringContainsString('`maybe` varchar(255) null default null', $mysql);

        $sqlite = self::schema(new SqliteDialect())->createSql('t', $defaults)[0];
        self::assertStringContainsString("\"backslash\" varchar(255) not null default 'C:\\path\\'", $sqlite, 'only MySQL escapes backslashes');
    }

    public function testLongIndexNamesAreShortenedDeterministically(): void
    {
        $blueprint = new Blueprint('a_very_long_table_name_for_testing', true);
        $name = $blueprint->indexName('unique', ['first_extremely_long_column', 'second_extremely_long_column']);

        self::assertLessThanOrEqual(60, strlen($name));
        self::assertSame($name, $blueprint->indexName('unique', ['first_extremely_long_column', 'second_extremely_long_column']));
        self::assertMatchesRegularExpression('/^[a-z0-9_]+$/', $name);
    }

    public function testInvalidDefinitionsAreRejected(): void
    {
        $cases = [
            'id in alter' => fn() => self::schema(new SqliteDialect())->alterSql('t', static fn(Blueprint $t) => $t->id()),
            'drop column in create' => fn() => self::schema(new SqliteDialect())->createSql('t', static fn(Blueprint $t) => $t->dropColumn('x')),
            'duplicate column' => fn() => self::schema(new SqliteDialect())->createSql('t', static function (Blueprint $t): void {
                $t->string('a');
                $t->integer('a');
            }),
            'bad decimal' => fn() => self::schema(new SqliteDialect())->createSql('t', static fn(Blueprint $t) => $t->decimal('d', 5, 6)),
            'bad string length' => fn() => self::schema(new SqliteDialect())->createSql('t', static fn(Blueprint $t) => $t->string('s', 0)),
            'empty table' => fn() => self::schema(new SqliteDialect())->createSql('t', static fn(Blueprint $t) => null),
            'bad identifier' => fn() => self::schema(new SqliteDialect())->createSql('t; drop table x', static fn(Blueprint $t) => $t->string('a')),
            'bad column name' => fn() => self::schema(new SqliteDialect())->createSql('t', static fn(Blueprint $t) => $t->string('a b')),
            'bad fk action' => fn() => self::schema(new SqliteDialect())->createSql('t', static fn(Blueprint $t) => $t->foreignId('u')->constrained('users')->onDelete('explode')),
            'fk without table' => fn() => self::schema(new SqliteDialect())->createSql('t', static fn(Blueprint $t) => $t->foreign('u')),
            'array default' => fn() => self::schema(new SqliteDialect())->createSql('t', static fn(Blueprint $t) => $t->string('a')->default([1])),
            'null byte default' => fn() => self::schema(new SqliteDialect())->createSql('t', static fn(Blueprint $t) => $t->string('a')->default("a\0b")),
            'empty index' => fn() => self::schema(new SqliteDialect())->createSql('t', static fn(Blueprint $t) => $t->index([])),
        ];

        foreach ($cases as $label => $case) {
            try {
                $case();
                self::fail("Expected DatabaseException for: {$label}");
            } catch (DatabaseException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
