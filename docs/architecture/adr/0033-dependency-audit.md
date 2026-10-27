# ADR 0033: Dependency security audit

- Status: Accepted
- Date: 2026-10-26

## Context

The owner asked for an audit of existing third-party PHP packages:

- `composer audit` and OSV integration, CI checks, JSON reports and a policy
- clean and vulnerable test projects that give distinguishable, reproducible results
- never call a package safe
- never silently ignore a failed scan
- never run package installation scripts just to inspect code

This ADR covers that first milestone. Scheduled rescans with notifications, static inspection and license policy come later.

## Decisions

- **Only `composer.lock` is read.** Nothing is installed or autoloaded, and the scanned project's code is never run.
  - `LockFile` parses the JSON and records its SHA-256, so a report is tied to one lock file.
- **Two independent sources behind `AuditSource`:**
  - `ComposerAuditSource` runs `composer audit --locked --format=json --no-plugins --no-scripts --no-interaction`:
    - as an argument list (no shell), with stdin closed and a timeout
    - output goes to temporary files and the process is polled, because pipe reads block on Windows
    - Composer is run as a PHP script (`PHP_BINARY composer.phar`), because Windows cannot start `composer.bat` without a shell
    - `--no-plugins` matters: without it, Composer asked (in testing) to enable `php-http/discovery`'s plugin and to write `allow-plugins` into `composer.json`
  - `OsvSource` uses OSV.dev's `querybatch` (up to 1000 packages per request, following page tokens), then `vulns/{id}` for details:
    - withdrawn advisories are skipped
    - branch versions are reported as not checked
    - it uses a bound PSR-18 client, otherwise PHP's HTTP stream wrapper, so it needs no new dependency
- **Failures are explicit:**
  - Sources return a failed `SourceResult` instead of throwing, and the `Auditor` turns an exception into a failed result.
  - Composer exits 1 both for "vulnerable" and for errors (a missing directory also gives 1), so only valid JSON with an `advisories` key counts, and exit codes above 3 fail.
  - A report with any failed source is `complete: false`. Without findings, its status is `incomplete`, never "no known vulnerabilities".
- **Merging:**
  - The same advisory from both sources is merged, keeping the union of identifiers and sources and the higher severity.
  - Matching uses the other report's own id first, then shared aliases, because OSV aliases related advisories to each other (`GHSA-f2wf-25xc-69c9` and `GHSA-w248-ffj2-4v5q`), and alias-only matching merged two different advisories.
- **Policy is separate from the report** (`AuditPolicy`, from config `audit`):
  - minimum failing severity; unknown severity always fails
  - abandoned packages are optional to fail, always reported
  - source errors fail by default
  - ignore rules need a reason and can expire
- **Exit codes:** 0 passed, 1 findings fail, 2 usage, 3 incomplete. Findings win over incomplete.
- **Wording:**
  - Statuses are `vulnerabilities_found`, `incomplete` and `no_known_vulnerabilities`.
  - Every report and table carries the notice that an empty result does not mean the dependencies are safe.
  - A test asserts that "safe" appears only in that notice.
- **Staleness:** `--check` validates a saved report without network access:
  - schema
  - lock SHA-256
  - completeness
  - age (`--max-age`, default 7 days)
- **CI:**
  - The starter gets `.github/workflows/security.yml` (push, pull request, daily; uploads the JSON report).
  - The framework gets a daily workflow that runs `composer audit` and the live audit test.
  - The starter's `.gitignore` no longer excludes `composer.lock`; it stays excluded from the template archive.

## Verified

- **Fixtures** in `tests/Fixtures/Security` (lock files only, never installed):
  - `clean`: psr/log 3.0.2
  - `vulnerable`: guzzlehttp/guzzle 7.4.0 and the abandoned swiftmailer/swiftmailer v6.3.0
  - The real `composer audit` output for `vulnerable` is recorded in `composer-audit.json`.
- **Real run, 2026-10-26:**
  - clean: both sources ok, 0 advisories, exit 0
  - vulnerable: composer 14 and OSV 14 advisories, merged into 14 (6 high, 8 medium), swiftmailer abandoned, exit 1
  - The live test (`VEYROPHP_TEST_NETWORK=1`) repeats this against the real services.
- **The starter application itself:** 39 packages, both sources ok, no known advisories, and `veyrophp/core` (0.1.x-dev, path repository) listed as not checked by OSV.
- **Tests (26):**
  - lock parsing
  - Composer command line (no plugins, scripts or interaction; `--no-dev`)
  - Composer failures: error text, timeout, unexpected JSON, unexpected exit code
  - a real process failure and a real timeout that stops the process within the limit
  - OSV queries, details, withdrawn and branch versions
  - OSV failures: HTTP 500, network error, missing details
  - merging, including the mutual-alias case
  - statuses and the no-"safe" wording
  - policy: thresholds, unknown severity, ignore with reason and expiry, abandoned, incomplete
  - staleness
  - the command: clean vs vulnerable, a reproducible JSON report, `--output`, failed source and exit 3, config policy, `--check`, usage errors, registration

## Addendum (2026-10-26): scheduled rescans and notifications

- **`security:audit --notify`:**
  - It compares the new report with the previous one at `audit.report` (`AuditDiff`).
  - It notifies only when the result changed: a new advisory, a source that started failing, a newly abandoned package, or a resolved advisory.
  - It notifies by plain-text email (`AuditMail` to `audit.notify.mail`) and by the PSR-14 event `AuditChanged`, rather than through the Model-based Notifications module, because the recipients are operators, not user records.
- **Rules:**
  - Resolved advisories are only counted after a complete scan.
  - A failing source is reported once.
  - A failed email fails the run.
  - With `--format=json`, notification messages go to stderr so stdout stays valid JSON (the first version printed them after the JSON).
- **Matching:**
  - The first diff matched advisories by any shared alias. Against the real services, a second identical run reported "1 resolved", because the two Guzzle advisories OSV aliases to each other both matched the same old entry.
  - Matching now uses own ids first, and each old entry is matched once. A regression test fails on the old code.
- **The starter:**
  - It schedules `security:audit --notify --no-dev` daily at 04:00.
  - Recipients come from `AUDIT_NOTIFY_MAIL`, and `storage/security/` is git-ignored.
- **Verified in the starter:**
  - Real sources and the log mailer, three runs on the vulnerable fixture.
  - Run 1 sent one email ("14 new advisories, 1 newly abandoned package").
  - Runs 2 and 3 sent nothing ("No changes").
  - `schedule:list` shows the task.

## Not done
- static inspection of package code for suspicious patterns
- license policy
- an isolated sandbox for inspecting unknown packages
- a GitHub dependency-review action (it needs a GitHub repository)
- The CI workflows are written but have not run on GitHub yet, because the repositories are not published.
