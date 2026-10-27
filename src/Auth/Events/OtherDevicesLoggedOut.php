<?php

declare(strict_types=1);

namespace VeyroPHP\Auth\Events;

use VeyroPHP\Orm\Model;

/**
 * The user ended every other session and remembered browser (Auth::logoutOtherDevices()).
 */
final class OtherDevicesLoggedOut
{
    public function __construct(public readonly Model $user) {}
}
