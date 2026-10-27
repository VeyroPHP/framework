<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Integration\Security;

use PHPUnit\Framework\TestCase;
use VeyroPHP\Console\Input;
use VeyroPHP\Console\Output;
use VeyroPHP\Events\EventDispatcher;
use VeyroPHP\Mail\Mailer;
use VeyroPHP\Mail\Transport\ArrayTransport;
use VeyroPHP\Security\Audit\AuditSource;
use VeyroPHP\Security\Audit\ComposerAuditSource;
use VeyroPHP\Security\Audit\Events\AuditChanged;
use VeyroPHP\Security\Audit\LockFile;
use VeyroPHP\Security\Audit\OsvSource;
use VeyroPHP\Security\Audit\SourceResult;
use VeyroPHP\Security\Console\AuditCommand;
use VeyroPHP\Testing\TestApplication;

/**
 * security:audit on two fixture projects: "clean" (psr/log 3.0.2) and
 * "vulnerable" (guzzlehttp/guzzle 7.4.0 with published advisories, and the
 * abandoned swiftmailer). Composer's answer is the real output recorded in
 * tests/Fixtures/Security/vulnerable/composer-audit.json, so the results are
 * reproducible offline. testLiveSources repeats the check against the real
 * services when VEYROPHP_TEST_NETWORK=1.
 */
