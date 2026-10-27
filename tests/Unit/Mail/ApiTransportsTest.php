<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Unit\Mail;

use DateTimeImmutable;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use VeyroPHP\Application\Application;
use VeyroPHP\Config\Config;
use VeyroPHP\Mail\Attachment;
use VeyroPHP\Mail\Mailer;
use VeyroPHP\Mail\MailException;
use VeyroPHP\Mail\MailServiceProvider;
use VeyroPHP\Mail\Message;
use VeyroPHP\Mail\SentMessage;
use VeyroPHP\Mail\Transport\BrevoTransport;
use VeyroPHP\Mail\Transport\MailgunTransport;
use VeyroPHP\Mail\Transport\SesTransport;
use VeyroPHP\Mail\TransportException;
use VeyroPHP\Tests\Fixtures\Http\FakeMailApi;
use VeyroPHP\Tests\Fixtures\Http\FakeNetworkError;

/**
 * Mailgun, Amazon SES and Brevo, against a fake HTTP client: the requests must match
 * each API's documented format. No request leaves the machine.
 */
#[CoversClass(MailgunTransport::class)]
#[CoversClass(SesTransport::class)]
#[CoversClass(BrevoTransport::class)]
#[CoversClass(\VeyroPHP\Support\ApiCall::class)]
#[CoversClass(TransportException::class)]
final class ApiTransportsTest extends TestCase
{
    private FakeMailApi $api;

    protected function setUp(): void
    {
        $this->api = new FakeMailApi();
    }

    private static function sent(): SentMessage
    {
        $message = (new Message())
            ->from('shop@example.com', 'Shop')
            ->to('asha@example.test', 'Asha')
            ->cc('ravi@example.test')
            ->bcc('audit@example.test')
            ->replyTo('help@example.com', 'Help')
            ->subject('Order 42 — धन्यवाद')
            ->text('Thanks')
            ->html('<p>Thanks</p>')
            ->header('X-Order', '42')
            ->attach(Attachment::fromData('%PDF-1.4', 'invoice.pdf', 'application/pdf'));

        return new SentMessage(
            $message,
            "Message-ID: <abc@example.com>\r\nSubject: Order 42\r\n\r\nThanks",
            'abc@example.com',
            'shop@example.com',
            $message->recipients(),
            'api',
        );
    }

    public function testMailgunPostsTheMimeTextWithEveryEnvelopeRecipient(): void
    {
        (new MailgunTransport($this->api, 'mg.example.com', 'key-secret', 'https://api.eu.mailgun.net'))->send(self::sent());
        $request = $this->api->last();
        $body = (string) $request->getBody();

        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://api.eu.mailgun.net/v3/mg.example.com/messages.mime', (string) $request->getUri());
        self::assertSame('Basic ' . base64_encode('api:key-secret'), $request->getHeaderLine('Authorization'));
        self::assertMatchesRegularExpression('/^multipart\/form-data; boundary=(veyrophp-[A-Za-z0-9_-]+)$/', $request->getHeaderLine('Content-Type'));

        $boundary = substr($request->getHeaderLine('Content-Type'), strlen('multipart/form-data; boundary='));
        self::assertStringContainsString("--{$boundary}\r\nContent-Disposition: form-data; name=\"to\"\r\n\r\nasha@example.test,ravi@example.test,audit@example.test\r\n", $body, 'bcc only in the envelope');
        self::assertStringContainsString("Content-Disposition: form-data; name=\"message\"; filename=\"message.mime\"\r\nContent-Type: message/rfc822\r\n\r\nMessage-ID: <abc@example.com>", $body);
        self::assertStringEndsWith("--{$boundary}--\r\n", $body);
    }

    public function testSesSendsRawContentSignedForTheRegion(): void
    {
        $transport = new SesTransport($this->api, 'AKIDEXAMPLE', 'secret', 'ap-south-1', 'transactional', clock: static fn(): DateTimeImmutable => new DateTimeImmutable('2027-01-15 06:30:00 UTC'));
        $transport->send(self::sent());
        $request = $this->api->last();
        $payload = json_decode((string) $request->getBody(), true);

        self::assertSame('https://email.ap-south-1.amazonaws.com/v2/email/outbound-emails', (string) $request->getUri());
        self::assertSame([
            'FromEmailAddress' => 'shop@example.com',
            'Destination' => ['ToAddresses' => ['asha@example.test', 'ravi@example.test', 'audit@example.test']],
            'Content' => ['Raw' => ['Data' => base64_encode(self::sent()->raw)]],
            'ConfigurationSetName' => 'transactional',
        ], $payload);
        self::assertStringStartsWith('AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/20270115/ap-south-1/ses/aws4_request,SignedHeaders=', $request->getHeaderLine('Authorization'));
        self::assertSame('20270115T063000Z', $request->getHeaderLine('x-amz-date'));
        self::assertSame(hash('sha256', (string) $request->getBody()), $request->getHeaderLine('x-amz-content-sha256'));
    }

