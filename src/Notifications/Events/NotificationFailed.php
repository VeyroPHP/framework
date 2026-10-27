<?php

declare(strict_types=1);

namespace VeyroPHP\Notifications\Events;

use VeyroPHP\Notifications\Notification;
use VeyroPHP\Orm\Model;

/**
 * A channel could not deliver a notification. Sent after the failure was logged; the
 * other channels still run. If a listener throws, that is logged too and does not
 * replace the NotificationException that Notifier::sendNow() throws afterwards.
 */
final class NotificationFailed
{
    public function __construct(
        public readonly Model $notifiable,
        public readonly Notification $notification,
        public readonly string $channel,
        public readonly \Throwable $error,
    ) {}
}