final class AuditCommandTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../Fixtures/Security';

    private TestApplication $test;

    private string $out;

    protected function setUp(): void
    {
        $this->test = TestApplication::boot();
        $this->out = $this->test->basePath() . '/storage/audit.json';
    }

    protected function tearDown(): void
    {
        $this->test->cleanUp();
    }

    /**
     * Composer answers from the recorded output for the vulnerable fixture and
     * with an empty report for the clean one.
     */
    private static function recordedComposer(): AuditSource
    {
        return new ComposerAuditSource('composer.phar', 60, static function (array $command): array {
            if (str_contains(implode(' ', $command), 'vulnerable')) {
                return [3, (string) file_get_contents(self::FIXTURES . '/vulnerable/composer-audit.json'), ''];
            }

            return [0, '{"advisories": [], "abandoned": []}', ''];
        });
    }

    private static function failingOsv(): AuditSource
    {
        return new class implements AuditSource {
            public function name(): string
            {
                return 'osv';
            }

            public function scan(LockFile $lock, bool $includeDev): SourceResult
            {
                return SourceResult::failed('osv', 'OSV could not be reached at https://api.osv.dev.');
            }
        };
    }

    /**
     * @param list<string> $tokens
     * @param array<string, mixed> $audit config "audit"
     * @param list<AuditSource>|null $sources
     * @return array{int, string, string}
     */
    private function audit(array $tokens, array $audit = [], ?array $sources = null): array
    {
        $this->test->config()->mergeDefaults('audit', $audit);
        $command = new AuditCommand($this->test->app(), $this->test->config());
        $command->useSources($sources ?? [self::recordedComposer()]);

        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        self::assertIsResource($out);
        self::assertIsResource($err);

        $code = $command->execute(Input::parse($tokens, $command->definition()), new Output($out, $err));
        rewind($out);
        rewind($err);

        return [$code, (string) stream_get_contents($out), (string) stream_get_contents($err)];
    }

    public function testCleanAndVulnerableProjectsGiveDifferentResults(): void
    {
        [$cleanCode, $cleanOut] = $this->audit(['--path=' . self::FIXTURES . '/clean', '--sources=composer']);
        self::assertSame(0, $cleanCode);
        self::assertStringContainsString('No known vulnerabilities found in the sources that ran.', $cleanOut);
        self::assertStringContainsString('does not mean the dependencies are safe', $cleanOut);

        [$code, $out, $err] = $this->audit(['--path=' . self::FIXTURES . '/vulnerable', '--sources=composer']);
        self::assertSame(1, $code);
        self::assertStringContainsString('Checked 2 packages', $out);
        self::assertMatchesRegularExpression('/high\s+guzzlehttp\/guzzle 7\.4\.0\s+CVE-2026-69246 GHSA-v5mv-p594-2x33\s+Guzzle: Noncanonical host/', $out);
        self::assertStringContainsString('swiftmailer/swiftmailer is abandoned; suggested replacement: symfony/mailer.', $err);
        self::assertStringContainsString('14 finding(s) fail the audit policy.', $err);
    }

    public function testJsonReportIsReproducible(): void
    {
        $reports = [];

        foreach ([1, 2] as $run) {
            [$code, $out] = $this->audit(['--path=' . self::FIXTURES . '/vulnerable', '--sources=composer', '--format=json', '--output=' . $this->out]);
            self::assertSame(1, $code);
            $report = json_decode($out, true);
            self::assertIsArray($report);
            self::assertSame($report, json_decode((string) file_get_contents($this->out), true), '--output writes the same report.');
            unset($report['generated_at']);
            $reports[] = $report;
        }

        self::assertSame($reports[0], $reports[1]);
        $report = $reports[0];
        self::assertSame('vulnerabilities_found', $report['status']);
        self::assertTrue($report['complete']);
        self::assertIsArray($report['lock']);
        self::assertIsArray($report['advisories']);
        self::assertIsArray($report['policy']);
        self::assertSame(hash_file('sha256', self::FIXTURES . '/vulnerable/composer.lock'), $report['lock']['sha256']);
        self::assertSame([['name' => 'composer', 'status' => 'ok', 'error' => null, 'advisories' => 14, 'not_checked' => []]], $report['sources']);
        self::assertCount(14, $report['advisories']);
        self::assertSame(['swiftmailer/swiftmailer' => 'symfony/mailer'], $report['abandoned']);
        self::assertFalse($report['policy']['passed']);
        self::assertSame(['high', 'high', 'high', 'high', 'high', 'high'], array_slice(array_column($report['advisories'], 'severity'), 0, 6));
    }

    public function testFailedSourceIsNeverSilentlyIgnored(): void
    {
        [$code, , $err] = $this->audit(['--path=' . self::FIXTURES . '/clean'], [], [self::recordedComposer(), self::failingOsv()]);
        self::assertSame(AuditCommand::INCOMPLETE, $code);
        self::assertStringContainsString('osv: FAILED: OSV could not be reached', $err);
        self::assertStringContainsString('The scan is incomplete', $err);

        // Findings take precedence over the incomplete scan.
        [$code] = $this->audit(['--path=' . self::FIXTURES . '/vulnerable'], [], [self::recordedComposer(), self::failingOsv()]);
        self::assertSame(1, $code);

        // Turned off explicitly, it still warns.
        [$code, , $err] = $this->audit(['--path=' . self::FIXTURES . '/clean'], ['fail_on_source_error' => false], [self::recordedComposer(), self::failingOsv()]);
        self::assertSame(0, $code);
        self::assertStringContainsString('a source failed (audit.fail_on_source_error is off)', $err);
    }

    public function testPolicyFromConfig(): void
    {
        $ids = array_map(static fn(string $cve): array => ['id' => $cve, 'reason' => 'Fixture: accepted for the test'], [
            'CVE-2022-31043', 'CVE-2022-29248', 'CVE-2022-31042', 'CVE-2026-69246', 'CVE-2022-31090', 'CVE-2022-31091',
        ]);

        // The six high advisories are ignored, the eight medium ones are below "high".
        [$code, $out] = $this->audit(['--path=' . self::FIXTURES . '/vulnerable', '--sources=composer'], ['fail_on' => 'high', 'ignore' => $ids]);
        self::assertSame(0, $code);
        self::assertStringContainsString('Ignored PKSA-gcrk-3vtt-1r14 (guzzlehttp/guzzle): Fixture: accepted for the test', $out);
        self::assertStringContainsString('The findings are below the policy threshold.', $out);
    }

    public function testFailOnAbandoned(): void
    {
        [$code, , $err] = $this->audit(['--path=' . self::FIXTURES . '/vulnerable', '--sources=composer', '--format=json'], ['fail_on' => 'critical', 'fail_on_abandoned' => true]);

        self::assertSame(1, $code);
        self::assertSame('', $err);
    }

    public function testSavedReportCheck(): void
    {
        $this->audit(['--path=' . self::FIXTURES . '/vulnerable', '--sources=composer', '--format=json', '--output=' . $this->out]);

        [$code, $out] = $this->audit(['--path=' . self::FIXTURES . '/vulnerable', '--check=' . $this->out]);
        self::assertSame(0, $code);
        self::assertStringContainsString('The saved report is current for this composer.lock.', $out);

        [$code, , $err] = $this->audit(['--path=' . self::FIXTURES . '/clean', '--check=' . $this->out]);
        self::assertSame(1, $code);
        self::assertStringContainsString('Stale report: composer.lock has changed since the report was generated.', $err);

        $report = json_decode((string) file_get_contents($this->out), true);
        self::assertIsArray($report);
        $report['generated_at'] = (new \DateTimeImmutable('-10 days'))->format(\DATE_ATOM);
        file_put_contents($this->out, (string) json_encode($report));

        [$code, , $err] = $this->audit(['--path=' . self::FIXTURES . '/vulnerable', '--check=' . $this->out, '--max-age=7']);
        self::assertSame(1, $code);
        self::assertStringContainsString('the report is older than 7 days', $err);

        [$code] = $this->audit(['--path=' . self::FIXTURES . '/vulnerable', '--check=' . $this->out, '--max-age=30']);
        self::assertSame(0, $code);
    }

    public function testUsageErrors(): void
    {
        $cases = [
            [['--path=' . self::FIXTURES . '/missing'], [], 'No lock file at'],
            [['--path=' . self::FIXTURES . '/clean', '--sources=snyk'], [], 'Unknown audit source "snyk": use composer.'],
            [['--path=' . self::FIXTURES . '/clean', '--sources=composer', '--format=xml'], [], '--format must be table or json.'],
            [['--path=' . self::FIXTURES . '/clean'], ['fail_on' => 'severe'], 'audit.fail_on must be low, medium, high or critical.'],
        ];

        foreach ($cases as [$tokens, $config, $message]) {
            $this->tearDown();
            $this->setUp();
            [$code, , $err] = $this->audit($tokens, $config);

            self::assertSame(2, $code, $message);
            self::assertStringContainsString($message, $err);
        }
    }

    public function testNotifyOnlyWhenTheResultChanges(): void
    {
        $events = [];
        $dispatcher = $this->test->make(EventDispatcher::class);
        $dispatcher->listen(AuditChanged::class, static function (AuditChanged $e) use (&$events): void {
            $events[] = $e->diff->summary();
        });
        $transport = $this->test->make(Mailer::class)->transport();
        self::assertInstanceOf(ArrayTransport::class, $transport);
        $config = ['notify' => ['mail' => ['ops@example.test']]];
        $vulnerable = ['--path=' . self::FIXTURES . '/vulnerable', '--notify', '--sources=composer'];

        // First run: everything is new.
        [$code, $out] = $this->audit($vulnerable, $config, [self::recordedComposer()]);
        self::assertSame(1, $code);
        self::assertStringContainsString('Notified (14 new advisories, 1 newly abandoned package): AuditChanged event, mail to ops@example.test.', $out);
        self::assertFileExists($this->test->basePath() . '/storage/security/audit.json', 'The report is kept for the next run.');
        self::assertCount(1, $transport->messages());
        self::assertSame('[Test] Dependency audit: 14 new advisories, 1 newly abandoned package', $transport->messages()[0]->message->getSubject());

        // Same result the next day: no email, no event.
        [$code, $out] = $this->audit($vulnerable, $config, [self::recordedComposer()]);
        self::assertSame(1, $code);
        self::assertStringContainsString('No changes since the previous audit: nothing to notify.', $out);
        self::assertCount(1, $transport->messages());

        // A source starts failing.
        $this->audit([...$vulnerable, '--sources=composer,osv'], $config, [self::recordedComposer(), self::failingOsv()]);
        self::assertCount(2, $transport->messages());
        self::assertStringContainsString('osv: OSV could not be reached', (string) $transport->messages()[1]->message->getText());

        // Dependencies updated (here: the clean lock file): everything resolved.
        [$code] = $this->audit(['--path=' . self::FIXTURES . '/clean', '--notify', '--sources=composer'], $config, [self::recordedComposer()]);
        self::assertSame(0, $code);
        self::assertCount(3, $transport->messages());
        self::assertSame('[Test] Dependency audit: 14 resolved', $transport->messages()[2]->message->getSubject());

        self::assertSame(['14 new advisories, 1 newly abandoned package', 'a source failed', '14 resolved'], $events);
    }

    public function testFailedNotificationIsAnError(): void
    {
        $this->test->cleanUp();
        $this->test = TestApplication::boot(config: ['mail' => ['default' => 'missing']]);
        [$code, , $err] = $this->audit(['--path=' . self::FIXTURES . '/vulnerable', '--notify', '--sources=composer', '--no-dev'], ['notify' => ['mail' => ['ops@example.test']], 'fail_on' => 'critical', 'fail_on_abandoned' => false], [self::recordedComposer()]);

        // The findings are below the policy, but the scheduled job must not look successful.
        self::assertSame(1, $code);
        self::assertStringContainsString('The audit notification email could not be sent:', $err);
    }

    public function testCommandIsRegistered(): void
    {
        [$code, $out] = $this->test->command(['security:audit', '--help']);

        self::assertSame(0, $code);
        self::assertStringContainsString('Only composer.lock is read: no package code is installed or run.', $out);
    }

    public function testLiveSources(): void
    {
        if (getenv('VEYROPHP_TEST_NETWORK') !== '1') {
            self::markTestSkipped('Set VEYROPHP_TEST_NETWORK=1 to run composer audit and OSV.dev for real.');
        }

        $live = [new ComposerAuditSource(ComposerAuditSource::locate()), new OsvSource()];

        [$code, $out] = $this->audit(['--path=' . self::FIXTURES . '/clean', '--format=json'], [], $live);
        $clean = json_decode($out, true);
        self::assertIsArray($clean);
        self::assertSame(0, $code, $out);
        self::assertSame('no_known_vulnerabilities', $clean['status']);
        self::assertIsArray($clean['sources']);
        self::assertSame(['ok', 'ok'], array_column($clean['sources'], 'status'));

        [$code, $out] = $this->audit(['--path=' . self::FIXTURES . '/vulnerable', '--format=json'], [], $live);
        $vulnerable = json_decode($out, true);
        self::assertIsArray($vulnerable);
        self::assertSame(1, $code);
        self::assertSame('vulnerabilities_found', $vulnerable['status']);
        self::assertIsArray($vulnerable['sources']);
        self::assertIsArray($vulnerable['advisories']);
        self::assertSame(['ok', 'ok'], array_column($vulnerable['sources'], 'status'));
        self::assertGreaterThanOrEqual(14, count($vulnerable['advisories']));
        $identifiers = [];
        foreach ($vulnerable['advisories'] as $advisory) {
            self::assertIsArray($advisory);
            self::assertIsArray($advisory['aliases']);
            $identifiers = [...$identifiers, $advisory['id'], ...$advisory['aliases']];
        }
        self::assertContains('CVE-2022-29248', $identifiers);
    }
}
