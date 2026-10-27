<?php

declare(strict_types=1);

namespace VeyroPHP\Notifications\Sms;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use SensitiveParameter;
use VeyroPHP\Notifications\NotificationException;
use VeyroPHP\Support\ApiCall;

/**
 * MSG91 (India), through its Flow API. Indian SMS rules (DLT) allow only registered
 * templates, so every message needs a template id: SmsMessage::template($id, $vars).
 * The variables fill the template's ##name## placeholders; the text is not sent.
 *
 *     'sms' => ['gateway' => 'msg91', 'key' => env('MSG91_AUTH_KEY')],
 */
final class Msg91Gateway implements SmsGateway
{
    public function __construct(
        private readonly ClientInterface $client,
        #[SensitiveParameter]
        private readonly string $authKey,
        private readonly Psr17Factory $factory = new Psr17Factory(),
    ) {
        if ($authKey === '') {
            throw new NotificationException('The MSG91 auth key is empty.');
        }
    }

    public function send(SmsMessage $message, string $to): void
    {
        $template = $message->getTemplateId() ?? throw new NotificationException('MSG91 sends only DLT templates: call template($id, $variables) on the SmsMessage.');

        $body = json_encode([
            'template_id' => $template,
            'short_url' => '0',
            // MSG91 wants the number with country code and without "+".
            'recipients' => [['mobiles' => ltrim($to, '+')] + $message->getVariables()],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $request = $this->factory->createRequest('POST', 'https://control.msg91.com/api/v5/flow')
            ->withHeader('authkey', $this->authKey)
            ->withHeader('Accept', 'application/json')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->factory->createStream($body));

        ApiCall::send($this->client, $request, 'MSG91', 'SMS', NotificationException::fromApi(...));
    }
}
