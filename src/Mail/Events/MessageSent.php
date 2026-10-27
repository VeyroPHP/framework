<?php

declare(strict_types=1);

namespace VeyroPHP\Mail\Events;

use VeyroPHP\Mail\SentMessage;

/**
 * A message was handed to the transport successfully.
 */
final class MessageSent
{
    public function __construct(public readonly SentMessage $sent) {}
}
