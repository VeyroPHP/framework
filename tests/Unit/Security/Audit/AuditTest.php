<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Unit\Security\Audit;

use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use VeyroPHP\Security\Audit\Advisory;
use VeyroPHP\Security\Audit\AuditException;
use VeyroPHP\Security\Audit\Auditor;
use VeyroPHP\Security\Audit\AuditPolicy;
use VeyroPHP\Security\Audit\AuditReport;
use VeyroPHP\Security\Audit\AuditSource;
use VeyroPHP\Security\Audit\ComposerAuditSource;
use VeyroPHP\Security\Audit\LockFile;
use VeyroPHP\Security\Audit\OsvSource;
use VeyroPHP\Security\Audit\Severity;
use VeyroPHP\Security\Audit\SourceResult;

/**
 * A fake OSV.dev: answers querybatch from a package => ids map and vulns/{id}
 * from a details map. Records every request.
 */
final class FakeOsv implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    public ?int $status = null;

    public bool $offline = false;

    /**
     * @param array<string, list<string>> $ids package name => advisory ids
     * @param array<string, array<string, mixed>> $vulns id => OSV record
     */
    public function __construct(private readonly array $ids, private readonly array $vulns) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        if ($this->offline) {
            throw new class ('connection refused') extends \RuntimeException implements NetworkExceptionInterface {
                public function getRequest(): RequestInterface
                {
                    throw new \LogicException('not used');
                }
            };
        }

        if ($this->status !== null) {
            return new Response($this->status, [], 'error');
        }

        $path = $request->getUri()->getPath();

        if ($path === '/v1/querybatch') {
            $body = json_decode((string) $request->getBody(), true);
            \assert(is_array($body) && is_array($body['queries']));
            $results = [];

            foreach ($body['queries'] as $query) {
                \assert(is_array($query) && is_array($query['package']) && is_string($query['package']['name']));
                $ids = $this->ids[$query['package']['name']] ?? [];
                $results[] = $ids === [] ? new \stdClass() : ['vulns' => array_map(static fn(string $id): array => ['id' => $id], $ids)];
            }

            return new Response(200, [], (string) json_encode(['results' => $results]));
        }

        $id = rawurldecode(substr($path, strlen('/v1/vulns/')));

        return isset($this->vulns[$id]) ? new Response(200, [], (string) json_encode($this->vulns[$id])) : new Response(404, [], '{}');
    }
}

