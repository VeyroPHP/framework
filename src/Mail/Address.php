<?php

declare(strict_types=1);

namespace VeyroPHP\Mail;

/**
 * An email address with an optional display name.
 *
 *     new Address('asha@example.com', 'Asha Verma');
 *     Address::parse('Asha Verma <asha@example.com>');
 *
 * Addresses must be ASCII (internationalized domains in punycode). Line
 * breaks and control characters are rejected, so user input cannot add
 * mail headers (header injection).
 */
final class Address
{
    public readonly string $email;

    public function __construct(string $email, public readonly string $name = '')
    {
        $email = trim($email);

        if ($email === '' || preg_match('/[\x00-\x20\x7F-\xFF<>]/', $email) === 1 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new MailException(sprintf('Invalid email address "%s".', self::printable($email)));
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
            throw new MailException('A mail address name must not contain line breaks or control characters.');
        }

        $this->email = $email;
    }

    /**
     * Accepts "user@example.com" or "Name <user@example.com>".
     */
    public static function parse(string|self $address): self
    {
        if ($address instanceof self) {
            return $address;
        }

        if (preg_match('/^\s*(.*?)\s*<([^<>]+)>\s*$/sD', $address, $m) === 1) {
            return new self($m[2], trim($m[1], " \t\"'"));
        }

        return new self($address);
    }

    public function __toString(): string
    {
        return $this->name === '' ? $this->email : sprintf('%s <%s>', $this->name, $this->email);
    }

    private static function printable(string $value): string
    {
        return (string) preg_replace('/[^\x21-\x7E]/', '?', strlen($value) > 100 ? substr($value, 0, 100) . '...' : $value);
    }
}
