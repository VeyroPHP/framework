<?php

declare(strict_types=1);

namespace VeyroPHP\Mail\Transport;

use Closure;
use DateTimeImmutable;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use SensitiveParameter;
use VeyroPHP\Mail\MailException;
use VeyroPHP\Mail\SentMessage;
use VeyroPHP\Mail\TransportException;
use VeyroPHP\Support\ApiCall;
use VeyroPHP\Support\AwsSignatureV4;

/**
 * Sends through the Amazon SES v2 API (SendEmail with raw content), signed with AWS
 * Signature Version 4. The MIME text is the one the mailer built; every envelope
 * recipient, Bcc included, is in "Destination".
 *
 *     'ses' => ['transport' => 'ses', 'key' => env('AWS_ACCESS_KEY_ID'),
 *               'secret' => env('AWS_SECRET_ACCESS_KEY'), 'region' => 'ap-south-1',
 *               'configuration_set' => null],
 */
final class SesTransport implements Transport
{
    private readonly AwsSignatureV4 $signer;

    /**
     * @param (Closure(): DateTimeImmutable)|null $clock
     */
    public function __construct(
        private readonly ClientInterface $client,
        string $accessKey,
        #[SensitiveParameter]
        string $secretKey,
        private readonly string $region,
        private readonly ?string $configurationSet = null,
        private readonly Psr17Factory $factory = new Psr17Factory(),
        private readonly ?Closure $clock = null,
    ) {
        if ($accessKey === '' || $secretKey === '') {
            throw new MailException('Amazon SES needs an access key and a secret key.');
        }

        if (preg_match('/^[a-z]{2}(-[a-z]+)+-\d+$/D', $region) !== 1) {
            throw new MailException('The SES region must look like ap-south-1.');
        }

        $this->signer = new AwsSignatureV4($accessKey, $secretKey, $region, 'ses');
    }

    public function send(SentMessage $message): void
    {
        $payload = [
            'FromEmailAddress' => $message->sender,
            'Destination' => ['ToAddresses' => $message->recipients],
            'Content' => ['Raw' => ['Data' => base64_encode($message->raw)]],
        ];

        if ($this->configurationSet !== null && $this->configurationSet !== '') {
            $payload['ConfigurationSetName'] = $this->configurationSet;
        }

        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $request = $this->factory->createRequest('POST', 'https://email.' . $this->region . '.amazonaws.com/v2/email/outbound-emails')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->factory->createStream($body));

        $now = $this->clock === null ? new DateTimeImmutable() : ($this->clock)();
        ApiCall::send($this->client, $this->signer->sign($request, hash('sha256', $body), $now), 'Amazon SES', 'message', TransportException::fromApi(...));
    }
}
