<?php

declare(strict_types=1);

namespace VeyroPHP\Notifications\Channels;

use VeyroPHP\Mail\Mailable;
use VeyroPHP\Mail\Mailer;
use VeyroPHP\Mail\Message;
use VeyroPHP\Notifications\Notification;
use VeyroPHP\Notifications\NotificationException;
use VeyroPHP\Orm\Model;

/**
 * Sends toMail(). A Message without recipients goes to the notifiable's
 * address: routeNotificationForMail() if the model has it, else its "email" column.
 */
final class MailChannel implements Channel
{
    public function __construct(private readonly Mailer $mailer) {}

    public function send(Model $notifiable, Notification $notification): void
    {
        $mail = $notification->toMail($notifiable);

        if ($mail instanceof Mailable) {
            $this->mailer->send($mail);

            return;
        }

        if ($mail->recipients() === []) {
            $mail->to(self::address($notifiable));
        }

        $this->mailer->send($mail);
    }

    private static function address(Model $notifiable): string
    {
        $address = method_exists($notifiable, 'routeNotificationForMail')
            ? $notifiable->routeNotificationForMail()
            : $notifiable->raw('email');

        return is_string($address) && $address !== ''
            ? $address
            : throw new NotificationException(sprintf('%s has no email address for the mail channel.', $notifiable::class));
    }
}
