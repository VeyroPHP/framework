# Notifications

Tell users about events (an order shipped, a payment failed) through one or more **channels**: mail, an in-app list (the database) or your own channels such as SMS or WhatsApp. Design: [ADR 0030](../architecture/adr/0030-notifications.md).

## Setup

Register `VeyroPHP\Notifications\NotificationServiceProvider` (the starter app does). For in-app notifications, add a migration with `DatabaseNotifications::up($schema)`; the starter app has it.

## Writing a notification

```bash
php veyro make:notification OrderShipped
```

```php
final class OrderShipped extends Notification
{
    public function __construct(public readonly int $orderId) {}

    public function via(Model $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(Model $notifiable): Message
    {
        return (new Message())->subject('Your order shipped')->text("Order {$this->orderId} is on its way.");
    }

    public function toDatabase(Model $notifiable): array
    {
        return ['order_id' => $this->orderId, 'text' => 'Your order shipped'];
    }
}
```

Send it:

```php
$notifier->send($user, new OrderShipped(42));
$notifier->send([$asha, $ravi], new MaintenanceTonight());
$notifier->sendNow($user, new OrderShipped(42), ['database']);   // only some channels, never queued
```

- `via()` can depend on the user, for example their settings.
- **Mail:** `toMail()` may return a `Message` without recipients, which then goes to `routeNotificationForMail()` (if the model has it) or the `email` column. It may also return a `Mailable`.
- **Queued sending:** add `implements VeyroPHP\Queue\ShouldQueue`, and the notification is sent from the [queue](queues.md), one job per user. The constructor then follows the job rules, and users are loaded fresh by key; a deleted user's job fails at once.

## In-app notifications

```php
$notifications->unread($user, 10);       // newest first: list of DatabaseNotification (id, type, data, readAt, createdAt)
$notifications->unreadCount($user);
$notifications->markAsRead($user, $id);  // only the user's own notifications
$notifications->markAllAsRead($user);
$notifications->delete($user, $id);
$notifications->pruneRead(90);           // delete read ones older than 90 days, for example from the scheduler
```

Ids are ULIDs. Every method takes the user, so one user's id cannot touch another user's notifications. The starter dashboard lists unread notifications and has a "Mark all as read" button.

## SMS

The built-in `sms` channel sends through MSG91 (India) or Twilio:

```php
// config/notifications.php
'sms' => [
    'gateway' => 'msg91',                      // msg91, twilio, log (default), array (tests), or your SmsGateway class
    'key' => $env->string('MSG91_AUTH_KEY', ''),
    // twilio: 'sid' => ..., 'token' => ..., 'from' => '+1...' or a Messaging Service SID 'MG...'
    'default_country_code' => '91',            // for numbers stored without "+"
],
```

```php
final class LoginOtp extends Notification
{
    public function __construct(private readonly string $otp) {}

    public function via(Model $notifiable): array
    {
        return ['sms'];
    }

    public function toSms(Model $notifiable): SmsMessage
    {
        // Indian SMS (DLT) must use a registered template; MSG91 sends the template, not the text.
        return (new SmsMessage("Your login OTP is {$this->otp}"))->template('6512ab...', ['otp' => $this->otp]);
    }
}
```

- **Number:**
  - `SmsMessage::to()`, else `routeNotificationForSms()` on the model, else its `phone` column.
  - It is sent as E.164 (`+919876543210`). With `default_country_code`, `98765 43210`, `09876543210` and `919876543210` all become `+919876543210`.
  - Without it, a number without `+` is refused rather than guessed.
- **Gateways:**
  - MSG91 needs a template id (DLT rules).
  - Twilio sends the text.
  - `log` writes the SMS to the log, useful while developing.
  - `msg91` and `twilio` need `guzzlehttp/guzzle` or a bound PSR-18 client.
- **Errors:** a refusal throws `NotificationException`, with the HTTP status as its code.
- **Not yet verified:** the gateways have not been tried against real accounts yet.

## Custom channels (WhatsApp, Slack, another SMS provider)

For another SMS provider, implementing `VeyroPHP\Notifications\Sms\SmsGateway` is enough. A channel configured under `channels` (below) replaces a built-in one of the same name.

```php
final class SmsChannel implements Channel
{
    public function __construct(private readonly ClientInterface $http, private readonly Config $config) {}

    public function send(Model $notifiable, Notification $notification): void
    {
        $phone = $notifiable->raw('phone');
        if (!is_string($phone) || !method_exists($notification, 'toSms')) {
            return;
        }

        // Call your SMS provider's HTTP API here; throw on failure so it is reported.
    }
}
```

```php
// config/notifications.php
return ['channels' => ['sms' => App\Notifications\SmsChannel::class]];
```

Then return `'sms'` from `via()` and add a `toSms()` method to the notification. Channels are created by the container, so they can ask for an HTTP client or config.

## Failures

- Every channel is tried, even when an earlier one fails.
- A failure is logged and dispatches `NotificationFailed`. Afterwards `send()` throws a `NotificationException` naming how many channels failed. A `NotificationFailed` listener that throws is logged as well; it does not stop the remaining channels or replace that exception.
- A queued notification is then retried, and channels that had already succeeded run again, so a user may get the same mail twice. Make channels idempotent where that matters.
- Every successful delivery dispatches `NotificationSent`.
