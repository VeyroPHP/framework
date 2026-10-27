<?php

declare(strict_types=1);

namespace VeyroPHP\Auth\Access;

use VeyroPHP\Http\Exception\HttpException;

/**
 * Gate::authorize() refused: 401 for guests, 403 for logged-in users. The message is
 * shown to the client and never names the ability or the record.
 */
final class AuthorizationException extends HttpException
{
    public static function forGuest(): self
    {
        return new self(401, 'Unauthenticated.');
    }

    public static function forUser(): self
    {
        return new self(403, 'You do not have permission to do this.');
    }
}
