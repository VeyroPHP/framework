<?php

declare(strict_types=1);

namespace VeyroPHP\Session;

use InvalidArgumentException;
use VeyroPHP\Http\Cookie;

/**
 * Session settings.
 *
 * - lifetime: minutes of inactivity before the session expires (default 120)
 * - secure: true/false, or null to set the Secure flag automatically on HTTPS requests
 * - sameSite: lax (default), strict or none (none requires secure: true)
 */
final class SessionConfig
{
    public function __construct(
        public readonly string $cookieName = 'veyrophp_session',
        public readonly int $lifetimeMinutes = 120,
        public readonly ?bool $secure = null,
        public readonly string $sameSite = 'lax',
        public readonly string $path = '/',
        public readonly ?string $domain = null,
    ) {
        if ($lifetimeMinutes < 1) {
            throw new InvalidArgumentException('Session lifetime must be at least one minute.');
        }

        if (!in_array(strtolower($sameSite), Cookie::SAME_SITE, true)) {
            throw new InvalidArgumentException('session.same_site must be lax, strict or none.');
        }

        if (strtolower($sameSite) === 'none' && $secure !== true) {
            throw new InvalidArgumentException('session.same_site "none" needs session.secure => true: browsers drop SameSite=None cookies without Secure.');
        }
    }
}
