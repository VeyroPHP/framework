<?php

declare(strict_types=1);

namespace VeyroPHP\Notifications\Sms;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use SensitiveParameter;
use VeyroPHP\Notifications\NotificationException;
use VeyroPHP\Support\ApiCall;

/**
 * Twilio Programmable Messaging. $from is a Twilio number (+1...) or a Messaging
 * Service SID (MG...).
 *
 *     'sms' => ['gateway' => 'twilio', 'sid' => env('TWILIO_SID'), 'token' => env('TWILIO_TOKEN'),
 *               'from' => '+15005550006'],
 */
final class TwilioGateway implements SmsGateway
{
    public function __construct(
        private readonly ClientInterface $client,
        private readonly string $accountSid,
        #[SensitiveParameter]
        private readonly string $authToken,
        private readonly string $from,
        private readonly Psr17Factory $factory = new Psr17Factory(),
    ) {
        if (preg_match('/^AC[0-9a-fA-F]{32}$/D', $accountSid) !== 1) {
            throw new NotificationException('The Twilio account SID must look like AC followed by 32 hex digits.');
        }

        if ($authToken === '' || $from === '') {
            throw new NotificationException('Twilio needs an auth token and a "from" number or Messaging Service SID.');
        }
    }

    public function send(SmsMessage $message, string $to): void
    {
        $sender = str_starts_with($this->from, 'MG') ? ['MessagingServiceSid' => $this->from] : ['From' => $this->from];
        $body = http_build_query(['To' => $to, 'Body' => $message->text] + $sender, '', '&', PHP_QUERY_RFC3986);

        $request = $this->factory->createRequest('POST', 'https://api.twilio.com/2010-04-01/Accounts/' . $this->accountSid . '/Messages.json')
            ->withHeader('Authorization', 'Basic ' . base64_encode($this->accountSid . ':' . $this->authToken))
            ->withHeader('Accept', 'application/json')
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withBody($this->factory->createStream($body));

        ApiCall::send($this->client, $request, 'Twilio', 'SMS', NotificationException::fromApi(...));
    }
}
