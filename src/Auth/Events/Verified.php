<?php

declare(strict_types=1);

namespace VeyroPHP\Auth\Events;

use VeyroPHP\Orm\Model;

/**
 * A user confirmed their email address.
 */
final class Verified
{
    public function __construct(public readonly Model $user) {}
}
