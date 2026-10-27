<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Integration\Notifications;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use VeyroPHP\Application\Application;
use VeyroPHP\Config\Config;
use VeyroPHP\Database\Connection\Connection;
use VeyroPHP\Database\DatabaseServiceProvider;
use VeyroPHP\Database\Schema\Blueprint;
use VeyroPHP\Database\Schema\Schema;
use VeyroPHP\Events\EventDispatcher;
use VeyroPHP\Events\EventServiceProvider;
use VeyroPHP\Mail\Mailable;
use VeyroPHP\Mail\Mailer;
use VeyroPHP\Mail\MailServiceProvider;
use VeyroPHP\Mail\Message;
use VeyroPHP\Mail\Transport\ArrayTransport;
use VeyroPHP\Notifications\Channels\Channel;
use VeyroPHP\Notifications\Channels\DatabaseChannel;
use VeyroPHP\Notifications\Channels\MailChannel;
use VeyroPHP\Notifications\DatabaseNotification;
use VeyroPHP\Notifications\DatabaseNotifications;
use VeyroPHP\Notifications\Events\NotificationFailed;
use VeyroPHP\Notifications\Events\NotificationSent;
use VeyroPHP\Notifications\Notification;
use VeyroPHP\Notifications\NotificationException;
use VeyroPHP\Notifications\NotificationServiceProvider;
use VeyroPHP\Notifications\Notifier;
use VeyroPHP\Notifications\SendQueuedNotification;
use VeyroPHP\Orm\Model;
use VeyroPHP\Orm\Orm;
use VeyroPHP\Orm\OrmServiceProvider;
use VeyroPHP\Queue\Driver\ArrayDriver;
use VeyroPHP\Queue\Queue;
use VeyroPHP\Queue\QueueServiceProvider;
use VeyroPHP\Queue\ShouldQueue;
use VeyroPHP\Queue\Worker;
use VeyroPHP\Tests\Fixtures\Auth\AuthUser;
use VeyroPHP\Tests\Fixtures\Logging\ArrayLogger;

class OrderShipped extends Notification
{
    /**
     * @param list<string> $channels
     */
    public function __construct(public readonly int $orderId, public readonly array $channels = ['mail', 'database']) {}

    public function via(Model $notifiable): array
    {
        return $this->channels;
    }

    public function toMail(Model $notifiable): Message
    {
        return (new Message())->subject('Order ' . $this->orderId . ' shipped')->text('On its way.');
    }

    public function toDatabase(Model $notifiable): array
    {
        return ['order_id' => $this->orderId, 'text' => 'आपका ऑर्डर निकल गया'];
    }

    public function toSms(Model $notifiable): string
    {
        return 'Order ' . $this->orderId . ' shipped';
    }
}

final class QueuedOrderShipped extends OrderShipped implements ShouldQueue {}

final class ReceiptMail extends Mailable
{
    public function build(Message $message): void
    {
        $message->to('accounts@example.com')->subject('Receipt')->text('x');
    }
}

final class ReceiptNotification extends Notification
{
    public function via(Model $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(Model $notifiable): Mailable
    {
        return new ReceiptMail();
    }
}

final class SmsChannel implements Channel
{
    /** @var list<string> */
    public static array $sent = [];

    public function send(Model $notifiable, Notification $notification): void
    {
        if (!$notification instanceof OrderShipped) {
            throw new NotificationException('SMS only for orders');
        }

        $phone = $notifiable->raw('phone');
        self::$sent[] = (is_string($phone) ? $phone : '?') . ': ' . $notification->toSms($notifiable);
    }
}

final class BrokenChannel implements Channel
{
    public function send(Model $notifiable, Notification $notification): void
    {
        throw new \RuntimeException('SMS gateway down');
    }
}

final class NotifiableWithRoute extends Model
{
    protected const TABLE = 'users';

