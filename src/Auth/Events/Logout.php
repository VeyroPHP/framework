<?php

declare(strict_types=1);

namespace VeyroPHP\Auth\Events;

use VeyroPHP\Orm\Model;

/**
 * A user logged out. $user is null when nobody was logged in.
 */
final class Logout
{
    public function __construct(public readonly ?Model $user) {}
}
