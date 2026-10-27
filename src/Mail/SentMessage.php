<?php

declare(strict_types=1);

namespace VeyroPHP\Mail;

/**
 * A message as handed to the transport: the original Message (with rendered
 * bodies), the raw MIME text and the envelope.
 */
final class SentMessage
{
    /**
     * @param list<string> $recipients envelope recipients (to, cc and bcc)
     */
    public function __construct(
        public readonly Message $message,
        public readonly string $raw,
        public readonly string $messageId,
        public readonly string $sender,
        public readonly array $recipients,
        public readonly string $mailer,
    ) {}
}
