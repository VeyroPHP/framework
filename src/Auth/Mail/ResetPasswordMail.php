<?php

declare(strict_types=1);

namespace VeyroPHP\Auth\Mail;

use VeyroPHP\Mail\Mailable;
use VeyroPHP\Mail\Message;

/**
 * The default "reset your password" mail. Replace it with your own Mailable
 * through config "auth.passwords.mail" (same constructor).
 */
final class ResetPasswordMail extends Mailable
{
    public function __construct(
        public readonly string $email,
        public readonly string $url,
        public readonly string $appName,
        public readonly int $minutes,
    ) {}

    public function build(Message $message): void
    {
        $e = static fn(string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $message->to($this->email)
            ->subject('Reset your ' . $this->appName . ' password')
            ->text(sprintf(
                "You asked to reset your %s password. Open this link to choose a new one:\n\n%s\n\nThe link works once and expires in %d minutes. If you did not ask for this, ignore this email; your password stays the same.",
                $this->appName,
                $this->url,
                $this->minutes,
            ))
            ->html(sprintf(
                '<p>You asked to reset your %s password.</p><p><a href="%s">Choose a new password</a></p><p>The link works once and expires in %d minutes. If you did not ask for this, ignore this email; your password stays the same.</p>',
                $e($this->appName),
                $e($this->url),
                $this->minutes,
            ));
    }
}
