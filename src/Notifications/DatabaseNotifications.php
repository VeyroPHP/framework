<?php

declare(strict_types=1);

namespace VeyroPHP\Notifications;

use VeyroPHP\Database\Connection\Connection;
use VeyroPHP\Database\Schema\Blueprint;
use VeyroPHP\Database\Schema\Schema;
use VeyroPHP\Orm\Model;
use VeyroPHP\Support\Ulid;

/**
 * Stored (in-app) notifications of a user.
 *
 *     $notifications->unread($user);          // newest first
 *     $notifications->unreadCount($user);
 *     $notifications->markAsRead($user, $id); // only the user's own
 *
 * Ids are ULIDs, so they can appear in URLs without revealing counts.
 */
final class DatabaseNotifications
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $table = 'notifications',
    ) {}

    /**
     * Creates the table; call from a migration (and down() from its down()).
     */
    public static function up(Schema $schema, string $table = 'notifications'): void
    {
        $schema->create($table, static function (Blueprint $t): void {
            $t->string('id', 26)->unique();
            $t->string('notifiable_type', 191);
            $t->string('notifiable_id', 64);
            $t->string('type', 191);
            $t->text('data');
            $t->dateTime('read_at')->nullable();
            $t->dateTime('created_at');
            $t->index(['notifiable_type', 'notifiable_id', 'read_at']);
        });
    }

    public static function down(Schema $schema, string $table = 'notifications'): void
    {
        $schema->dropIfExists($table);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function store(Model $notifiable, string $type, array $data): string
    {
        $id = Ulid::generate();

        $this->connection->table($this->table)->insert([
            'id' => $id,
            'notifiable_type' => $notifiable::class,
            'notifiable_id' => (string) ($notifiable->getKey() ?? throw new NotificationException('Only saved models can receive notifications.')),
            'type' => $type,
            'data' => json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'read_at' => null,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);

        return $id;
    }

    /**
     * @return list<DatabaseNotification> newest first
     */
    public function all(Model $notifiable, int $limit = 50): array
    {
        return $this->fetch($notifiable, false, $limit);
    }

    /**
     * @return list<DatabaseNotification> newest first
     */
    public function unread(Model $notifiable, int $limit = 50): array
    {
        return $this->fetch($notifiable, true, $limit);
    }

    public function unreadCount(Model $notifiable): int
    {
        return $this->owned($notifiable)->whereNull('read_at')->count();
    }

    /**
     * @return bool false when the id is not one of this notifiable's notifications
     */
    public function markAsRead(Model $notifiable, string $id): bool
    {
        return $this->owned($notifiable)->where('id', $id)->whereNull('read_at')->update(['read_at' => gmdate('Y-m-d H:i:s')]) === 1
            || $this->owned($notifiable)->where('id', $id)->exists();
    }

    public function markAllAsRead(Model $notifiable): int
    {
        return $this->owned($notifiable)->whereNull('read_at')->update(['read_at' => gmdate('Y-m-d H:i:s')]);
    }

    public function delete(Model $notifiable, string $id): bool
    {
        return $this->owned($notifiable)->where('id', $id)->delete() === 1;
    }

    /**
     * Deletes read notifications older than $days days; returns how many.
     */
    public function pruneRead(int $days = 90): int
    {
        return $this->connection->table($this->table)
            ->whereNotNull('read_at')
            ->where('created_at', '<', gmdate('Y-m-d H:i:s', time() - $days * 86400))
            ->delete();
    }

    private function owned(Model $notifiable): \VeyroPHP\Database\Query\Builder
    {
        return $this->connection->table($this->table)
            ->where('notifiable_type', $notifiable::class)
            ->where('notifiable_id', (string) $notifiable->getKey());
    }

    /**
     * @return list<DatabaseNotification>
     */
    private function fetch(Model $notifiable, bool $unreadOnly, int $limit): array
    {
        $query = $this->owned($notifiable)->orderByDesc('created_at')->orderByDesc('id')->limit(max(1, $limit));

        if ($unreadOnly) {
            $query->whereNull('read_at');
        }

        return array_map(static function (array $row): DatabaseNotification {
            $data = json_decode(is_string($row['data'] ?? null) ? $row['data'] : '[]', true);
            $text = static fn(string $key): string => is_scalar($row[$key] ?? null) ? (string) $row[$key] : '';

            /** @var array<string, mixed> $data */
            $data = is_array($data) ? $data : [];

            return new DatabaseNotification($text('id'), $text('type'), $data, is_string($row['read_at'] ?? null) ? $row['read_at'] : null, $text('created_at'));
        }, $query->get());
    }
}
