<?php

declare(strict_types=1);

namespace VeyroPHP\Mail\Transport;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use SensitiveParameter;
use VeyroPHP\Mail\Address;
use VeyroPHP\Mail\MailException;
use VeyroPHP\Mail\SentMessage;
use VeyroPHP\Mail\TransportException;
use VeyroPHP\Support\ApiCall;

/**
 * Sends through the Brevo (formerly Sendinblue) transactional email API. Brevo takes
 * the message as JSON fields, not MIME, so the parts are mapped one by one: sender,
 * to, cc, bcc, the first reply-to, subject, HTML and text bodies, attachments, and
 * the message's own extra headers.
 *
 *     'brevo' => ['transport' => 'brevo', 'key' => env('BREVO_API_KEY')],
 */
final class BrevoTransport implements Transport
{
    public function __construct(
        private readonly ClientInterface $client,
        #[SensitiveParameter]
        private readonly string $apiKey,
        private readonly string $endpoint = 'https://api.brevo.com',
        private readonly Psr17Factory $factory = new Psr17Factory(),
    ) {
        if ($apiKey === '') {
            throw new MailException('The Brevo API key is empty.');
        }

        if (preg_match('#^https://[A-Za-z0-9.-]+(:\d+)?$#D', $endpoint) !== 1) {
            throw new MailException('The Brevo endpoint must be an https:// URL without a path.');
        }
    }

    public function send(SentMessage $message): void
    {
        $mail = $message->message;
        $from = $mail->getFrom() ?? Address::parse($message->sender);
        $payload = ['sender' => self::address($from), 'subject' => $mail->getSubject()];

        foreach (['to' => $mail->getTo(), 'cc' => $mail->getCc(), 'bcc' => $mail->getBcc()] as $key => $addresses) {
            if ($addresses !== []) {
                $payload[$key] = array_map(self::address(...), $addresses);
            }
        }

        if (($replyTo = $mail->getReplyTo()[0] ?? null) !== null) {
            $payload['replyTo'] = self::address($replyTo);
        }

        if ($mail->getHtml() !== null) {
            $payload['htmlContent'] = $mail->getHtml();
        }

        if ($mail->getText() !== null) {
            $payload['textContent'] = $mail->getText();
        }

        foreach ($mail->getAttachments() as $attachment) {
            $payload['attachment'][] = ['name' => $attachment->name, 'content' => base64_encode($attachment->data)];
        }

        $payload['headers'] = ['Message-Id' => '<' . $message->messageId . '>'] + $mail->getHeaders();

        $request = $this->factory->createRequest('POST', $this->endpoint . '/v3/smtp/email')
            ->withHeader('api-key', $this->apiKey)
            ->withHeader('Accept', 'application/json')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->factory->createStream(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));

        ApiCall::send($this->client, $request, 'Brevo', 'message', TransportException::fromApi(...));
    }

    /**
     * @return array{email: string, name?: string}
     */
    private static function address(Address $address): array
    {
        return $address->name === '' ? ['email' => $address->email] : ['email' => $address->email, 'name' => $address->name];
    }
}
