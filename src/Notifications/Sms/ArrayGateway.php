<?php

declare(strict_types=1);

namespace VeyroPHP\Notifications\Sms;

/**
 * Keeps SMS in memory instead of sending them. For tests:
 *
 *     $sms = $container->make(ArrayGateway::class);   // with 'gateway' => 'array'
 *     $sms->sent()[0]['to'];
 */
final class ArrayGateway implements SmsGateway
{
    /** @var list<array{to: string, message: SmsMessage}> */
    private array $sent = [];

    public function send(SmsMessage $message, string $to): void
    {
        $this->sent[] = ['to' => $to, 'message' => $message];
    }

    /**
     * @return list<array{to: string, message: SmsMessage}>
     */
    public function sent(): array
    {
        return $this->sent;
    }
}
