<?php

declare(strict_types=1);

namespace VeyroPHP\Session;

use Stringable;

/**
 * The CSRF token of the current session, created only when it is first read.
 * Pages without forms therefore do not create a session or set a cookie.
 *
 *     <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
 */
final class CsrfToken implements Stringable
{
    public function __construct(private readonly Session $session) {}

    public function value(): string
    {
        return $this->session->token();
    }

    public function __toString(): string
    {
        return $this->value();
    }
}
