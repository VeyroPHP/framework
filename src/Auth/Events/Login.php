<?php

declare(strict_types=1);

namespace VeyroPHP\Auth\Events;

use VeyroPHP\Orm\Model;

/**
 * A user was logged in (by attempt(), login() or a remember-me cookie).
 */
final class Login
{
    public function __construct(public readonly Model $user) {}
}
