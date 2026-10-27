<?php

declare(strict_types=1);

namespace VeyroPHP\Mail\Transport;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use SensitiveParameter;
use VeyroPHP\Mail\MailException;
use VeyroPHP\Mail\SentMessage;
use VeyroPHP\Mail\TransportException;
use VeyroPHP\Support\ApiCall;
use VeyroPHP\Support\Base64Url;

/**
 * Sends through the Mailgun HTTP API, as the MIME text the mailer built (the same
 * headers, bodies and attachments as over SMTP). Bcc recipients are only in the
 * envelope ("to" field), never in the message.
 *
 *     'mailgun' => ['transport' => 'mailgun', 'domain' => 'mg.example.com',
 *                   'secret' => env('MAILGUN_SECRET'), 'endpoint' => 'https://api.eu.mailgun.net'],
 */
final class MailgunTransport implements Transport
{
    public function __construct(
        private readonly ClientInterface $client,
        private readonly string $domain,
        #[SensitiveParameter]
        private readonly string $secret,
        private readonly string $endpoint = 'https://api.mailgun.net',
        private readonly Psr17Factory $factory = new Psr17Factory(),
    ) {
        if (preg_match('/^[A-Za-z0-9.-]+$/D', $domain) !== 1) {
            throw new MailException('The Mailgun domain must be a host name such as mg.example.com.');
        }

        if ($secret === '') {
            throw new MailException('The Mailgun API key (secret) is empty.');
        }

        if (preg_match('#^https://[A-Za-z0-9.-]+(:\d+)?$#D', $endpoint) !== 1) {
            throw new MailException('The Mailgun endpoint must be an https:// URL without a path, such as https://api.eu.mailgun.net.');
        }
    }

    public function send(SentMessage $message): void
    {
        $boundary = 'veyrophp-' . Base64Url::secret(12);
        $body = self::part($boundary, 'to', implode(',', $message->recipients))
            . self::part($boundary, 'message', $message->raw, 'message.mime', 'message/rfc822')
            . '--' . $boundary . "--\r\n";

        $request = $this->factory->createRequest('POST', $this->endpoint . '/v3/' . $this->domain . '/messages.mime')
            ->withHeader('Authorization', 'Basic ' . base64_encode('api:' . $this->secret))
            ->withHeader('Accept', 'application/json')
            ->withHeader('Content-Type', 'multipart/form-data; boundary=' . $boundary)
            ->withBody($this->factory->createStream($body));

        ApiCall::send($this->client, $request, 'Mailgun', 'message', TransportException::fromApi(...));
    }

    private static function part(string $boundary, string $name, string $content, ?string $filename = null, ?string $type = null): string
    {
        return '--' . $boundary . "\r\n"
            . 'Content-Disposition: form-data; name="' . $name . '"' . ($filename === null ? '' : '; filename="' . $filename . '"') . "\r\n"
            . ($type === null ? '' : 'Content-Type: ' . $type . "\r\n")
            . "\r\n" . $content . "\r\n";
    }
}
