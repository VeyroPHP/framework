<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Fixtures\Auth;

use VeyroPHP\Orm\Model;

class AccessPost extends Model
{
    protected const TABLE = 'posts';
}

final class FeaturedAccessPost extends AccessPost {}

final class AccessPostPolicy
{
    /** @var list<string> */
    public array $calls = [];

    public function view(?AuthUser $user, AccessPost $post): bool
    {
        $this->calls[] = 'view';

        return $post->get('published') === true || $post->get('user_id') === $user?->getKey();
    }

    public function create(AuthUser $user): bool
    {
        $this->calls[] = 'create';

        return $user->get('email') !== 'readonly@example.test';
    }

    public function update(AuthUser $user, AccessPost $post): bool
    {
        $this->calls[] = 'update';

        return $post->get('user_id') === $user->getKey();
    }

    public function share(AuthUser $user, AccessPost $post, string $channel): bool
    {
        return $channel === 'email' && $this->update($user, $post);
    }

    public function broken(AuthUser $user, AccessPost $post): string
    {
        return 'yes';
    }

    protected function delete(AuthUser $user, AccessPost $post): bool
    {
        return true;
    }
}
