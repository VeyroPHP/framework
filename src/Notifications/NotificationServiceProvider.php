<?php

declare(strict_types=1);

namespace VeyroPHP\Notifications;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use VeyroPHP\Application\Application;
use VeyroPHP\Application\ServiceProvider;
use VeyroPHP\Config\Config;
use VeyroPHP\Config\OptionalSettings;
use VeyroPHP\Container\Container;
use VeyroPHP\Database\Connection\Connection;
use VeyroPHP\Notifications\Channels\Channel;
use VeyroPHP\Notifications\Channels\DatabaseChannel;
use VeyroPHP\Notifications\Channels\MailChannel;
use VeyroPHP\Notifications\Channels\SmsChannel;
use VeyroPHP\Notifications\Sms\ArrayGateway;
use VeyroPHP\Notifications\Sms\LogGateway;
use VeyroPHP\Notifications\Sms\Msg91Gateway;
use VeyroPHP\Notifications\Sms\SmsGateway;
use VeyroPHP\Notifications\Sms\TwilioGateway;
use VeyroPHP\Queue\Queue;
use VeyroPHP\Support\HttpClient;

/**
 * Registers Notifier and DatabaseNotifications.
 *
 * Built-in channels: "mail" (needs MailServiceProvider), "database" (needs the
 * notifications table) and "sms". Config (config/notifications.php):
 *
 *     'sms' => [
 *         'gateway' => 'msg91',               // msg91, twilio, log (default), array, or a SmsGateway class
 *         'key' => env('MSG91_AUTH_KEY'),     // msg91
 *         'sid' => '...', 'token' => '...', 'from' => '+1...',   // twilio
 *         'default_country_code' => '91',     // for numbers stored without "+"
 *     ],
 *     'channels' => ['whatsapp' => App\Notifications\WhatsAppChannel::class],   // implements Channel
 *
 * msg91 and twilio need guzzlehttp/guzzle or a bound PSR-18 ClientInterface.
 */
final class NotificationServiceProvider implements ServiceProvider
{
    public function register(Application $app): void
    {
        $container = $app->container();

        $container->singleton(DatabaseNotifications::class, static function (Container $c): DatabaseNotifications {
            $table = $c->make(Config::class)->get('notifications.table', 'notifications');

            return new DatabaseNotifications($c->make(Connection::class), is_string($table) ? $table : 'notifications');
        });

        // One in-memory gateway per application, so tests can read what was sent.
        $container->singleton(ArrayGateway::class, static fn(): ArrayGateway => new ArrayGateway());
        $container->bind(SmsGateway::class, static fn(Container $c): SmsGateway => self::smsGateway($c));

        $container->singleton(Notifier::class, static fn(Container $c): Notifier => new Notifier(
            static fn(string $name): Channel => self::channel($name, $c),
            $c->bound(Queue::class) ? static fn(): Queue => $c->make(Queue::class) : null,
            $c->bound(EventDispatcherInterface::class) ? $c->make(EventDispatcherInterface::class) : null,
            $c->bound(LoggerInterface::class) ? $c->make(LoggerInterface::class) : new NullLogger(),
        ));
    }

    private static function channel(string $name, Container $c): Channel
    {
        $custom = $c->make(Config::class)->get('notifications.channels.' . $name);

        if (is_string($custom)) {
            if (!is_subclass_of($custom, Channel::class)) {
                throw new NotificationException(sprintf('Notification channel "%s" (%s) must implement %s.', $name, $custom, Channel::class));
            }

            return $c->make($custom);
        }

        return match ($name) {
            'mail' => $c->make(MailChannel::class),
            'database' => $c->make(DatabaseChannel::class),
            'sms' => new SmsChannel(
                $c->make(SmsGateway::class),
                OptionalSettings::nullableString($c->make(Config::class), 'notifications.sms.default_country_code'),
            ),
            default => throw new NotificationException(sprintf('Unknown notification channel "%s"; configure it in notifications.channels.', $name)),
        };
    }

    private static function smsGateway(Container $c): SmsGateway
    {
        $config = $c->make(Config::class);
        $gateway = OptionalSettings::string($config, 'notifications.sms.gateway', 'log');
        $setting = static fn(string $key): string => OptionalSettings::nullableString($config, 'notifications.sms.' . $key)
            ?? throw new NotificationException(sprintf('The "%s" SMS gateway needs notifications.sms.%s.', $gateway, $key));
        $client = static fn(): ClientInterface => HttpClient::resolve($c, sprintf('The "%s" SMS gateway', $gateway), static fn(string $message): NotificationException => new NotificationException($message));

        return match (true) {
            $gateway === 'msg91' => new Msg91Gateway($client(), $setting('key')),
            $gateway === 'twilio' => new TwilioGateway($client(), $setting('sid'), $setting('token'), $setting('from')),
            $gateway === 'log' => new LogGateway($c->bound(LoggerInterface::class) ? $c->make(LoggerInterface::class) : new NullLogger()),
            $gateway === 'array' => $c->make(ArrayGateway::class),
            is_subclass_of($gateway, SmsGateway::class) => $c->make($gateway),
            default => throw new NotificationException(sprintf('Unknown SMS gateway "%s"; use msg91, twilio, log, array or a class implementing %s.', $gateway, SmsGateway::class)),
        };
    }
}