    public function routeNotificationForMail(): string
    {
        return 'billing@example.com';
    }
}

#[CoversClass(Notifier::class)]
#[CoversClass(Notification::class)]
#[CoversClass(MailChannel::class)]
#[CoversClass(DatabaseChannel::class)]
#[CoversClass(DatabaseNotifications::class)]
#[CoversClass(DatabaseNotification::class)]
#[CoversClass(SendQueuedNotification::class)]
#[CoversClass(NotificationServiceProvider::class)]
#[CoversClass(NotificationException::class)]
#[CoversClass(NotificationSent::class)]
#[CoversClass(NotificationFailed::class)]
final class NotificationsTest extends TestCase
{
    private Application $app;

    protected function setUp(): void
    {
        SmsChannel::$sent = [];
        $this->app = new Application(sys_get_temp_dir());
        $this->app->container()->instance(Config::class, new Config([
            'database' => ['default' => 'memory', 'connections' => ['memory' => ['driver' => 'sqlite', 'database' => ':memory:']]],
            'mail' => ['default' => 'array', 'from' => ['address' => 'shop@example.com'], 'mailers' => ['array' => ['transport' => 'array']]],
            'queue' => ['default' => 'array', 'connections' => ['array' => ['driver' => 'array']]],
            'notifications' => ['channels' => ['sms' => SmsChannel::class, 'broken' => BrokenChannel::class, 'wrong' => \stdClass::class]],
        ]));
        foreach ([new DatabaseServiceProvider(), new OrmServiceProvider(), new EventServiceProvider(), new MailServiceProvider(), new QueueServiceProvider(), new NotificationServiceProvider()] as $provider) {
            $this->app->register($provider);
        }
        $this->app->boot();

        $schema = $this->app->container()->make(Schema::class);
        $schema->create('users', static function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->string('email');
            $t->string('phone')->nullable();
            $t->string('password')->nullable();
            $t->timestamps();
        });
        DatabaseNotifications::up($schema);
    }

    private function user(string $email, ?string $phone = null): AuthUser
    {
        $user = (new AuthUser())->forceFill(['name' => 'U', 'email' => $email, 'phone' => $phone]);
        $this->app->container()->make(Orm::class)->save($user);

        return $user;
    }

    private function notifier(): Notifier
    {
        return $this->app->container()->make(Notifier::class);
    }

    private function mails(): ArrayTransport
    {
        $transport = $this->app->container()->make(Mailer::class)->transport();
        self::assertInstanceOf(ArrayTransport::class, $transport);

        return $transport;
    }

    private function inbox(): DatabaseNotifications
    {
        return $this->app->container()->make(DatabaseNotifications::class);
    }

