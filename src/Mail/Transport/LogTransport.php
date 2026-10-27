<?php

declare(strict_types=1);

namespace VeyroPHP\Mail\Transport;

use Psr\Log\LoggerInterface;
use VeyroPHP\Mail\SentMessage;

/**
 * Writes messages to the log instead of sending them. For local development:
 * recipients, subject and the plain-text body (decoded, so links can be
 * copied) are logged at "debug".
 *
 * Do not use it in production: mail bodies often contain personal data and
 * links that act as passwords (password resets).
 */
final class LogTransport implements Transport
{
    public function __construct(private readonly LoggerInterface $logger) {}

    public function send(SentMessage $message): void
    {
        $this->logger->debug("Mail not sent (log mailer). To: {to}. Subject: {subject}.\n{body}", [
            'to' => implode(', ', $message->recipients),
            'subject' => $message->message->getSubject(),
            'body' => $message->message->getText() ?? $message->message->getHtml() ?? '',
            'message_id' => $message->messageId,
        ]);
    }
}
