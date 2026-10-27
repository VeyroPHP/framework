<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Unit\Notifications;

use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Log\AbstractLogger;
use Stringable;
use VeyroPHP\Application\Application;
use VeyroPHP\Config\Config;
use VeyroPHP\Notifications\Channels\SmsChannel;
use VeyroPHP\Notifications\Notification;
use VeyroPHP\Notifications\NotificationException;
use VeyroPHP\Notifications\NotificationServiceProvider;
use VeyroPHP\Notifications\Notifier;
use VeyroPHP\Notifications\Sms\ArrayGateway;
use VeyroPHP\Notifications\Sms\LogGateway;
use VeyroPHP\Notifications\Sms\Msg91Gateway;
use VeyroPHP\Notifications\Sms\SmsMessage;
use VeyroPHP\Notifications\Sms\TwilioGateway;
use VeyroPHP\Orm\Model;
use VeyroPHP\Tests\Fixtures\Http\FakeMailApi;

final class SmsUser extends Model
{
    protected const TABLE = 'users';
}

final class RoutedSmsUser extends Model
{
    protected const TABLE = 'users';

    public function routeNotificationForSms(): string
    {
        return '+91 98765-43210';
    }
}

final class OtpNotification extends Notification
{
    public function __construct(private readonly string $otp, private readonly ?string $to = null) {}

    public function via(Model $notifiable): array
    {
        return ['sms'];
    }

    public function toSms(Model $notifiable): SmsMessage
    {
        $message = (new SmsMessage('Your OTP is ' . $this->otp))->template('6512abc', ['otp' => $this->otp]);

        return $this->to === null ? $message : $message->to($this->to);
    }
}

/**
 * The "sms" channel, its number rules, and the MSG91 and Twilio request formats
 * (against a fake HTTP client; nothing is sent).
 */
#[CoversClass(SmsChannel::class)]
#[CoversClass(SmsMessage::class)]
#[CoversClass(Msg91Gateway::class)]
#[CoversClass(TwilioGateway::class)]
#[CoversClass(ArrayGateway::class)]
#[CoversClass(LogGateway::class)]
final class SmsTest extends TestCase
{
    /**
     * @param array<string, mixed> $row
     */
    private static function user(array $row): SmsUser
    {
        return SmsUser::hydrate($row + ['id' => 1]);
    }

    public function testTheChannelSendsToTheModelsNumberInE164(): void
    {
        $gateway = new ArrayGateway();
        $channel = new SmsChannel($gateway, '91');

        foreach ([
            '+91 98765 43210' => '+919876543210',
            '9876543210' => '+919876543210',
            '09876543210' => '+919876543210',
            '919876543210' => '+919876543210',
            '(+1) 415-555-0100' => '+14155550100',
        ] as $stored => $sent) {
            $channel->send(self::user(['phone' => $stored]), new OtpNotification('123456'));
            self::assertSame($sent, $gateway->sent()[count($gateway->sent()) - 1]['to'], (string) $stored);
        }

        $channel->send(RoutedSmsUser::hydrate(['id' => 2]), new OtpNotification('1'));
        self::assertSame('+919876543210', $gateway->sent()[count($gateway->sent()) - 1]['to'], 'routeNotificationForSms()');

        $channel->send(self::user(['phone' => '+1 4155550100']), new OtpNotification('1', '+447700900123'));
        self::assertSame('+447700900123', $gateway->sent()[count($gateway->sent()) - 1]['to'], 'SmsMessage::to() wins');
    }

