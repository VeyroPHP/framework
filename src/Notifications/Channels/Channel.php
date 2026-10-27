<?php

declare(strict_types=1);

namespace VeyroPHP\Notifications\Channels;

use VeyroPHP\Notifications\Notification;
use VeyroPHP\Orm\Model;

/**
 * Delivers notifications one way (mail, database, SMS, WhatsApp, Slack...).
 *
 * A custom channel usually calls a method of the notification, for example
 * $notification->toSms($notifiable), and reads the address from the model.
 */
interface Channel
{
    public function send(Model $notifiable, Notification $notification): void;
}
