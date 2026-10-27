<?php

declare(strict_types=1);

namespace VeyroPHP\Session;

use LogicException;
use VeyroPHP\Exception\VeyroPHPException;

final class SessionException extends LogicException implements VeyroPHPException
{
    public static function notStarted(): self
    {
        return new self('No session has been started. Add StartSession to the global middleware.');
    }

    public static function csrfWithoutSession(): self
    {
        return new self('VerifyCsrfToken needs StartSession to run before it.');
    }
}
