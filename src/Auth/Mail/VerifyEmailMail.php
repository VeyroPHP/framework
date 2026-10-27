<?php

declare(strict_types=1);

namespace VeyroPHP\Auth\Mail;

use VeyroPHP\Mail\Mailable;
use VeyroPHP\Mail\Message;

/**
 * The default "confirm your email address" mail. Replace it with your own
 * Mailable through config "auth.verification.mail" (same constructor).
 */
final class VerifyEmailMail extends Mailable
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
            ->subject('Confirm your email address for ' . $this->appName)
            ->text(sprintf(
                "Please confirm your email address for %s by opening this link:\n\n%s\n\nThe link expires in %d minutes. If you did not create an account, ignore this email.",
                $this->appName,
                $this->url,
                $this->minutes,
            ))
            ->html(sprintf(
                '<p>Please confirm your email address for %s.</p><p><a href="%s">Confirm email address</a></p><p>The link expires in %d minutes. If you did not create an account, ignore this email.</p>',
                $e($this->appName),
                $e($this->url),
                $this->minutes,
            ));
    }
}
