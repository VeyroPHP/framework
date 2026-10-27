<?php

declare(strict_types=1);

namespace VeyroPHP\Exception;

/**
 * An exception with client-safe details (such as validation errors) that the
 * HTTP layer adds to error responses.
 */
interface HasErrorDetails extends VeyroPHPException
{
    /**
     * @return array<string, mixed> merged into the JSON error object, e.g. ['errors' => [...]]
     */
    public function getDetails(): array;
}
