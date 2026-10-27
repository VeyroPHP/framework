<?php

declare(strict_types=1);

namespace VeyroPHP\Database\Exception;

use VeyroPHP\Exception\HasHttpStatus;

/**
 * A pagination cursor that was not produced by cursorPaginate(), for example
 * one edited by the client. Rendered as 400 by the HTTP layer.
 */
final class InvalidCursorException extends DatabaseException implements HasHttpStatus
{
    public function __construct()
    {
        parent::__construct('Invalid pagination cursor.');
    }

    public function getStatusCode(): int
    {
        return 400;
    }
}