    public function testBrevoMapsTheMessageToItsJsonFields(): void
    {
        (new BrevoTransport($this->api, 'xkeysib-secret'))->send(self::sent());
        $request = $this->api->last();
        $payload = json_decode((string) $request->getBody(), true);

        self::assertSame('https://api.brevo.com/v3/smtp/email', (string) $request->getUri());
        self::assertSame('xkeysib-secret', $request->getHeaderLine('api-key'));
        self::assertSame([
            'sender' => ['email' => 'shop@example.com', 'name' => 'Shop'],
            'subject' => 'Order 42 — धन्यवाद',
            'to' => [['email' => 'asha@example.test', 'name' => 'Asha']],
            'cc' => [['email' => 'ravi@example.test']],
            'bcc' => [['email' => 'audit@example.test']],
            'replyTo' => ['email' => 'help@example.com', 'name' => 'Help'],
            'htmlContent' => '<p>Thanks</p>',
            'textContent' => 'Thanks',
            'attachment' => [['name' => 'invoice.pdf', 'content' => base64_encode('%PDF-1.4')]],
            'headers' => ['Message-Id' => '<abc@example.com>', 'X-Order' => '42'],
        ], $payload);
    }

    public function testRefusalsAndNetworkErrorsBecomeTransportExceptions(): void
    {
        $cases = [
            [new Response(401, [], '{"message":"Invalid private key"}'), 'Mailgun refused the message (HTTP 401): Invalid private key', false],
            [new Response(429, [], 'slow down'), 'Mailgun refused the message (HTTP 429): slow down', true],
            [new Response(503), 'Mailgun refused the message (HTTP 503): Service Unavailable', true],
            [new FakeNetworkError("timeout\r\nInjected: header"), 'Mailgun could not be reached: timeout Injected: header', true],
        ];

        foreach ($cases as [$answer, $message, $retryable]) {
            $this->api->answer($answer);

            try {
                (new MailgunTransport($this->api, 'mg.example.com', 'key'))->send(self::sent());
                self::fail('TransportException expected');
            } catch (TransportException $e) {
                self::assertSame($message, $e->getMessage());
                self::assertSame($retryable, $e->isRetryable(), $message);
            }
        }
    }

    public function testSettingsAreCheckedWhenTheTransportIsCreated(): void
    {
        $invalid = [
            fn() => new MailgunTransport($this->api, 'mg.example.com/evil', 'key'),
            fn() => new MailgunTransport($this->api, 'mg.example.com', ''),
            fn() => new MailgunTransport($this->api, 'mg.example.com', 'key', 'http://api.mailgun.net'),
            fn() => new SesTransport($this->api, 'AKID', 'secret', 'mumbai'),
            fn() => new SesTransport($this->api, '', 'secret', 'ap-south-1'),
            fn() => new BrevoTransport($this->api, ''),
        ];

        foreach ($invalid as $i => $create) {
            try {
                $create();
                self::fail('MailException expected for case ' . $i);
            } catch (MailException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testTheMailServiceProviderBuildsApiMailersFromConfig(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->container()->instance(Config::class, new Config(['mail' => [
            'default' => 'brevo',
            'from' => ['address' => 'shop@example.com'],
            'mailers' => [
                'brevo' => ['transport' => 'brevo', 'key' => 'xkeysib'],
                'mailgun' => ['transport' => 'mailgun', 'domain' => 'mg.example.com', 'secret' => 'key'],
                'ses' => ['transport' => 'ses', 'key' => 'AKID', 'secret' => 's', 'region' => 'ap-south-1'],
                'broken' => ['transport' => 'mailgun', 'secret' => 'key'],
            ],
        ]]));
        $app->container()->instance(ClientInterface::class, $this->api);
        $app->register(new MailServiceProvider());
        $app->boot();
        $mailer = $app->container()->make(Mailer::class);

        self::assertInstanceOf(BrevoTransport::class, $mailer->transport('brevo'));
        self::assertInstanceOf(MailgunTransport::class, $mailer->transport('mailgun'));
        self::assertInstanceOf(SesTransport::class, $mailer->transport('ses'));

        $mailer->send((new Message())->to('asha@example.test')->subject('Hi')->text('Hello'));
        self::assertSame('https://api.brevo.com/v3/smtp/email', (string) $this->api->last()->getUri());

        $this->expectException(MailException::class);
        $this->expectExceptionMessage('Mailer "broken" needs "domain"');
        $mailer->transport('broken');
    }
}
