<?php

declare(strict_types=1);

namespace VeyroPHP\Notifications\Events;

use VeyroPHP\Notifications\Notification;
use VeyroPHP\Orm\Model;

final class NotificationSent
{
    public function __construct(
        public readonly Model $notifiable,
        public readonly Notification $notification,
        public readonly string $channel,
    ) {}
}
