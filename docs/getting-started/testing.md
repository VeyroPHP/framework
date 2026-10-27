# Testing

The starter app's `tests/Feature` show the usual setup: a fresh application per test with an in-memory SQLite database, the array cache, the array mailer and the sync queue, all forced in `phpunit.xml`. Run them with `composer test`.

## Requests: `TestClient`

```php
use VeyroPHP\Http\HttpKernel;
use VeyroPHP\Testing\TestClient;

$client = new TestClient($app->container()->make(HttpKernel::class));

$client->get('/login');                                            // keeps cookies, remembers the form
$response = $client->post('/login', ['email' => 'a@b.test', 'password' => '...'], withToken: true);
$page = $client->follow($response);                                // GET the redirect target
```

`withToken: true` adds the `_token` field from the last page's form, as a browser would. Pass `['REMOTE_ADDR' => '203.0.113.9']` as the second constructor argument to test per-address limits.

## Time: `TestClock`

There is no global time switch. Classes that depend on time take a clock closure, and tests pass one from a `TestClock`:

```php
use VeyroPHP\Cache\ArrayStore;
use VeyroPHP\Cache\Cache;
use VeyroPHP\Security\RateLimiter;
use VeyroPHP\Testing\TestClock;

$clock = new TestClock('2026-10-27 10:00:00', 'Asia/Kolkata');
$cache = new Cache(new ArrayStore($clock->timestamps()), 'app', null, 10, 50, $clock->timestamps());
$limiter = new RateLimiter($cache, $clock->timestamps());

$clock->travel(61);          // seconds, or a relative time such as '+1 day'
$clock->set('2027-01-01');
```

`timestamps()` gives Unix time (for `RateLimiter`, `Cache`, `ArrayStore`, `MaintenanceMode`); `dates()` gives `DateTimeImmutable` (for `FileLogger`).

## Events: `EventFake`

```php
use VeyroPHP\Testing\EventFake;

$events = EventFake::install($app->container());       // before the code under test is created
// ... run the code ...
$events->assertDispatched(OrderPlaced::class, fn (OrderPlaced $e) => $e->id === 7);
$events->assertDispatchedTimes(OrderPlaced::class, 1);
$events->assertNotDispatched(OrderCancelled::class);
```

- Listeners do not run. Name event classes in `passThrough:` to let them run anyway.
- Services that were already created keep the dispatcher they got, so install the fake first.

## Database: `RollsBackTransactions`

For tests against MySQL or PostgreSQL, where re-running migrations per test is slow:

```php
use VeyroPHP\Database\Connection\Connection;
use VeyroPHP\Testing\RollsBackTransactions;

final class OrderTest extends TestCase
{
    use RollsBackTransactions;

    protected function connectionsToRollBack(): array
    {
        return [$this->app->container()->make(Connection::class)];
    }
}
```

Each test runs in a transaction that is rolled back afterwards. Transactions inside the code under test become savepoints.

- MySQL and MariaDB commit implicitly on `CREATE`, `ALTER` and `DROP TABLE`: run migrations once before the tests, not inside them.
- Other processes and connections do not see the test's rows.

## Mail and queues

The starter forces the `array` mailer: read sent messages with `$app->container()->make(Mailer::class)->transport()` (`messages()`, `last()`). The `sync` queue runs jobs at once. To test real SMTP behaviour without a network, the starter's `ContactMailDeliveryTest` starts a local SMTP sink (`tests/Fixtures/smtp-sink.php`).

## Packages

Package tests use `TestApplication`; see [creating packages](../packages/creating-packages.md).
