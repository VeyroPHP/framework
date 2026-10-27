<?php

declare(strict_types=1);

// Models used by the ORM tests.

namespace VeyroPHP\Tests\Fixtures\Orm;

use VeyroPHP\Orm\Model;
use VeyroPHP\Orm\Relation;

/**
 * @property string $name
 * @property string $email
 * @property-read list<Post> $posts
 * @property-read Profile|null $profile
 * @property-read list<Role> $roles
 */
final class User extends Model
{
    protected const TABLE = 'users';
    protected const PUBLIC_ID = 'public_id';
    protected const FILLABLE = ['name', 'email', 'settings'];
    protected const HIDDEN = ['password'];
    protected const CASTS = ['is_admin' => 'bool', 'email_verified_at' => 'datetime', 'settings' => 'json', 'score' => 'float'];

    public static function relations(): array
    {
        return [
            'posts' => Relation::hasMany(Post::class, 'user_id'),
            'profile' => Relation::hasOne(Profile::class, 'user_id'),
            'roles' => Relation::belongsToMany(Role::class, 'role_user', 'user_id', 'role_id'),
        ];
    }
}

/**
 * @property string $title
 * @property-read User|null $author
 * @property-read list<Comment> $comments
 */
final class Post extends Model
{
    protected const TABLE = 'posts';
    protected const FILLABLE = ['title', 'user_id'];
    protected const CASTS = ['published_on' => 'date'];

    public static function relations(): array
    {
        return [
            'author' => Relation::belongsTo(User::class, 'user_id'),
            'comments' => Relation::hasMany(Comment::class, 'post_id'),
        ];
    }
}

final class Comment extends Model
{
    protected const TABLE = 'comments';
    protected const TIMESTAMPS = false;
    protected const FILLABLE = ['post_id', 'body'];
}

final class Profile extends Model
{
    protected const TABLE = 'profiles';
    protected const TIMESTAMPS = false;
}

final class Role extends Model
{
    protected const TABLE = 'roles';
    protected const TIMESTAMPS = false;
    protected const FILLABLE = ['name'];
}

/** Table name derived from the class name: blog_posts. */
final class BlogPost extends Model {}

final class BadTableModel extends Model
{
    protected const TABLE = 123;
}
