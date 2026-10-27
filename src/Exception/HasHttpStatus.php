<?php

declare(strict_types=1);

namespace VeyroPHP\Exception;

/**
 * An exception that maps to an HTTP status. Lets modules without an HTTP
 * dependency (such as the ORM) produce 404 and similar responses.
 *
 * The message of such an exception is shown to clients, so it must not
 * contain internal details.
 */
interface HasHttpStatus extends VeyroPHPException
{
    public function getStatusCode(): int;
}
