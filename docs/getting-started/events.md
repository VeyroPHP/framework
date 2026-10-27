# Events

Events let one part of the application react to something that happened in another part without the two knowing each other. For example, registration sends `UserRegistered`, and separate listeners send a welcome mail and record statistics. Design: [ADR 0020](../architecture/adr/0020-events.md).

`VeyroPHP\Events\EventDispatcher` implements the PSR-14 standard (`Psr\EventDispatcher\EventDispatcherInterface`), so libraries written for PSR-14 can use it.

## Setup

Register `VeyroPHP\Events\EventServiceProvider` (the starter app does). Create an event and a listener:

```bash
php veyro make:event UserRegistered
php veyro make:listener SendWelcomeMail --event=UserRegistered
```

An event is any object, usually with public read-only properties:

```php
final class UserRegistered
{
    public function __construct(public readonly User $user) {}
}
```

A listener is a class with `handle()` (or `__invoke()`). Its constructor dependencies are injected by the container:

```php
final class SendWelcomeMail
{
    public function __construct(private readonly LoggerInterface $logger) {}

    public function handle(UserRegistered $event): void
    {
        // ...
    }
}
```

List listeners in `config/events.php`:

```php
return [
    'listeners' => [
        UserRegistered::class => [SendWelcomeMail::class, RecordSignup::class],
    ],
];
```

or in a service provider's `boot()`:

```php
$events = $app->container()->make(EventDispatcher::class);
$events->listen(UserRegistered::class, SendWelcomeMail::class);
$events->listen(UserRegistered::class, static function (UserRegistered $event): void { /* ... */ });
```

## Sending events

Inject the dispatcher and call `dispatch()`. It returns the event object:

```php
public function register(EventDispatcherInterface $events /* , ... */): Response
{
    // ... create $user ...
    $events->dispatch(new UserRegistered($user));
}
```

## Rules

- Listeners run **immediately and in registration order**, in the same request. For slow work (sending mail, calling other services), let the listener implement `VeyroPHP\Queue\ShouldQueue`: it then runs in a [queue worker](queues.md#queued-event-listeners-and-mail), and the event must follow the job rules (JSON values, enums or models in promoted constructor parameters).
- A listener registered for a class also receives its subclasses; one registered for an interface receives every event implementing it.
- If a listener throws, later listeners do not run and the exception reaches the code that called `dispatch()`. Catch it inside the listener if the work is optional.
- An event implementing `Psr\EventDispatcher\StoppableEventInterface` stops further listeners once `isPropagationStopped()` returns true.
- Listener classes are created when their event is dispatched, not at registration, and a new instance is created each time, so they can safely depend on per-request services such as `Auth`.
- Mistakes are reported when listeners are registered: an unknown event class, a missing listener class or a listener without `handle()`/`__invoke()` throws `EventException`.

## Built-in events

With `EventServiceProvider` registered, `Auth` sends:

| Event | When | Properties |
| --- | --- | --- |
| `VeyroPHP\Auth\Events\Login` | after `attempt()` succeeds or `login()` | `user` |
| `VeyroPHP\Auth\Events\LoginFailed` | `attempt()` rejected the credentials | `identifier` (what was typed, usually an email), `user` (null if no account matched); never the password |
| `VeyroPHP\Auth\Events\Logout` | `logout()` | `user` (null if nobody was logged in) |

Example: record the last login time.

```php
final class RecordLastLogin
{
    public function __construct(private readonly Orm $orm) {}

    public function handle(Login $event): void
    {
        $event->user->set('last_login_at', date('Y-m-d H:i:s'));
        $this->orm->save($event->user);
    }
}
```

`LoginFailed` contains personal data (the identifier); do not write it to logs that many people can read.

## Testing

Register a closure to collect events:

```php
$seen = [];
$events->listen(UserRegistered::class, static function (UserRegistered $e) use (&$seen): void { $seen[] = $e; });

// ... run the code under test ...

// $seen now holds every UserRegistered event that was sent; assert on it.
```
