<?php

declare(strict_types=1);

namespace VeyroPHP\Auth\Events;

use VeyroPHP\Orm\Model;

/**
 * attempt() rejected the credentials. $user is null when no account matched
 * the identifier. The password is never included.
 *
 * The identifier is what the visitor typed (usually an email address), so
 * treat it as personal data when logging it.
 */
final class LoginFailed
{
    public function __construct(
        public readonly string $identifier,
        public readonly ?Model $user,
    ) {}
}
