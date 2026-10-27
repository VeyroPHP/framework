<?php

declare(strict_types=1);

namespace VeyroPHP\Auth\Events;

use VeyroPHP\Orm\Model;

/**
 * A user set a new password through a reset link.
 */
final class PasswordReset
{
    public function __construct(public readonly Model $user) {}
}
