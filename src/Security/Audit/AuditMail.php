<?php

declare(strict_types=1);

namespace VeyroPHP\Security\Audit;

use VeyroPHP\Mail\Message;

/**
 * The plain-text email sent by "security:audit --notify" when the result
 * changed. It lists only the changes, then the current overall state.
 */
final class AuditMail
{
    /**
     * @param list<string> $to
     */
    public static function build(string $appName, array $to, AuditReport $report, PolicyResult $policy, AuditDiff $diff): Message
    {
        $lines = [
            "Dependency audit for {$appName}",
            "Lock file: {$report->lock->path}",
            'Checked: ' . $report->generatedAt->format('Y-m-d H:i T'),
            '',
        ];

        if ($diff->new !== []) {
            $lines[] = 'New advisories (' . count($diff->new) . '):';

            foreach ($diff->new as $a) {
                $ids = implode(' ', array_slice([$a->id, ...$a->aliases], 0, 3));
                $lines[] = "  [{$a->severity->value}] {$a->package} {$a->version}: {$a->title}";
                $lines[] = "      {$ids}" . ($a->link !== null ? "  {$a->link}" : '');
            }

            $lines[] = '';
        }

        if ($diff->failedSources !== []) {
            $lines[] = 'Sources that failed (the result is incomplete):';

            foreach ($diff->failedSources as $source => $error) {
                $lines[] = "  {$source}: {$error}";
            }

            $lines[] = '';
        }

        if ($diff->newlyAbandoned !== []) {
            $lines[] = 'Newly abandoned packages:';

            foreach ($diff->newlyAbandoned as $package => $replacement) {
                $lines[] = "  {$package}" . ($replacement !== '' ? " (suggested replacement: {$replacement})" : '');
            }

            $lines[] = '';
        }

        if ($diff->resolved !== []) {
            $lines[] = 'No longer reported (' . count($diff->resolved) . '):';

            foreach ($diff->resolved as $r) {
                $lines[] = "  {$r['package']}: {$r['id']} {$r['title']}";
            }

            $lines[] = '';
        }

        $lines[] = sprintf(
            'Current state: %s, %d advisories, policy %s.',
            str_replace('_', ' ', $report->status()),
            count($report->advisories),
            $policy->passed() ? 'passed' : 'FAILED',
        );
        $lines[] = 'Details: php veyro security:audit';
        $lines[] = '';
        $lines[] = AuditReport::NOTICE;

        $message = (new Message())
            ->subject("[{$appName}] Dependency audit: {$diff->summary()}")
            ->text(implode("\n", $lines) . "\n");

        foreach ($to as $address) {
            $message->to($address);
        }

        return $message;
    }
}
