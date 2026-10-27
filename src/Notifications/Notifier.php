<?php

declare(strict_types=1);

namespace VeyroPHP\Notifications;

use Closure;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use VeyroPHP\Notifications\Channels\Channel;
use VeyroPHP\Notifications\Events\NotificationFailed;
use VeyroPHP\Notifications\Events\NotificationSent;
use VeyroPHP\Orm\Model;
use VeyroPHP\Queue\Queue;
use VeyroPHP\Queue\ShouldQueue;

/**
 * Sends notifications through their channels.
 *
 *     $notifier->send($user, new OrderShipped(42));
 *     $notifier->send([$asha, $ravi], new MaintenanceTonight());
 *
 * - Notifications implementing ShouldQueue are queued (one job per user) when
 *   a queue is registered, otherwise sent at once.
 * - Every channel is tried even if an earlier one fails; failures are logged,
 *   dispatch NotificationFailed, and are reported together afterwards.
 *   A queued notification is then retried, so channels that had succeeded
 *   may deliver twice: keep channels idempotent where that matters.
 * - A failing NotificationSent listener is logged; the channel still counts as
 *   delivered. A failing NotificationFailed listener is logged too; the remaining
 *   channels still run, and the channel failure is still reported.
 */
final class Notifier
{
    /** @var array<string, Channel> */
    private array $resolved = [];

    /**
     * @param Closure(string): Channel $channels creates a channel by name
     * @param (Closure(): Queue)|null $queue
     */
    public function __construct(
        private readonly Closure $channels,
        private readonly ?Closure $queue = null,
        private readonly ?EventDispatcherInterface $events = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * @param Model|iterable<Model> $notifiables
     */
    public function send(Model|iterable $notifiables, Notification $notification): void
    {
        if ($notification instanceof ShouldQueue && $this->queue !== null) {
            $queue = ($this->queue)();
            $packed = $queue->payload()->pack($notification);

            foreach (self::list($notifiables) as $notifiable) {
                $queue->push(new SendQueuedNotification($notifiable, $packed));
            }

            return;
        }

        $this->sendNow($notifiables, $notification);
    }

    /**
     * Sends without queueing.
     *
     * @param Model|iterable<Model> $notifiables
     * @param list<string>|null $channels only these channels (default: via())
     *
     * @throws NotificationException after all channels were tried, when any failed
     */
    public function sendNow(Model|iterable $notifiables, Notification $notification, ?array $channels = null): void
    {
        $failures = [];

        foreach (self::list($notifiables) as $notifiable) {
            foreach ($channels ?? $notification->via($notifiable) as $name) {
                try {
                    $this->channel($name)->send($notifiable, $notification);
                } catch (\Throwable $e) {
                    $failures[] = $e;
                    $this->logger->error('Notification failed', [
                        'notification' => $notification::class,
                        'channel' => $name,
                        'notifiable' => $notifiable::class . '#' . (string) $notifiable->getKey(),
                        'error' => $e::class . ': ' . $e->getMessage(),
                    ]);

                    // A failing listener must not skip the remaining channels; the channel
                    // failure itself is still reported by the exception below (SVC-2).
                    try {
                        $this->events?->dispatch(new NotificationFailed($notifiable, $notification, $name, $e));
                    } catch (\Throwable $listenerError) {
                        $this->logger->error('A NotificationFailed listener failed', [
                            'notification' => $notification::class,
                            'channel' => $name,
                            'notifiable' => $notifiable::class . '#' . (string) $notifiable->getKey(),
                            'error' => $listenerError::class . ': ' . $listenerError->getMessage(),
                        ]);
                    }

                    continue;
                }

                // Delivered: a failing NotificationSent listener is reported, not counted as a
                // channel failure, so a queued notification is not retried and sent again.
                try {
                    $this->events?->dispatch(new NotificationSent($notifiable, $notification, $name));
                } catch (\Throwable $e) {
                    $this->logger->error('A NotificationSent listener failed after delivery', [
                        'notification' => $notification::class,
                        'channel' => $name,
                        'notifiable' => $notifiable::class . '#' . (string) $notifiable->getKey(),
                        'error' => $e::class . ': ' . $e->getMessage(),
                    ]);
                }
            }
        }

        if ($failures !== []) {
            throw new NotificationException(
                sprintf('%d notification channel(s) failed for %s; first error: %s', count($failures), $notification::class, $failures[0]->getMessage()),
                0,
                $failures[0],
            );
        }
    }

    private function channel(string $name): Channel
    {
        return $this->resolved[$name] ??= ($this->channels)($name);
    }

    /**
     * @param Model|iterable<Model> $notifiables
     *
     * @return list<Model>
     */
    private static function list(Model|iterable $notifiables): array
    {
        if ($notifiables instanceof Model) {
            return [$notifiables];
        }

        $list = [];

        foreach ($notifiables as $notifiable) {
            $list[] = $notifiable;
        }

        return $list;
    }
}
