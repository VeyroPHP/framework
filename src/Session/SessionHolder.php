<?php

declare(strict_types=1);

namespace VeyroPHP\Session;

/**
 * Scoped (per request) holder for the current session, filled by StartSession.
 * Lets services such as Auth receive the session through the container.
 *
 * @internal Framework implementation detail, not covered by the backward-compatibility promise.
 */
final class SessionHolder
{
    private ?Session $session = null;

    public function set(Session $session): void
    {
        $this->session = $session;
    }

    public function has(): bool
    {
        return $this->session !== null;
    }

    public function session(): Session
    {
        return $this->session ?? throw SessionException::notStarted();
    }
}
