<?php

declare(strict_types=1);

namespace VeyroPHP\Notifications;

use VeyroPHP\Orm\Model;
use VeyroPHP\Queue\Job;
use VeyroPHP\Queue\Payload;

/**
 * Sends a ShouldQueue notification to one notifiable from a queue worker.
 * The notifiable is stored by key and loaded fresh.
 *
 * @internal created by Notifier
 */
final class SendQueuedNotification implements Job
{
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120, 600];

    /**
     * @param array<mixed> $notification packed notification (Payload::pack)
     */
    public function __construct(
        public readonly Model $notifiable,
        public readonly array $notification,
    ) {}

    public function handle(Notifier $notifier, Payload $payload): void
    {
        $notification = $payload->unpack($this->notification, Notification::class);
        \assert($notification instanceof Notification);

        $notifier->sendNow($this->notifiable, $notification);
    }
}
