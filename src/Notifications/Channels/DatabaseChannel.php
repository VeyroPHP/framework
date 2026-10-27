<?php

declare(strict_types=1);

namespace VeyroPHP\Notifications\Channels;

use VeyroPHP\Notifications\DatabaseNotifications;
use VeyroPHP\Notifications\Notification;
use VeyroPHP\Orm\Model;

/**
 * Stores toDatabase() for in-app notification lists ("You have 3 new notifications").
 */
final class DatabaseChannel implements Channel
{
    public function __construct(private readonly DatabaseNotifications $notifications) {}

    public function send(Model $notifiable, Notification $notification): void
    {
        $this->notifications->store($notifiable, $notification::class, $notification->toDatabase($notifiable));
    }
}