    public function testNumbersThatCannotBeTrustedAreRefused(): void
    {
        $cases = [
            [new SmsChannel(new ArrayGateway()), ['phone' => '9876543210'], 'no country code configured'],
            [new SmsChannel(new ArrayGateway(), '91'), ['phone' => 'call me'], 'not a number'],
            [new SmsChannel(new ArrayGateway(), '91'), ['phone' => ''], 'empty'],
            [new SmsChannel(new ArrayGateway(), '91'), [], 'missing'],
            [new SmsChannel(new ArrayGateway(), '91'), ['phone' => '+0123456789'], 'country code 0'],
        ];

        foreach ($cases as [$channel, $row, $case]) {
            try {
                $channel->send(self::user($row), new OtpNotification('1'));
                self::fail('NotificationException expected: ' . $case);
            } catch (NotificationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testMsg91SendsTheDltTemplateWithItsVariables(): void
    {
        $api = new FakeMailApi(new Response(200, [], '{"type":"success"}'));
        (new Msg91Gateway($api, 'auth-key'))->send((new SmsMessage('Your OTP is 482913'))->template('6512abc', ['otp' => '482913']), '+919876543210');
        $request = $api->last();

        self::assertSame('https://control.msg91.com/api/v5/flow', (string) $request->getUri());
        self::assertSame('auth-key', $request->getHeaderLine('authkey'));
        self::assertSame(
            ['template_id' => '6512abc', 'short_url' => '0', 'recipients' => [['mobiles' => '919876543210', 'otp' => '482913']]],
            json_decode((string) $request->getBody(), true),
        );

        $this->expectException(NotificationException::class);
        $this->expectExceptionMessage('MSG91 sends only DLT templates');
        (new Msg91Gateway($api, 'auth-key'))->send(new SmsMessage('plain text'), '+919876543210');
    }

    public function testTwilioPostsAFormWithBasicAuth(): void
    {
        $api = new FakeMailApi(new Response(201, [], '{"sid":"SM1"}'));
        $sid = 'AC' . str_repeat('a', 32);
        (new TwilioGateway($api, $sid, 'token', '+15005550006'))->send(new SmsMessage('Hello & bye'), '+14155550100');
        $request = $api->last();

        self::assertSame('https://api.twilio.com/2010-04-01/Accounts/' . $sid . '/Messages.json', (string) $request->getUri());
        self::assertSame('Basic ' . base64_encode($sid . ':token'), $request->getHeaderLine('Authorization'));
        parse_str((string) $request->getBody(), $form);
        self::assertSame(['To' => '+14155550100', 'Body' => 'Hello & bye', 'From' => '+15005550006'], $form);

        (new TwilioGateway($api, $sid, 'token', 'MG' . str_repeat('b', 32)))->send(new SmsMessage('Hi'), '+14155550100');
        parse_str((string) $api->last()->getBody(), $form);
        self::assertSame('MG' . str_repeat('b', 32), $form['MessagingServiceSid'] ?? null);
    }

    public function testGatewayRefusalsBecomeNotificationExceptions(): void
    {
        $api = new FakeMailApi(new Response(401, [], '{"message":"Authentication failure"}'));

        try {
            (new TwilioGateway($api, 'AC' . str_repeat('a', 32), 'token', '+15005550006'))->send(new SmsMessage('Hi'), '+14155550100');
            self::fail('NotificationException expected');
        } catch (NotificationException $e) {
            self::assertSame('Twilio refused the SMS (HTTP 401): Authentication failure', $e->getMessage());
            self::assertSame(401, $e->getCode());
        }
    }

    public function testTheProviderWiresTheSmsChannel(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->container()->instance(Config::class, new Config(['notifications' => ['sms' => ['gateway' => 'array', 'default_country_code' => '91']]]));
        $app->register(new NotificationServiceProvider());
        $app->boot();

        $app->container()->make(Notifier::class)->send(self::user(['phone' => '9876543210']), new OtpNotification('777'));
        $sent = $app->container()->make(ArrayGateway::class)->sent();

        self::assertCount(1, $sent);
        self::assertSame('+919876543210', $sent[0]['to']);
        self::assertSame('Your OTP is 777', $sent[0]['message']->text);
    }

    public function testTheProviderNeedsGatewaySettings(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->container()->instance(Config::class, new Config(['notifications' => ['sms' => ['gateway' => 'msg91']]]));
        $app->container()->instance(ClientInterface::class, new FakeMailApi());
        $app->register(new NotificationServiceProvider());
        $app->boot();

        $this->expectException(NotificationException::class);
        $this->expectExceptionMessage('The "msg91" SMS gateway needs notifications.sms.key.');
        $app->container()->make(\VeyroPHP\Notifications\Sms\SmsGateway::class);
    }

    public function testTheLogGatewayWritesTheMessage(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $lines = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->lines[] = strtr((string) $message, ['{to}' => is_string($context['to'] ?? null) ? $context['to'] : '', '{text}' => is_string($context['text'] ?? null) ? $context['text'] : '']);
            }
        };

        (new LogGateway($logger))->send(new SmsMessage('Hi'), '+919876543210');
        self::assertSame(['SMS to +919876543210: Hi'], $logger->lines);
    }

    public function testMessagesAreChecked(): void
    {
        foreach ([fn() => new SmsMessage('  '), fn() => (new SmsMessage('x'))->template('bad id!')] as $create) {
            try {
                $create();
                self::fail('NotificationException expected');
            } catch (NotificationException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
