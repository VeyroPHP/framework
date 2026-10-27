<?php

declare(strict_types=1);

namespace VeyroPHP\Notifications;

/**
 * A stored notification.
 */
final class DatabaseNotification
{
    /**
     * @param array<string, mixed> $data what toDatabase() returned
     */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly array $data,
        public readonly ?string $readAt,
        public readonly string $createdAt,
    ) {}

    public function isRead(): bool
    {
        return $this->readAt !== null;
    }
}