    public function testMailAndDatabaseChannels(): void
    {
        $asha = $this->user('asha@example.com');
        $ravi = $this->user('ravi@example.com');
        $sent = [];
        $this->app->container()->make(EventDispatcher::class)->listen(NotificationSent::class, static function (NotificationSent $e) use (&$sent): void {
            $sent[] = $e->channel;
        });

        $this->notifier()->send([$asha, $ravi], new OrderShipped(42));

        self::assertCount(2, $this->mails()->messages());
        self::assertSame(['asha@example.com'], $this->mails()->messages()[0]->recipients, 'Message without to() goes to the email column');
        self::assertSame('Order 42 shipped', $this->mails()->messages()[1]->message->getSubject());
        self::assertSame(['mail', 'database', 'mail', 'database'], $sent);

        $notifications = $this->inbox()->unread($asha);
        self::assertCount(1, $notifications);
        self::assertSame(OrderShipped::class, $notifications[0]->type);
        self::assertSame(['order_id' => 42, 'text' => 'आपका ऑर्डर निकल गया'], $notifications[0]->data);
        self::assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $notifications[0]->id, 'a ULID');
        self::assertFalse($notifications[0]->isRead());
    }

    public function testReadStateBelongsToItsOwner(): void
    {
        $asha = $this->user('asha@example.com');
        $ravi = $this->user('ravi@example.com');
        $this->notifier()->send($asha, new OrderShipped(1, ['database']));
        $this->notifier()->send($asha, new OrderShipped(2, ['database']));
        $this->notifier()->send($ravi, new OrderShipped(3, ['database']));
        $ashas = $this->inbox()->all($asha);

        self::assertSame(2, $this->inbox()->unreadCount($asha));
        self::assertFalse($this->inbox()->markAsRead($ravi, $ashas[0]->id), "Ravi cannot mark Asha's notification");
        self::assertFalse($this->inbox()->delete($ravi, $ashas[0]->id));
        self::assertTrue($this->inbox()->markAsRead($asha, $ashas[0]->id));
        self::assertSame(1, $this->inbox()->unreadCount($asha));
        self::assertTrue($this->inbox()->all($asha)[0]->isRead() || $this->inbox()->all($asha)[1]->isRead());
        self::assertSame(1, $this->inbox()->markAllAsRead($asha));
        self::assertSame(0, $this->inbox()->unreadCount($asha));
        self::assertSame(1, $this->inbox()->unreadCount($ravi));
        self::assertSame(0, $this->inbox()->pruneRead(90), 'read today: kept');
        self::assertTrue($this->inbox()->delete($asha, $ashas[1]->id));
        self::assertCount(1, $this->inbox()->all($asha));
    }

    public function testMailableAndCustomMailRoute(): void
    {
        $this->notifier()->send($this->user('asha@example.com'), new ReceiptNotification());
        self::assertSame(['accounts@example.com'], $this->mails()->last()?->recipients);

        $this->user('x@example.com');
        $routed = $this->app->container()->make(Orm::class)->find(NotifiableWithRoute::class, 2);
        self::assertNotNull($routed);
        $this->notifier()->send($routed, new OrderShipped(5, ['mail']));
        self::assertSame(['billing@example.com'], $this->mails()->last()?->recipients);
    }

    public function testCustomChannelAndFailureIsolation(): void
    {
        $user = $this->user('asha@example.com', '+919812345678');
        $failed = [];
        $this->app->container()->make(EventDispatcher::class)->listen(NotificationFailed::class, static function (NotificationFailed $e) use (&$failed): void {
            $failed[] = $e->channel . ': ' . $e->error->getMessage();
        });

        $this->notifier()->send($user, new OrderShipped(7, ['sms']));
        self::assertSame(['+919812345678: Order 7 shipped'], SmsChannel::$sent);

        try {
            $this->notifier()->send($user, new OrderShipped(8, ['broken', 'database', 'mail']));
            self::fail('expected NotificationException');
        } catch (NotificationException $e) {
            self::assertStringContainsString('1 notification channel(s) failed', $e->getMessage());
            self::assertStringContainsString('SMS gateway down', $e->getMessage());
        }

        self::assertSame(['broken: SMS gateway down'], $failed);
        self::assertSame(1, $this->inbox()->unreadCount($user), 'the database channel still ran');
        self::assertCount(1, $this->mails()->messages(), 'and the mail channel');
    }

    /**
     * VQ-044: a channel that delivered is not counted as failed because a
     * NotificationSent listener threw, so a queued notification is not sent again.
     */
    public function testAListenerFailureAfterDeliveryIsNotAChannelFailure(): void
    {
        $user = $this->user('asha@example.com');
        $failed = [];
        $events = $this->app->container()->make(EventDispatcher::class);
        $events->listen(NotificationSent::class, static function (): void {
            throw new \RuntimeException('listener down');
        });
        $events->listen(NotificationFailed::class, static function (NotificationFailed $e) use (&$failed): void {
            $failed[] = $e->channel;
        });

        $this->notifier()->send($user, new OrderShipped(5, ['database', 'mail']));
        self::assertSame([], $failed, 'no channel failed');
        self::assertCount(1, $this->mails()->messages());
        self::assertSame(1, $this->inbox()->unreadCount($user));

        // Queued: the worker must not retry and deliver again.
        $this->notifier()->send($user, new QueuedOrderShipped(6));
        $worker = $this->app->container()->make(Worker::class);
        for ($i = 0; $i < 3; $i++) {
            $worker->runNextJob('array', ['default']);
        }
        self::assertCount(2, $this->mails()->messages(), 'the queued notification was delivered once');
        self::assertSame([], $failed);
    }

    /**
     * SVC-2: a throwing NotificationFailed listener must not skip the remaining
     * channels or the error log, and the caller still gets one NotificationException.
     */
    public function testAFailingNotificationFailedListenerDoesNotStopTheOtherChannels(): void
    {
        $user = $this->user('asha@example.com', '+919812345678');
        $events = $this->app->container()->make(EventDispatcher::class);
        $events->listen(NotificationFailed::class, static function (): never {
            throw new \LogicException('alerting is down');
        });
        $logger = new ArrayLogger();
        $notifier = new Notifier(
            static fn(string $name): Channel => $name === 'broken' ? new BrokenChannel() : new SmsChannel(),
            events: $events,
            logger: $logger,
        );

        try {
            $notifier->sendNow($user, new OrderShipped(9), ['broken', 'sms', 'broken']);
            self::fail('expected NotificationException');
        } catch (NotificationException $e) {
            self::assertStringContainsString('2 notification channel(s) failed', $e->getMessage());
            self::assertSame('SMS gateway down', $e->getPrevious()?->getMessage(), 'the channel error, not the listener error');
        }

        self::assertSame(['+919812345678: Order 9 shipped'], SmsChannel::$sent, 'the channel after the failure still ran');
        $messages = array_column($logger->records, 'message');
        self::assertSame(2, count(array_keys($messages, 'Notification failed', true)), 'both channel failures were logged');
        self::assertSame(2, count(array_keys($messages, 'A NotificationFailed listener failed', true)));
    }

    /**
     * SVC-2, queued: the channel failure still fails the job, so the queue retries it;
     * the failing listener changes nothing about that.
     */
    public function testAQueuedNotificationIsStillRetriedWhenTheFailedListenerThrows(): void
    {
        $user = $this->user('asha@example.com');
        $this->app->container()->make(EventDispatcher::class)->listen(NotificationFailed::class, static function (): never {
            throw new \LogicException('alerting is down');
        });

        $this->notifier()->send($user, new QueuedOrderShipped(11, ['broken', 'database']));
        $this->app->container()->make(Worker::class)->runNextJob('array', ['default']);

        self::assertSame(1, $this->inbox()->unreadCount($user), 'the working channel delivered');
        self::assertSame(1, $this->app->container()->make(Queue::class)->driver('array')->size('default'), 'the job is back on the queue for a retry');
    }

    public function testConfigurationErrors(): void
    {
        $user = $this->user('asha@example.com');

        foreach ([['fax', 'Unknown notification channel "fax"'], ['wrong', 'must implement']] as [$channel, $error]) {
            try {
                $this->notifier()->sendNow($user, new OrderShipped(1), [$channel]);
                self::fail('expected NotificationException');
            } catch (NotificationException $e) {
                self::assertStringContainsString($error, $e->getMessage());
            }
        }

        $noMail = new class extends Notification {
            public function via(Model $notifiable): array
            {
                return ['mail'];
            }
        };

        $this->expectException(NotificationException::class);
        $this->expectExceptionMessage('has no toMail() method');
        $this->notifier()->send($user, $noMail);
    }

    public function testQueuedNotificationsRunInTheWorker(): void
    {
        $asha = $this->user('asha@example.com');
        $ravi = $this->user('ravi@example.com');

        $this->notifier()->send([$asha, $ravi], new QueuedOrderShipped(9));

        self::assertSame([], $this->mails()->messages(), 'nothing sent during the request');
        $driver = $this->app->container()->make(Queue::class)->driver();
        self::assertInstanceOf(ArrayDriver::class, $driver);
        self::assertCount(2, $driver->jobs(SendQueuedNotification::class), 'one job per user');

        // Ravi deletes his account before the worker runs.
        $this->app->container()->make(Connection::class)->table('users')->where('id', $ravi->getKey())->delete();
        $worker = $this->app->container()->make(Worker::class);
        $worker->runNextJob('array', ['default']);
        $worker->runNextJob('array', ['default']);

        self::assertSame(['asha@example.com'], $this->mails()->last()?->recipients);
        self::assertCount(1, $this->mails()->messages());
        self::assertSame(1, $this->inbox()->unreadCount($asha));
        self::assertSame(0, $driver->size('default'), "Ravi's job failed at once (model missing) instead of retrying");
    }
}
