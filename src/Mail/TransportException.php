<?php

declare(strict_types=1);

namespace VeyroPHP\Mail;

/**
 * The mail server could not be reached or refused the message.
 *
 * $smtpCode is the server's reply code (for example 535 for wrong credentials,
 * 550 for an unknown recipient) or 0 for connection problems. HTTP mail APIs set
 * $httpStatus instead. isRetryable() is true for connection problems, temporary SMTP
 * (4xx) replies, and HTTP 429 and 5xx answers.
 */
final class TransportException extends MailException
{
    public function __construct(
        string $message,
        public readonly int $smtpCode = 0,
        ?\Throwable $previous = null,
        public readonly int $httpStatus = 0,
    ) {
        parent::__construct($message, $smtpCode !== 0 ? $smtpCode : $httpStatus, $previous);
    }

    /**
     * An HTTP mail API failure: $httpStatus 0 when it could not be reached.
     */
    public static function fromApi(string $message, int $httpStatus, ?\Throwable $previous): self
    {
        return new self($message, 0, $previous, $httpStatus);
    }

    public function isRetryable(): bool
    {
        if ($this->httpStatus !== 0) {
            return $this->httpStatus === 429 || $this->httpStatus >= 500;
        }

        return $this->smtpCode === 0 || ($this->smtpCode >= 400 && $this->smtpCode < 500);
    }
}
