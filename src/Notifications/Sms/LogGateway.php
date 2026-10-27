<?php

declare(strict_types=1);

namespace VeyroPHP\Notifications\Sms;

use Psr\Log\LoggerInterface;

/**
 * Writes SMS to the log instead of sending them: for development.
 */
final class LogGateway implements SmsGateway
{
    public function __construct(private readonly LoggerInterface $logger) {}

    public function send(SmsMessage $message, string $to): void
    {
        $this->logger->info('SMS to {to}: {text}', ['to' => $to, 'text' => $message->text, 'template' => $message->getTemplateId()]);
    }
}
