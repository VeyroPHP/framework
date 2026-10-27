<?php

declare(strict_types=1);

namespace VeyroPHP\Notifications;

use VeyroPHP\Mail\Mailable;
use VeyroPHP\Mail\Message;
use VeyroPHP\Orm\Model;

/**
 * Something to tell a user, through one or more channels.
 *
 *     final class OrderShipped extends Notification
 *     {
 *         public function __construct(public readonly int $orderId) {}
 *
 *         public function via(Model $notifiable): array
 *         {
 *             return ['mail', 'database'];
 *         }
 *
 *         public function toMail(Model $notifiable): Message
 *         {
 *             return (new Message())->subject('Your order shipped')->text('Order ' . $this->orderId . ' is on its way.');
 *         }
 *
 *         public function toDatabase(Model $notifiable): array
 *         {
 *             return ['order_id' => $this->orderId, 'text' => 'Your order shipped'];
 *         }
 *     }
 *
 *     $notifier->send($user, new OrderShipped(42));
 *
 * Implement VeyroPHP\Queue\ShouldQueue to send it from a queue worker; the
 * constructor then follows the job rules (JSON values, enums or models).
 * The "sms" channel calls toSms(Model $notifiable): SmsMessage; custom channels
 * call their own method.
 */
abstract class Notification
{
    /**
     * Channel names: "mail", "database", "sms" or any configured custom channel.
     *
     * @return list<string>
     */
    abstract public function via(Model $notifiable): array;

    /**
     * For the "mail" channel. A Message without to() is sent to the notifiable's address.
     */
    public function toMail(Model $notifiable): Message|Mailable
    {
        throw new NotificationException(sprintf('%s uses the mail channel but has no toMail() method.', static::class));
    }

    /**
     * For the "database" channel: the data stored with the notification (JSON).
     *
     * @return array<string, mixed>
     */
    public function toDatabase(Model $notifiable): array
    {
        throw new NotificationException(sprintf('%s uses the database channel but has no toDatabase() method.', static::class));
    }
}
