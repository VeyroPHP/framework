<?php

declare(strict_types=1);

namespace VeyroPHP\Auth\Passwords;

/**
 * Result of PasswordBroker::sendResetLink() and reset().
 *
 * Show the same message for LinkSent and UserNotFound, so the form does not
 * reveal which addresses have accounts.
 */
enum ResetStatus: string
{
    case LinkSent = 'link_sent';
    case UserNotFound = 'user_not_found';
    case Throttled = 'throttled';
    case PasswordReset = 'password_reset';
    case InvalidToken = 'invalid_token';
}
