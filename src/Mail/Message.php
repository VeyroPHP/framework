<?php

declare(strict_types=1);

namespace VeyroPHP\Mail;

/**
 * An email to send.
 *
 *     $message = (new Message())
 *         ->to('asha@example.com', 'Asha')
 *         ->subject('Your invoice')
 *         ->view('mail.invoice', ['invoice' => $invoice])   // HTML from a view
 *         ->attach(Attachment::fromPath($pdf));
 *
 * - Body: html() and/or text(), or view() / textView() rendered by the Mailer.
 *   With only HTML, the Mailer adds a plain-text version (better delivery).
 * - from() is optional when config mail.from is set.
 * - Every value is checked for line breaks, so user input cannot add headers.
 */
final class Message
{
    private ?Address $from = null;

    /** @var list<Address> */
    private array $to = [];

    /** @var list<Address> */
    private array $cc = [];

    /** @var list<Address> */
    private array $bcc = [];

    /** @var list<Address> */
    private array $replyTo = [];

    private string $subject = '';

    private ?string $text = null;

    private ?string $html = null;

    /** @var array{string, array<string, mixed>}|null */
    private ?array $view = null;

    /** @var array{string, array<string, mixed>}|null */
    private ?array $textView = null;

    /** @var list<Attachment> */
    private array $attachments = [];

    /** @var array<string, string> */
    private array $headers = [];

    /** Headers the MIME builder writes itself. */
    private const RESERVED_HEADERS = [
        'from', 'to', 'cc', 'bcc', 'reply-to', 'subject', 'date', 'message-id',
        'mime-version', 'content-type', 'content-transfer-encoding', 'content-disposition',
        'sender', 'return-path',
    ];

    public function from(string|Address $address, string $name = ''): self
    {
        $this->from = self::address($address, $name);

        return $this;
    }

    public function to(string|Address $address, string $name = ''): self
    {
        $this->to[] = self::address($address, $name);

        return $this;
    }

    public function cc(string|Address $address, string $name = ''): self
    {
        $this->cc[] = self::address($address, $name);

        return $this;
    }

    /**
     * Receives the message without appearing in its headers.
     */
    public function bcc(string|Address $address, string $name = ''): self
    {
        $this->bcc[] = self::address($address, $name);

        return $this;
    }

    public function replyTo(string|Address $address, string $name = ''): self
    {
        $this->replyTo[] = self::address($address, $name);

        return $this;
    }

    public function subject(string $subject): self
    {
        if (preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $subject) === 1) {
            throw new MailException('The subject must not contain line breaks or control characters.');
        }

        $this->subject = $subject;

        return $this;
    }

    public function text(string $text): self
    {
        $this->text = $text;

        return $this;
    }

    public function html(string $html): self
    {
        $this->html = $html;

        return $this;
    }

    /**
     * HTML body from a view, rendered when the message is sent. Escape data with $e() as in any view.
     *
     * @param array<string, mixed> $data
     */
    public function view(string $name, array $data = []): self
    {
        $this->view = [$name, $data];

        return $this;
    }

    /**
     * Plain-text body from a view, rendered when the message is sent.
     *
     * @param array<string, mixed> $data
     */
    public function textView(string $name, array $data = []): self
    {
        $this->textView = [$name, $data];

        return $this;
    }

    public function attach(Attachment $attachment): self
    {
        $this->attachments[] = $attachment;

        return $this;
    }

    /**
     * An extra header such as "List-Unsubscribe" or "X-Campaign".
     */
    public function header(string $name, string $value): self
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9-]*$/D', $name) !== 1) {
            throw new MailException(sprintf('Invalid header name "%s".', $name));
        }

        if (in_array(strtolower($name), self::RESERVED_HEADERS, true)) {
            throw new MailException(sprintf('Header "%s" is set by the message itself; use its method instead.', $name));
        }

        if (preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $value) === 1) {
            throw new MailException(sprintf('Header "%s" must not contain line breaks or control characters.', $name));
        }

        $this->headers[$name] = $value;

        return $this;
    }

    public function getFrom(): ?Address
    {
        return $this->from;
    }

    /** @return list<Address> */
    public function getTo(): array
    {
        return $this->to;
    }

    /** @return list<Address> */
    public function getCc(): array
    {
        return $this->cc;
    }

    /** @return list<Address> */
    public function getBcc(): array
    {
        return $this->bcc;
    }

    /** @return list<Address> */
    public function getReplyTo(): array
    {
        return $this->replyTo;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getText(): ?string
    {
        return $this->text;
    }

    public function getHtml(): ?string
    {
        return $this->html;
    }

    /** @return array{string, array<string, mixed>}|null */
    public function getView(): ?array
    {
        return $this->view;
    }

    /** @return array{string, array<string, mixed>}|null */
    public function getTextView(): ?array
    {
        return $this->textView;
    }

    /** @return list<Attachment> */
    public function getAttachments(): array
    {
        return $this->attachments;
    }

    /** @return array<string, string> */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Everyone who receives the message: to, cc and bcc, without duplicates.
     *
     * @return list<string>
     */
    public function recipients(): array
    {
        $emails = [];

        foreach ([...$this->to, ...$this->cc, ...$this->bcc] as $address) {
            $emails[strtolower($address->email)] ??= $address->email;
        }

        return array_values($emails);
    }

    private static function address(string|Address $address, string $name): Address
    {
        if ($address instanceof Address) {
            return $name === '' ? $address : new Address($address->email, $name);
        }

        return $name === '' ? Address::parse($address) : new Address($address, $name);
    }
}
