# ADR 0038: Maintenance mode, health checks and testing helpers

- Status: Accepted
- Date: 2026-10-27

## Context

Before 0.1.0 the framework had no way to take a site down for a deploy, no endpoint for load balancers or uptime monitors, and no helpers for time and events in tests. Other frameworks were read to see which problems these features solve; the design below starts from what VeyroPHP already has.

## Decisions

### Maintenance mode

- One JSON file, `storage/maintenance.json`, is the switch (`php veyro down` / `up`). Every PHP process sees it at once, without a cache or a restart, and nothing in `public/` changes.
- The 503 is built by `BlockDuringMaintenance`, not thrown. Thrown 5xx errors are logged, and a long downtime would write one log entry per request.
- **Bypass secret:** `down --secret` prints a link once; the file stores only the SHA-256 of the secret, and each `down` makes a new one. Opening `/_maintenance/<secret>` sets an HttpOnly cookie and redirects away at once, so the secret does not stay in the address bar or `Referer`. Not chosen: a secret path segment that stays in every URL, or a secret stored in plain text.
- **Allow list:** IP addresses and CIDR ranges, matched like trusted proxies (the client address comes from `ClientIp`, so a forged `X-Forwarded-For` does not help).
- An unreadable switch file counts as down: it must not reopen the site during a migration.
- `/up` stays reachable. Queue workers and the scheduler are not paused: they are separate processes, and pausing them silently would hide work that piles up. The deployment guide says when to stop them.

### Health checks

- `HealthCheck` holds named checks (closures that throw to fail). The HTTP provider adds `database`, `cache` and `storage` when those services exist.
- The web endpoint answers only `ok`/`fail` per check. Error messages go to the log and to `php veyro health`, because they can contain host names or credentials.
- During maintenance `/up` still answers 200 with `"maintenance": true`. A 503 would make load balancers remove every server, and the site would stay unreachable after `up` until they re-check.

### Testing helpers

- `TestClock`: no global time override. The framework's time-dependent classes already take clock closures, and the test passes the clock's `timestamps()` or `dates()`.
- `EventFake` replaces the PSR-14 dispatcher in the container and records events; chosen event classes can still reach their listeners. Assertions use PHPUnit when it is installed and `AssertionError` otherwise.
- `RollsBackTransactions`: each test runs in a transaction that is rolled back. Faster than re-migrating per test on MySQL and PostgreSQL; the limits (implicit commits on MySQL DDL, other connections not seeing the rows) are documented.

## Consequences

- Three new commands (`down`, `up`, `health`) and one new global middleware in the starter.
- Nothing changes for applications that do not add `BlockDuringMaintenance` or the `/up` route.
