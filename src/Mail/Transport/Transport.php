<?php

declare(strict_types=1);

namespace VeyroPHP\Mail\Transport;

use VeyroPHP\Mail\SentMessage;
use VeyroPHP\Mail\TransportException;

/**
 * Delivers a built message (SMTP, log, array, or your own, for example an HTTP mail API).
 */
interface Transport
{
    /**
     * @throws TransportException when the message could not be delivered
     */
    public function send(SentMessage $message): void;
}
