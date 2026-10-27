<?php

declare(strict_types=1);

namespace VeyroPHP\Mail\Transport;

use VeyroPHP\Mail\SentMessage;

/**
 * Keeps messages in memory instead of sending them. For tests:
 *
 *     $mail = $container->make(Mailer::class)->transport('array');
 *     // ... run the code under test ...
 *     $mail->last()?->message->getSubject();
 */
final class ArrayTransport implements Transport
{
    /** @var list<SentMessage> */
    private array $messages = [];

    public function send(SentMessage $message): void
    {
        $this->messages[] = $message;
    }

    /**
     * @return list<SentMessage>
     */
    public function messages(): array
    {
        return $this->messages;
    }

    public function last(): ?SentMessage
    {
        return $this->messages === [] ? null : $this->messages[count($this->messages) - 1];
    }

    /**
     * Messages that went to $email (to, cc or bcc).
     *
     * @return list<SentMessage>
     */
    public function sentTo(string $email): array
    {
        return array_values(array_filter(
            $this->messages,
            static fn(SentMessage $m): bool => in_array(strtolower($email), array_map('strtolower', $m->recipients), true),
        ));
    }

    public function clear(): void
    {
        $this->messages = [];
    }
}
