<?php

declare(strict_types=1);

// Models for soft deletes, scopes, model events and the through/morph relations.

namespace VeyroPHP\Tests\Fixtures\Orm;

use VeyroPHP\Database\Query\Builder;
use VeyroPHP\Orm\Model;
use VeyroPHP\Orm\Relation;
use VeyroPHP\Orm\Scope;

/** The team the current request belongs to, read by TeamScope. */
final class CurrentTeam
{
    public static int $id = 1;
}

/** Global scope: only the current team's rows. */
final class TeamScope implements Scope
{
    public function apply(Builder $query): void
    {
        $query->where('team_id', CurrentTeam::$id);
    }
}

/** Local scope with an "or" inside, to prove scopes are grouped. */
final class Visible implements Scope
{
    public function apply(Builder $query): void
    {
        $query->where('status', 'published')->orWhere('status', 'featured');
    }
}

final class Article extends Model
{
    protected const TABLE = 'articles';
    protected const SOFT_DELETES = 'deleted_at';
    protected const FILLABLE = ['title', 'status', 'team_id', 'slug'];

    /**
     * @return list<Scope>
     */
    public static function globalScopes(): array
    {
        return [new TeamScope()];
    }
}

final class Country extends Model
{
    protected const TABLE = 'countries';
    protected const TIMESTAMPS = false;
    protected const FILLABLE = ['name'];

    public static function relations(): array
    {
        return [
            'authors' => Relation::hasMany(Author::class, 'country_id'),
            'stories' => Relation::hasManyThrough(Story::class, Author::class, 'country_id', 'author_id'),
        ];
    }
}

final class Author extends Model
{
    protected const TABLE = 'authors';
    protected const TIMESTAMPS = false;
    protected const SOFT_DELETES = 'deleted_at';
    protected const FILLABLE = ['country_id', 'name'];
}

final class Story extends Model
{
    protected const TABLE = 'stories';
    protected const TIMESTAMPS = false;
    protected const FILLABLE = ['author_id', 'title'];

    public static function relations(): array
    {
        return ['author' => Relation::belongsTo(Author::class, 'author_id')];
    }
}

final class Photo extends Model
{
    protected const TABLE = 'photos';
    protected const TIMESTAMPS = false;
    protected const FILLABLE = ['url'];

    public static function relations(): array
    {
        return [
            'remarks' => Relation::morphMany(Remark::class, 'remarkable', 'photo'),
            'firstRemark' => Relation::morphOne(Remark::class, 'remarkable', 'photo'),
        ];
    }
}

final class Video extends Model
{
    protected const TABLE = 'videos';
    protected const TIMESTAMPS = false;
    protected const FILLABLE = ['title'];

    public static function relations(): array
    {
        return ['remarks' => Relation::morphMany(Remark::class, 'remarkable', 'video')];
    }
}

final class Remark extends Model
{
    protected const TABLE = 'remarks';
    protected const TIMESTAMPS = false;
    protected const FILLABLE = ['remarkable_type', 'remarkable_id', 'body'];

    public static function relations(): array
    {
        return ['remarkable' => Relation::morphTo('remarkable', ['photo' => Photo::class, 'video' => Video::class])];
    }
}

// Tenant tables that all have team_id, joined by belongsToMany and hasManyThrough.

final class Library extends Model
{
    protected const TABLE = 'libraries';
    protected const TIMESTAMPS = false;
    protected const FILLABLE = ['name'];

    public static function relations(): array
    {
        return ['books' => Relation::hasManyThrough(Book::class, Shelf::class, 'library_id', 'shelf_id')];
    }
}

final class Shelf extends Model
{
    protected const TABLE = 'shelves';
    protected const TIMESTAMPS = false;
    protected const FILLABLE = ['library_id', 'team_id'];

    /**
     * @return list<Scope>
     */
    public static function globalScopes(): array
    {
        return [new TeamScope()];
    }
}

final class Book extends Model
{
    protected const TABLE = 'books';
    protected const TIMESTAMPS = false;
    protected const SOFT_DELETES = 'deleted_at';
    protected const FILLABLE = ['shelf_id', 'team_id', 'title'];

    /**
     * @return list<Scope>
     */
    public static function globalScopes(): array
    {
        return [new TeamScope()];
    }
}

final class Reader extends Model
{
    protected const TABLE = 'readers';
    protected const TIMESTAMPS = false;
    protected const FILLABLE = ['name'];

    public static function relations(): array
    {
        return ['books' => Relation::belongsToMany(Book::class, 'book_reader', 'reader_id', 'book_id')];
    }
}

// Models whose primary key is not "id" (DB-4).

final class Member extends Model
{
    protected const TABLE = 'members';
    protected const PRIMARY_KEY = 'code';
    protected const TIMESTAMPS = false;
    protected const FILLABLE = ['code', 'name'];

    public static function relations(): array
    {
        return [
            'clubs' => Relation::belongsToMany(Club::class, 'club_member', 'member_code', 'club_uid'),
            'clubsByName' => Relation::belongsToMany(Club::class, 'club_member_names', 'member_name', 'club_name', parentKey: 'name', relatedKey: 'name'),
        ];
    }
}

final class Club extends Model
{
    protected const TABLE = 'clubs';
    protected const PRIMARY_KEY = 'uid';
    protected const TIMESTAMPS = false;
    protected const FILLABLE = ['uid', 'name'];

    public static function relations(): array
    {
        return ['members' => Relation::belongsToMany(Member::class, 'club_member', 'club_uid', 'member_code')];
    }
}