final class AuditTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../../Fixtures/Security';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/veyrophp-audit-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    /**
     * @param list<array<string, mixed>> $packages
     * @param list<array<string, mixed>> $dev
     */
    private function lock(array $packages, array $dev = []): LockFile
    {
        file_put_contents($this->dir . '/composer.lock', (string) json_encode(['packages' => $packages, 'packages-dev' => $dev]));

        return LockFile::read($this->dir . '/composer.lock');
    }

    private static function vulnerableLock(): LockFile
    {
        return LockFile::read(self::FIXTURES . '/vulnerable/composer.lock');
    }

    /**
     * @param list<string> $aliases
     */
    private static function advisory(string $id, array $aliases = [], Severity $severity = Severity::High, string $source = 'test', string $package = 'acme/lib'): Advisory
    {
        return new Advisory($package, '1.0.0', $id, $aliases, 'Title of ' . $id, $severity, null, null, [$source]);
    }

    private static function source(string $name, SourceResult|\Throwable $result): AuditSource
    {
        return new class ($name, $result) implements AuditSource {
            public function __construct(private readonly string $name, private readonly SourceResult|\Throwable $result) {}

            public function name(): string
            {
                return $this->name;
            }

            public function scan(LockFile $lock, bool $includeDev): SourceResult
            {
                if ($this->result instanceof \Throwable) {
                    throw $this->result;
                }

                return $this->result;
            }
        };
    }

    private static function report(SourceResult ...$results): AuditReport
    {
        $sources = array_map(static fn(SourceResult $r): AuditSource => self::source($r->source, $r), array_values($results));

        return (new Auditor($sources))->audit(self::vulnerableLock(), true, new \DateTimeImmutable('2026-10-26 12:00:00'));
    }

    // Lock file

    public function testLockFileReadsPackagesWithoutRunningAnything(): void
    {
        $lock = $this->lock(
            [['name' => 'b/two', 'version' => 'v2.0.0', 'abandoned' => 'c/three'], ['name' => 'a/one', 'version' => '1.0.0', 'abandoned' => true]],
            [['name' => 'd/dev', 'version' => '3.0.0']],
        );

        self::assertSame(['a/one', 'b/two', 'd/dev'], array_column($lock->packages(), 'name'));
        self::assertSame(['a/one', 'b/two'], array_column($lock->packages(false), 'name'));
        self::assertSame(['', 'c/three', null], array_column($lock->packages(), 'abandoned'));
        self::assertSame('v2.0.0', $lock->version('b/two'));
        self::assertSame(hash_file('sha256', $this->dir . '/composer.lock'), $lock->sha256);
    }

    public function testMissingOrInvalidLockFileIsAnError(): void
    {
        try {
            LockFile::read($this->dir . '/composer.lock');
            self::fail('A missing lock file must be reported.');
        } catch (AuditException $e) {
            self::assertStringContainsString('No lock file', $e->getMessage());
        }

        file_put_contents($this->dir . '/composer.lock', '{"not": "a lock"}');
        $this->expectException(AuditException::class);
        LockFile::read($this->dir . '/composer.lock');
    }

    // composer audit

    public function testComposerAuditRunsWithoutPluginsScriptsOrShellAndParsesTheReport(): void
    {
        $calls = [];
        $recorded = (string) file_get_contents(self::FIXTURES . '/vulnerable/composer-audit.json');
        $source = new ComposerAuditSource('/opt/composer.phar', 60, static function (array $command, string $cwd, int $timeout) use (&$calls, $recorded): array {
            $calls[] = [$command, $cwd, $timeout];

            return [3, $recorded, ''];
        });

        $lock = self::vulnerableLock();
        $result = $source->scan($lock, false);

        self::assertTrue($result->succeeded());
        self::assertCount(1, $calls);
        [$command, $cwd, $timeout] = $calls[0];
        self::assertSame([PHP_BINARY, '/opt/composer.phar', 'audit', '--locked', '--format=json', '--no-plugins', '--no-scripts', '--no-interaction', '--working-dir=' . $lock->directory(), '--no-dev'], $command);
        self::assertSame($lock->directory(), $cwd);
        self::assertSame(60, $timeout);

        self::assertCount(14, $result->advisories);
        self::assertSame(['swiftmailer/swiftmailer' => 'symfony/mailer'], $result->abandoned);

        $first = $result->advisories[0];
        self::assertSame('guzzlehttp/guzzle', $first->package);
        self::assertSame('7.4.0', $first->version);
        self::assertSame('PKSA-gcrk-3vtt-1r14', $first->id);
        self::assertSame(['CVE-2026-69246', 'GHSA-v5mv-p594-2x33'], $first->aliases);
        self::assertSame(Severity::High, $first->severity);
        self::assertSame(['composer'], $first->sources);
    }

    public function testComposerFailuresAreNeverReadAsClean(): void
    {
        $runs = [
            'error text' => [1, '', "In AuditCommand.php line 1:\n  No composer.lock found\n"],
            'timeout' => [-1, '', 'timed out after 60 seconds'],
            'json without advisories' => [0, '{"something": "else"}', ''],
            'unexpected exit code' => [255, '{"advisories": [], "abandoned": []}', 'crashed'],
        ];

        foreach ($runs as $case => $run) {
            $result = (new ComposerAuditSource('composer.phar', 60, static fn(): array => $run))->scan(self::vulnerableLock(), true);

            self::assertFalse($result->succeeded(), $case);
            self::assertSame([], $result->advisories, $case);
            self::assertStringContainsString('composer audit failed', (string) $result->error, $case);
        }

        $result = (new ComposerAuditSource('composer.phar', 60, static fn(): array => $runs['error text']))->scan(self::vulnerableLock(), true);
        self::assertSame('composer audit failed (exit 1): No composer.lock found', $result->error);
    }

    public function testRealProcessFailureIsReported(): void
    {
        // Runs this PHP binary on a missing script: PHP exits 1 without JSON.
        $result = (new ComposerAuditSource($this->dir . '/missing-composer.phar', 30))->scan(self::vulnerableLock(), true);

        self::assertFalse($result->succeeded());
        self::assertStringStartsWith('composer audit failed (exit 1)', (string) $result->error);
    }

    public function testRealProcessTimeoutStopsTheProcess(): void
    {
        file_put_contents($this->dir . '/slow.php', '<?php sleep(30); echo "{\"advisories\": []}";');
        $started = microtime(true);
        $result = (new ComposerAuditSource($this->dir . '/slow.php', 1))->scan(self::vulnerableLock(), true);

        self::assertLessThan(10, microtime(true) - $started);
        self::assertSame('composer audit failed (exit -1): timed out after 1 seconds', $result->error);
    }

    // OSV

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function osvRecords(): array
    {
        return [
            'GHSA-w248-ffj2-4v5q' => ['id' => 'GHSA-w248-ffj2-4v5q', 'aliases' => ['CVE-2022-31043', 'GHSA-f2wf-25xc-69c9'], 'summary' => 'Fix failure to strip Authorization header on HTTP downgrade', 'database_specific' => ['severity' => 'HIGH']],
            'GHSA-f2wf-25xc-69c9' => ['id' => 'GHSA-f2wf-25xc-69c9', 'aliases' => ['CVE-2022-31042', 'GHSA-w248-ffj2-4v5q'], 'summary' => 'Failure to strip the Cookie header on change in host', 'database_specific' => ['severity' => 'HIGH']],
            'GHSA-old' => ['id' => 'GHSA-old', 'withdrawn' => '2024-01-01T00:00:00Z', 'summary' => 'Withdrawn'],
            'GHSA-noseverity' => ['id' => 'GHSA-noseverity', 'details' => "First line of details\nMore"],
        ];
    }

    public function testOsvQueriesLockedVersionsAndReadsDetails(): void
    {
        $client = new FakeOsv(['guzzlehttp/guzzle' => ['GHSA-w248-ffj2-4v5q', 'GHSA-f2wf-25xc-69c9', 'GHSA-old'], 'swiftmailer/swiftmailer' => ['GHSA-noseverity']], self::osvRecords());
        $result = (new OsvSource($client, 'https://osv.test'))->scan(self::vulnerableLock(), true);

        self::assertTrue($result->succeeded(), (string) $result->error);
        self::assertSame(['GHSA-f2wf-25xc-69c9', 'GHSA-noseverity', 'GHSA-w248-ffj2-4v5q'], array_map(static fn(Advisory $a): string => $a->id, $result->advisories));

        $batch = json_decode((string) $client->requests[0]->getBody(), true);
        self::assertSame(['queries' => [
            ['package' => ['ecosystem' => 'Packagist', 'name' => 'guzzlehttp/guzzle'], 'version' => '7.4.0'],
            ['package' => ['ecosystem' => 'Packagist', 'name' => 'swiftmailer/swiftmailer'], 'version' => '6.3.0'],
        ]], $batch, 'The leading "v" is removed from versions.');
        self::assertSame('https://osv.test/v1/querybatch', (string) $client->requests[0]->getUri());

        $noSeverity = $result->advisories[1];
        self::assertSame('swiftmailer/swiftmailer', $noSeverity->package);
        self::assertSame('v6.3.0', $noSeverity->version);
        self::assertSame(Severity::Unknown, $noSeverity->severity);
        self::assertSame('First line of details', $noSeverity->title);
        self::assertSame('https://osv.dev/vulnerability/GHSA-noseverity', $noSeverity->link);
        self::assertSame(Severity::High, $result->advisories[0]->severity);
    }

    public function testOsvSkipsBranchVersionsAndSaysSo(): void
    {
        $lock = $this->lock([['name' => 'a/stable', 'version' => '1.0.0'], ['name' => 'a/branch', 'version' => 'dev-main'], ['name' => 'a/alias', 'version' => '2.x-dev']]);
        $client = new FakeOsv([], []);
        $result = (new OsvSource($client))->scan($lock, true);

        self::assertTrue($result->succeeded());
        self::assertSame(['a/alias (2.x-dev: branch version)', 'a/branch (dev-main: branch version)'], $result->notChecked);
        $batch = json_decode((string) $client->requests[0]->getBody(), true);
        self::assertIsArray($batch);
        self::assertSame([['package' => ['ecosystem' => 'Packagist', 'name' => 'a/stable'], 'version' => '1.0.0']], $batch['queries']);
    }

    public function testOsvFailuresAreReported(): void
    {
        $client = new FakeOsv([], []);
        $client->status = 500;
        $result = (new OsvSource($client))->scan(self::vulnerableLock(), true);
        self::assertSame('OSV request POST /v1/querybatch failed with HTTP 500.', $result->error);

        $client = new FakeOsv([], []);
        $client->offline = true;
        $result = (new OsvSource($client))->scan(self::vulnerableLock(), true);
        self::assertSame('OSV could not be reached: connection refused', $result->error);

        // An advisory id whose details cannot be fetched fails the source.
        $client = new FakeOsv(['guzzlehttp/guzzle' => ['GHSA-missing']], []);
        $result = (new OsvSource($client))->scan(self::vulnerableLock(), true);
        self::assertSame('OSV request GET /v1/vulns/GHSA-missing failed with HTTP 404.', $result->error);
        self::assertSame([], $result->advisories);
    }

    // Merging and reports

    public function testSameAdvisoryFromTwoSourcesIsMergedButRelatedOnesAreNot(): void
    {
        $composer = SourceResult::ok('composer', [
            new Advisory('acme/lib', '1.0.0', 'PKSA-1', ['CVE-2022-31043', 'GHSA-w248-ffj2-4v5q'], 'Authorization header', Severity::Medium, 'https://github.com/advisories/GHSA-w248-ffj2-4v5q', '<7.4.5', ['composer']),
            new Advisory('acme/lib', '1.0.0', 'PKSA-2', ['CVE-2022-31042', 'GHSA-f2wf-25xc-69c9'], 'Cookie header', Severity::High, null, '<7.4.5', ['composer']),
        ]);
        // OSV aliases these two advisories to each other.
        $osv = SourceResult::ok('osv', [
            new Advisory('acme/lib', '1.0.0', 'GHSA-f2wf-25xc-69c9', ['CVE-2022-31042', 'GHSA-w248-ffj2-4v5q'], 'Cookie', Severity::High, 'https://osv.dev/x', null, ['osv']),
            new Advisory('acme/lib', '1.0.0', 'GHSA-w248-ffj2-4v5q', ['CVE-2022-31043', 'GHSA-f2wf-25xc-69c9'], 'Auth', Severity::Critical, 'https://osv.dev/y', null, ['osv']),
            new Advisory('other/lib', '1.0.0', 'GHSA-w248-ffj2-4v5q', [], 'Same id, other package', Severity::Low, null, null, ['osv']),
        ]);

        $report = self::report($composer, $osv);

        self::assertCount(3, $report->advisories);
        [$auth, $cookie, $other] = $report->advisories;
        self::assertSame('PKSA-1', $auth->id);
        self::assertSame(Severity::Critical, $auth->severity, 'The higher severity wins.');
        self::assertSame(['composer', 'osv'], $auth->sources);
        self::assertSame('https://github.com/advisories/GHSA-w248-ffj2-4v5q', $auth->link);
        self::assertSame('Authorization header', $auth->title);
        self::assertSame('PKSA-2', $cookie->id);
        self::assertSame(['composer', 'osv'], $cookie->sources);
        self::assertSame('other/lib', $other->package);
    }

    public function testStatusIsVulnerableIncompleteOrNoKnownVulnerabilities(): void
    {
        $clean = self::report(SourceResult::ok('composer', []), SourceResult::ok('osv', []));
        self::assertSame('no_known_vulnerabilities', $clean->status());
        self::assertTrue($clean->complete());

        $failed = self::report(SourceResult::ok('composer', []), SourceResult::failed('osv', 'OSV could not be reached'));
        self::assertSame('incomplete', $failed->status());
        self::assertFalse($failed->complete());

        $vulnerable = self::report(SourceResult::ok('composer', [self::advisory('X-1')]), SourceResult::failed('osv', 'down'));
        self::assertSame('vulnerabilities_found', $vulnerable->status());

        $none = (new Auditor([]))->audit(self::vulnerableLock());
        self::assertSame('incomplete', $none->status(), 'An audit with no sources proves nothing.');
    }

    public function testThrowingSourceBecomesAFailedSource(): void
    {
        $report = (new Auditor([self::source('broken', new \LogicException('bug'))]))->audit(self::vulnerableLock());

        self::assertSame('LogicException: bug', $report->results[0]->error);
        self::assertSame('incomplete', $report->status());
        self::assertSame(['swiftmailer/swiftmailer' => 'symfony/mailer'], $report->abandoned, 'Abandoned packages come from the lock file even when sources fail.');
    }

    public function testJsonReportNeverClaimsSafety(): void
    {
        $report = self::report(SourceResult::ok('composer', []));
        $json = (string) json_encode($report->toArray());
        $data = $report->toArray();

        self::assertSame(1, $data['schema']);
        self::assertSame((new \DateTimeImmutable('2026-10-26 12:00:00'))->format(\DATE_ATOM), $data['generated_at']);
        self::assertSame('no_known_vulnerabilities', $data['status']);
        self::assertSame(['path', 'sha256', 'packages', 'include_dev'], array_keys((array) $data['lock']));
        self::assertSame(AuditReport::NOTICE, $data['notice']);
        self::assertSame(1, substr_count(strtolower($json), 'safe'), 'The only mention of "safe" is the notice that says the result does not mean safe.');
        self::assertStringContainsString('does not mean the dependencies are safe', $json);
    }

    // Policy

    public function testPolicyThresholdAndUnknownSeverity(): void
    {
        $report = self::report(SourceResult::ok('composer', [
            self::advisory('LOW-1', [], Severity::Low),
            self::advisory('MED-1', [], Severity::Medium),
            self::advisory('UNK-1', [], Severity::Unknown),
        ]));
        $now = new \DateTimeImmutable('2026-10-26');

        self::assertSame(['acme/lib 1.0.0: MED-1 (medium)', 'acme/lib 1.0.0: LOW-1 (low)', 'acme/lib 1.0.0: UNK-1 (unknown)'], (new AuditPolicy())->evaluate($report, $now)->failures);

        $high = AuditPolicy::fromConfig(['fail_on' => 'high'])->evaluate($report, $now);
        self::assertSame(['acme/lib 1.0.0: UNK-1 (unknown)'], $high->failures, 'An advisory without a severity always fails.');
        self::assertFalse($high->passed());
    }

    public function testIgnoreNeedsAReasonAndExpires(): void
    {
        $report = self::report(SourceResult::ok('composer', [self::advisory('PKSA-1', ['CVE-2026-1'])]));
        $policy = AuditPolicy::fromConfig(['ignore' => [['id' => 'cve-2026-1', 'reason' => 'Only used in tests', 'until' => '2026-10-31']]]);

        $before = $policy->evaluate($report, new \DateTimeImmutable('2026-10-31 23:00'));
        self::assertTrue($before->passed());
        self::assertSame([['id' => 'PKSA-1', 'package' => 'acme/lib', 'reason' => 'Only used in tests', 'until' => '2026-10-31']], $before->ignored);

        $after = $policy->evaluate($report, new \DateTimeImmutable('2026-11-01 00:00'));
        self::assertFalse($after->passed());
        self::assertSame(['cve-2026-1 (ignored until 2026-10-31)'], $after->expiredIgnores);

        foreach ([[['id' => 'CVE-2026-1']], [['id' => 'CVE-2026-1', 'reason' => '  ']], [['id' => 'CVE-2026-1', 'reason' => 'x', 'until' => 'next week']]] as $ignore) {
            try {
                AuditPolicy::fromConfig(['ignore' => $ignore]);
                self::fail('Invalid ignore rule accepted: ' . json_encode($ignore));
            } catch (AuditException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(AuditException::class);
        AuditPolicy::fromConfig(['fail_on' => 'severe']);
    }

    public function testAbandonedAndIncompletePolicies(): void
    {
        $report = self::report(SourceResult::failed('composer', 'down'));
        $now = new \DateTimeImmutable('2026-10-26');

        $default = (new AuditPolicy())->evaluate($report, $now);
        self::assertSame([], $default->failures);
        self::assertTrue($default->incomplete);
        self::assertFalse($default->passed());

        $lenient = AuditPolicy::fromConfig(['fail_on_source_error' => false, 'fail_on_abandoned' => true])->evaluate($report, $now);
        self::assertFalse($lenient->incomplete);
        self::assertSame(['swiftmailer/swiftmailer is abandoned, use symfony/mailer'], $lenient->failures);
    }

    // Stale reports

    public function testSavedReportGoesStale(): void
    {
        $lock = self::vulnerableLock();
        $saved = json_decode((string) json_encode(self::report(SourceResult::ok('composer', []))->toArray()), true);
        self::assertIsArray($saved);

        self::assertSame([], AuditReport::staleness($saved, $lock, new \DateTimeImmutable('2026-11-02 11:00'), 7));
        self::assertSame(
            ['the report is older than 7 days (generated 2026-10-26 12:00); advisories are published every day'],
            AuditReport::staleness($saved, $lock, new \DateTimeImmutable('2026-11-03 13:00'), 7),
        );

        $changed = $this->lock([['name' => 'guzzlehttp/guzzle', 'version' => '7.10.0']]);
        self::assertSame(['composer.lock has changed since the report was generated'], AuditReport::staleness($saved, $changed, new \DateTimeImmutable('2026-10-27'), 7));

        $incomplete = json_decode((string) json_encode(self::report(SourceResult::failed('composer', 'down'))->toArray()), true);
        self::assertIsArray($incomplete);
        self::assertSame(['the scan was incomplete: a source failed'], AuditReport::staleness($incomplete, $lock, new \DateTimeImmutable('2026-10-27'), 7));

        self::assertSame(['the report has an unknown format'], AuditReport::staleness(['schema' => 99], $lock, new \DateTimeImmutable(), 7));
    }
}
