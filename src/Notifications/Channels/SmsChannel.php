<?php

declare(strict_types=1);

namespace VeyroPHP\Notifications\Channels;

use VeyroPHP\Notifications\Notification;
use VeyroPHP\Notifications\NotificationException;
use VeyroPHP\Notifications\Sms\SmsGateway;
use VeyroPHP\Notifications\Sms\SmsMessage;
use VeyroPHP\Orm\Model;

/**
 * Sends the notification's toSms(Model): SmsMessage through the configured gateway.
 * The number comes from the message (SmsMessage::to()), else routeNotificationForSms() if the model has it, else its
 * "phone" column.
 *
 * Numbers are sent in E.164 form (+919876543210). Spaces, dashes, dots and brackets
 * are removed. A number without "+" gets $defaultCountryCode ("91") in front when one
 * is configured, unless it is longer than 10 digits and already starts with that code;
 * without a configured code it is refused rather than guessed.
 */
final class SmsChannel implements Channel
{
    public function __construct(
        private readonly SmsGateway $gateway,
        private readonly ?string $defaultCountryCode = null,
    ) {
        if ($defaultCountryCode !== null && preg_match('/^[1-9]\d{0,3}$/D', $defaultCountryCode) !== 1) {
            throw new NotificationException('The default SMS country code must be 1 to 4 digits without "+", e.g. 91.');
        }
    }

    public function send(Model $notifiable, Notification $notification): void
    {
        // Not a method of Notification: applications may already have their own toSms().
        $message = method_exists($notification, 'toSms') ? $notification->toSms($notifiable) : null;

        if (!$message instanceof SmsMessage) {
            throw new NotificationException(sprintf('%s uses the sms channel: add toSms(Model $notifiable): %s.', $notification::class, SmsMessage::class));
        }

        $number = $message->getTo() ?? self::address($notifiable);

        $this->gateway->send($message, $this->normalize($number, $notifiable));
    }

    private function normalize(string $number, Model $notifiable): string
    {
        $number = (string) preg_replace('/[\s().-]+/', '', $number);

        if (!str_starts_with($number, '+') && $this->defaultCountryCode !== null) {
            $number = ltrim($number, '0');
            // "919876543210" already has the country code; "9876543210" gets it.
            $hasCode = strlen($number) > 10 && str_starts_with($number, $this->defaultCountryCode);
            $number = '+' . ($hasCode ? '' : $this->defaultCountryCode) . $number;
        }

        if (preg_match('/^\+[1-9]\d{7,14}$/D', $number) !== 1) {
            throw new NotificationException(sprintf('%s has no valid phone number for the SMS channel (use +<country code><number>).', $notifiable::class));
        }

        return $number;
    }

    private static function address(Model $notifiable): string
    {
        $number = method_exists($notifiable, 'routeNotificationForSms')
            ? $notifiable->routeNotificationForSms()
            : $notifiable->raw('phone');

        // A number kept in an integer column is accepted as its digits.
        $number = is_int($number) && $number > 0 ? (string) $number : $number;

        return is_string($number) && $number !== ''
            ? $number
            : throw new NotificationException(sprintf('%s has no phone number for the SMS channel.', $notifiable::class));
    }
}
