<?php

declare(strict_types=1);

namespace VeyroPHP\Notifications\Sms;

use VeyroPHP\Notifications\NotificationException;

/**
 * One text message, returned by a notification's toSms():
 *
 *     return new SmsMessage('Your OTP is 482913. It expires in 10 minutes.');
 *
 * In India, commercial SMS must use a DLT-registered template; give its id and
 * variables for gateways that send by template (MSG91):
 *
 *     return (new SmsMessage('Your OTP is 482913'))->template('6512ab...', ['otp' => '482913']);
 */
final class SmsMessage
{
    private ?string $to = null;

    private ?string $templateId = null;

    /** @var array<string, string> */
    private array $variables = [];

    public function __construct(public readonly string $text)
    {
        if (trim($text) === '') {
            throw new NotificationException('An SMS needs text.');
        }
    }

    /**
     * Sends to this number instead of the notifiable's.
     */
    public function to(string $number): self
    {
        $this->to = $number;

        return $this;
    }

    /**
     * @param array<string, string> $variables
     */
    public function template(string $id, array $variables = []): self
    {
        if (preg_match('/^[A-Za-z0-9_-]+$/D', $id) !== 1) {
            throw new NotificationException('An SMS template id may only contain letters, digits, "_" and "-".');
        }

        $this->templateId = $id;
        $this->variables = $variables;

        return $this;
    }

    public function getTo(): ?string
    {
        return $this->to;
    }

    public function getTemplateId(): ?string
    {
        return $this->templateId;
    }

    /**
     * @return array<string, string>
     */
    public function getVariables(): array
    {
        return $this->variables;
    }
}
