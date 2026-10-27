# Auditing dependencies

`php veyro security:audit` checks the package versions in `composer.lock` against published security advisories. Design: [ADR 0033](../architecture/adr/0033-dependency-audit.md).

```bash
php veyro security:audit                       # table, policy from config/audit.php
php veyro security:audit --format=json         # machine-readable report
php veyro security:audit --output=build/security-audit.json
php veyro security:audit --no-dev              # production dependencies only
php veyro security:audit --sources=composer    # one source only
php veyro security:audit --path=../other-app   # another project's composer.lock
```

## What it checks

| Source | Database | How |
| --- | --- | --- |
| `composer` | Packagist's advisories (GitHub Security Advisories, FriendsOfPHP) | Runs `composer audit --locked --format=json` |
| `osv` | [OSV.dev](https://osv.dev) (ecosystem Packagist) | Asks the OSV API for each locked version, then each advisory's details |

- **Merged results:** an advisory reported by both sources is shown once, with both sources, all identifiers (PKSA, CVE, GHSA) and the higher severity.
- **Abandoned packages** are listed, with the replacement the maintainer suggests.

## What it never does

- **It runs no package code.** Only `composer.lock` is read: nothing is installed or autoloaded. Composer is started with `--no-plugins --no-scripts --no-interaction`, without a shell, with stdin closed and a time limit (`audit.timeout`, default 120 seconds). Without these flags, a plain `composer audit` can ask to enable a package's plugin and then write `allow-plugins` into `composer.json`.
- **It never treats a failed check as a clean result.** Composer exits with 1 both for "vulnerable" and for many errors, so only valid JSON output with the expected fields counts. A source that times out, cannot be reached or answers with something unexpected is reported as failed, and the scan is then **incomplete**.
- **It never calls your dependencies safe.** The best result is "no known vulnerabilities found in the sources that ran", and every report says that this does not mean the dependencies are safe. Advisories exist only for problems someone found and published.

## Result and exit codes

| Status (`status` in JSON) | Meaning |
| --- | --- |
| `vulnerabilities_found` | At least one source reported an advisory for a locked version |
| `incomplete` | Nothing reported, but at least one source failed: the result cannot be trusted |
| `no_known_vulnerabilities` | Every source ran, and none matched |

| Exit code | Meaning |
| --- | --- |
| 0 | Passed the policy |
| 1 | Findings fail the policy |
| 2 | Usage error (no lock file, unknown source, invalid config) |
| 3 | No failing findings, but the scan was incomplete |

**Branch versions** (`dev-main`, `1.x-dev`, path repositories) cannot be matched by OSV, because they are not releases. They appear as "not checked" instead of being silently skipped.

## Policy: `config/audit.php`

```php
return [
    'sources' => ['composer', 'osv'],
    'fail_on' => 'low',              // lowest failing severity: low, medium, high, critical
    'fail_on_abandoned' => false,    // abandoned packages are always reported
    'fail_on_source_error' => true,  // an incomplete scan fails (exit 3)
    'ignore' => [
        ['id' => 'CVE-2026-0001', 'reason' => 'Only affects the X driver, which we do not use', 'until' => '2026-12-31'],
    ],
    'report' => 'storage/security/audit.json', // kept for --notify
    'notify' => ['mail' => ['ops@example.com']],
    'composer' => null,              // path to composer.phar when it is not on PATH
    'timeout' => 120,
];
```

- **Advisories without a severity always fail**, because their impact is unknown.
- **`ignore` matches any identifier** (PKSA, CVE or GHSA).
  - Every entry needs a reason.
  - `until` (optional) ends the exception after that day; expired rules are reported.
  - Review ignores regularly: fixing a vulnerability means updating the package, and an ignore only records that you accepted the risk.
- **Finding Composer:** the composer source runs Composer as a PHP script, in this order: `audit.composer`, `$COMPOSER_BINARY`, then `composer.phar` or a `composer` PHP script on `PATH`. Wrapper scripts such as `composer.bat` are skipped because they would need a shell.
- **The OSV HTTP client:** the osv source uses the application's PSR-18 client when one is bound as `Psr\Http\Client\ClientInterface`, and PHP's HTTP stream wrapper otherwise.

## Saved reports and staleness

A report is a snapshot. New advisories are published every day for versions you already use, and the report only describes the lock file it was made from.

```bash
php veyro security:audit --check=build/security-audit.json --max-age=7
```

`--check` makes no network calls. It fails (exit 1) when the saved report:

- was made from a different `composer.lock` (it compares the SHA-256)
- is older than `--max-age` days
- came from an incomplete scan
- has an unknown format

Use it, for example, as a deploy gate after a scheduled audit.

## Scheduled rescans and notifications

New advisories are published for versions you already run, so audit regularly, not only when dependencies change. The starter schedules it in `routes/schedule.php`:

```php
$schedule->command('security:audit', ['--notify', '--no-dev'])->dailyAt('04:00')->withoutOverlapping(60);
```

With `--notify`, the command:

1. reads the previous report from `audit.report` (default `storage/security/audit.json`, or `--output`);
2. runs the audit and saves the new report there;
3. compares the two (`AuditDiff`) and, **only if something changed**:
   - sends one plain-text email to `audit.notify.mail` (`AUDIT_NOTIFY_MAIL=ops@example.com,dev@example.com`);
   - dispatches `VeyroPHP\Security\Audit\Events\AuditChanged` (report, policy result and diff), so the application can also post to chat or notify admin users.

| A change is | Notes |
| --- | --- |
| A new advisory | Not in the previous report, for the same package. Matched by id first, then by a shared CVE or GHSA, so a version bump that is still affected is not "new". |
| A source that started failing | Reported once, not every day while it keeps failing |
| A newly abandoned package | |
| A resolved advisory | Listed in the previous report and gone now. Only counted after a **complete** scan, because a failed source may simply have missed it. |

The same findings on the next day send nothing, so people are not trained to ignore the email. The command's exit code still reflects the policy, so a scheduled run with findings is also logged as a failed task. If the email cannot be sent, the run fails even when the audit passed, because a silent notification failure would look like "all clear".

```php
// For example, in a service provider's boot():
$events->listen(AuditChanged::class, function (AuditChanged $e) use ($chat): void {
    $chat->post('#security', 'Dependency audit: ' . $e->diff->summary());
});
```

## In CI

The starter application includes `.github/workflows/security.yml`. It runs the audit on every push and pull request and daily, and keeps the JSON report as a build artifact:

```yaml
- run: composer install --no-interaction --no-progress --no-plugins --no-scripts
- run: php veyro security:audit --output=build/security-audit.json
```

Commit `composer.lock` (the starter's `.gitignore` no longer excludes it). Without a lock file, there is nothing reproducible to audit.

## JSON report (schema 1)

```json
{
    "schema": 1,
    "generated_at": "2026-10-26T19:05:00+05:30",
    "status": "vulnerabilities_found",
    "complete": true,
    "notice": "This report lists published advisories that matched the locked versions when it was generated. An empty result does not mean the dependencies are safe.",
    "lock": { "path": "...", "sha256": "...", "packages": 2, "include_dev": true },
    "sources": [
        { "name": "composer", "status": "ok", "error": null, "advisories": 14, "not_checked": [] },
        { "name": "osv", "status": "ok", "error": null, "advisories": 14, "not_checked": [] }
    ],
    "advisories": [
        {
            "package": "guzzlehttp/guzzle", "version": "7.4.0",
            "id": "PKSA-gcrk-3vtt-1r14", "aliases": ["CVE-2026-69246", "GHSA-v5mv-p594-2x33"],
            "title": "Guzzle: Noncanonical host can bypass host-based checks", "severity": "high",
            "link": "https://github.com/advisories/GHSA-v5mv-p594-2x33",
            "affected_versions": ">=8.0.0,<8.0.1|<7.15.2", "sources": ["composer", "osv"]
        }
    ],
    "abandoned": { "swiftmailer/swiftmailer": "symfony/mailer" },
    "policy": { "passed": false, "failures": ["..."], "incomplete": false, "ignored": [], "expired_ignores": [] }
}
```

## Not covered (yet)

- **Malicious code:** no advisory exists until someone reports it. The audit cannot tell whether a package is malicious.
- **Static inspection of package code:** suspicious patterns, `eval`, network calls on boot.
- **Licenses:** use `composer licenses`.
