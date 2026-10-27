# ADR 0030: Notifications

- Status: Accepted
- Date: 2026-10-26

## Context

Laravel parity item 11. Laravel's notifications send one message through several channels (mail, database, broadcast, Slack, SMS through packages). VeyroPHP now has mail, queues and events to build on.

## Decisions

- **`Notification` base class** with `via()`, `toMail()` and `toDatabase()`; custom channels use their own methods (`toSms()`). Missing methods give a clear error naming the notification and channel.
- **`Notifier` is a service** (`send`, `sendNow` with a channel list). There is no `Notifiable` trait and no `$user->notify()`, in line with the explicit ORM (ADR 0014).
- **Channels are classes:**
  - `mail` and `database` are built in; custom channels are named in config and created by the container.
  - Mail addresses come from `routeNotificationForMail()` or the `email` column.
- **Database notifications:**
  - ULID ids; data as JSON (UTF-8 kept).
  - `notifiable_type` + `notifiable_id` with an index, so one table serves several models.
  - Every read and write method takes the owner, so ids cannot be used across users.
- **Failure isolation:** all channels are tried and failures are collected (events and logs), then one exception follows. Queued notifications retry as a whole, which the guide states plainly, including possible duplicates.
- **Queued notifications** are one `SendQueuedNotification` job per user. The user is stored by key (Payload), so a deleted user makes the job fail at once (`ModelMissingException`) instead of mailing a deleted account.
- Not done:
  - broadcast/websocket channel
  - per-user channel preferences UI
  - notification digests
  - built-in SMS providers (they differ per country; the guide shows a custom channel)

## Verified

- `NotificationsTest` (6 tests):
  - mail and database to two users, with events
  - Hindi data stored intact
  - read state per owner (another user's id rejected), mark all, delete, prune
  - a Mailable and a custom mail route
  - a custom SMS channel
  - a failing channel not stopping the database and mail channels, with the event and exception
  - configuration errors
  - queued notifications, one job per user, with a deleted user's job failing without retries
- `make:notification` output is loaded and used in `DeveloperCommandsTest`.
- A starter feature test shows a notification on the dashboard (escaped) and marks it read.
