<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Unit\Security\Audit;

use PHPUnit\Framework\TestCase;
use VeyroPHP\Security\Audit\Advisory;
use VeyroPHP\Security\Audit\AuditDiff;
use VeyroPHP\Security\Audit\AuditMail;
use VeyroPHP\Security\Audit\Auditor;
use VeyroPHP\Security\Audit\AuditPolicy;
use VeyroPHP\Security\Audit\AuditReport;
use VeyroPHP\Security\Audit\AuditSource;
use VeyroPHP\Security\Audit\LockFile;
use VeyroPHP\Security\Audit\Severity;
use VeyroPHP\Security\Audit\SourceResult;

final class AuditDiffTest extends TestCase
{
    private const LOCK = __DIR__ . '/../../../Fixtures/Security/vulnerable/composer.lock';

    /**
     * @param list<string> $aliases
     */
    private static function advisory(string $id, array $aliases = [], string $version = '7.4.0'): Advisory
    {
        return new Advisory('guzzlehttp/guzzle', $version, $id, $aliases, 'Title ' . $id, Severity::High, 'https://example.test/' . $id, null, ['composer']);
    }

    private static function report(SourceResult ...$results): AuditReport
    {
        $sources = [];

        foreach ($results as $result) {
            $sources[] = new class ($result) implements AuditSource {
                public function __construct(private readonly SourceResult $result) {}

                public function name(): string
                {
                    return $this->result->source;
                }

                public function scan(LockFile $lock, bool $includeDev): SourceResult
                {
                    return $this->result;
                }
            };
        }

        return (new Auditor($sources))->audit(LockFile::read(self::LOCK), true, new \DateTimeImmutable('2026-10-27 03:30'));
    }

    /**
     * @return array<mixed>
     */
    private static function saved(AuditReport $report): array
    {
        $data = json_decode((string) json_encode($report->toArray()), true);
        self::assertIsArray($data);

        return $data;
    }

    public function testFirstRunReportsEverythingAsNew(): void
    {
        $diff = AuditDiff::between(null, self::report(SourceResult::ok('composer', [self::advisory('PKSA-1')])));

        self::assertTrue($diff->firstRun);
        self::assertTrue($diff->changed());
        self::assertSame(['PKSA-1'], array_map(static fn(Advisory $a): string => $a->id, $diff->new));
        self::assertSame(['swiftmailer/swiftmailer' => 'symfony/mailer'], $diff->newlyAbandoned);
        self::assertSame('1 new advisory, 1 newly abandoned package', $diff->summary());
    }

    public function testSameFindingsAreNotAChange(): void
    {
        $previous = self::saved(self::report(SourceResult::ok('composer', [self::advisory('PKSA-1', ['CVE-2026-1'])])));
        // Same advisory under another source's id, for a newer (still affected) version.
        $diff = AuditDiff::between($previous, self::report(SourceResult::ok('osv', [self::advisory('GHSA-x', ['CVE-2026-1'], '7.5.0')])));

        self::assertFalse($diff->changed());
        self::assertSame('no changes', $diff->summary());
    }

    public function testAdvisoriesAliasedToEachOtherStayPaired(): void
    {
        // Composer reports two separate advisories; OSV aliases them to each other,
        // so after merging both carry each other's CVE and GHSA.
        $both = ['CVE-2022-31042', 'CVE-2022-31043', 'GHSA-f2wf-25xc-69c9', 'GHSA-w248-ffj2-4v5q'];
        $composer = SourceResult::ok('composer', [self::advisory('PKSA-2z36', ['CVE-2022-31043', 'GHSA-w248-ffj2-4v5q']), self::advisory('PKSA-fvw5', ['CVE-2022-31042', 'GHSA-f2wf-25xc-69c9'])]);
        $osv = SourceResult::ok('osv', [self::advisory('GHSA-w248-ffj2-4v5q', $both), self::advisory('GHSA-f2wf-25xc-69c9', $both)]);
        $report = self::report($composer, $osv);
        self::assertCount(2, $report->advisories);

        $diff = AuditDiff::between(self::saved($report), self::report($composer, $osv));

        self::assertFalse($diff->changed(), $diff->summary());
    }

    public function testNewAndResolvedAdvisories(): void
    {
        $previous = self::saved(self::report(SourceResult::ok('composer', [self::advisory('PKSA-1'), self::advisory('PKSA-2')])));
        $diff = AuditDiff::between($previous, self::report(SourceResult::ok('composer', [self::advisory('PKSA-2'), self::advisory('PKSA-3')])));

        self::assertSame(['PKSA-3'], array_map(static fn(Advisory $a): string => $a->id, $diff->new));
        self::assertSame([['package' => 'guzzlehttp/guzzle', 'id' => 'PKSA-1', 'title' => 'Title PKSA-1']], $diff->resolved);
        self::assertSame('1 new advisory, 1 resolved', $diff->summary());
    }

    public function testAnIncompleteScanResolvesNothingAndReportsTheFailureOnce(): void
    {
        $previous = self::saved(self::report(SourceResult::ok('composer', [self::advisory('PKSA-1')]), SourceResult::ok('osv', [])));
        $failing = self::report(SourceResult::failed('composer', 'composer audit failed (exit -1): timed out after 120 seconds'), SourceResult::ok('osv', []));

        $diff = AuditDiff::between($previous, $failing);
        self::assertSame([], $diff->resolved, 'A failed source may simply have missed the advisory.');
        self::assertSame(['composer' => 'composer audit failed (exit -1): timed out after 120 seconds'], $diff->failedSources);
        self::assertSame('a source failed', $diff->summary());

        // Still failing the next day: not reported again.
        self::assertFalse(AuditDiff::between(self::saved($failing), $failing)->changed());
    }

    public function testMailListsTheChangesAndNeverClaimsSafety(): void
    {
        $report = self::report(SourceResult::ok('composer', [self::advisory('PKSA-1', ['CVE-2026-1', 'GHSA-aaaa'])]), SourceResult::failed('osv', 'OSV could not be reached.'));
        $policy = (new AuditPolicy())->evaluate($report, new \DateTimeImmutable('2026-10-27'));
        $mail = AuditMail::build('Shop', ['ops@example.test'], $report, $policy, AuditDiff::between(null, $report));

        self::assertSame('[Shop] Dependency audit: 1 new advisory, a source failed, 1 newly abandoned package', $mail->getSubject());
        self::assertSame(['ops@example.test'], array_map(static fn($a): string => $a->email, $mail->getTo()));
        $text = (string) $mail->getText();
        self::assertStringContainsString("  [high] guzzlehttp/guzzle 7.4.0: Title PKSA-1\n      PKSA-1 CVE-2026-1 GHSA-aaaa  https://example.test/PKSA-1", $text);
        self::assertStringContainsString("Sources that failed (the result is incomplete):\n  osv: OSV could not be reached.", $text);
        self::assertStringContainsString('  swiftmailer/swiftmailer (suggested replacement: symfony/mailer)', $text);
        self::assertStringContainsString('Current state: vulnerabilities found, 1 advisories, policy FAILED.', $text);
        self::assertStringContainsString(AuditReport::NOTICE, $text);
    }
}
