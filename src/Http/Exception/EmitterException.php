<?php

declare(strict_types=1);

namespace VeyroPHP\Http\Exception;

use RuntimeException;
use VeyroPHP\Exception\VeyroPHPException;

/**
 * Thrown when a response cannot be sent, for example because output already started.
 */
final class EmitterException extends RuntimeException implements VeyroPHPException
{
    public static function headersAlreadySent(string $file, int $line): self
    {
        return new self(sprintf('Cannot send the response: headers were already sent (output started at %s:%d).', $file, $line));
    }
}
