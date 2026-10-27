<?php

declare(strict_types=1);

namespace VeyroPHP\Notifications;

use RuntimeException;
use VeyroPHP\Exception\VeyroPHPException;

/**
 * A notification that cannot be sent as defined: unknown channel, missing
 * toX() method, a notifiable without an address for the channel, or a gateway
 * (SMS) that refused it. For gateway errors the code is the HTTP status (0 when
 * the gateway could not be reached).
 */
final class NotificationException extends RuntimeException implements VeyroPHPException
{
    public static function fromApi(string $message, int $httpStatus, ?\Throwable $previous): self
    {
        return new self($message, $httpStatus, $previous);
    }
}
