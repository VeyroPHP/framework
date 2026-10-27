<?php

declare(strict_types=1);

namespace VeyroPHP\Security\Audit;

/**
 * Decides whether an audit report passes, from config "audit":
 *
 *     'fail_on' => 'low',                // lowest severity that fails: low, medium, high, critical
 *     'fail_on_abandoned' => false,      // abandoned packages are always reported
 *     'fail_on_source_error' => true,    // an incomplete scan fails
 *     'ignore' => [
 *         ['id' => 'CVE-2026-0001', 'reason' => 'Not reachable: we never call X', 'until' => '2026-12-31'],
 *     ],
 *
 * Advisories without a severity always fail: their impact is unknown. An
 * ignore needs a reason and stops applying after its "until" date.
 */
final class AuditPolicy
{
    /**
     * @param list<array{id: string, reason: string, until: ?\DateTimeImmutable}> $ignore
     */
    public function __construct(
        public readonly Severity $failOn = Severity::Low,
        public readonly bool $failOnAbandoned = false,
        public readonly bool $failOnSourceError = true,
        private readonly array $ignore = [],
    ) {}

    /**
     * @param array<mixed> $config
     */
    public static function fromConfig(array $config): self
    {
        $failOn = Severity::parse($config['fail_on'] ?? 'low');

        if ($failOn === Severity::Unknown) {
            throw new AuditException('audit.fail_on must be low, medium, high or critical.');
        }

        $ignore = [];

        foreach (is_array($config['ignore'] ?? null) ? $config['ignore'] : [] as $entry) {
            $id = is_array($entry) ? ($entry['id'] ?? null) : null;
            $reason = is_array($entry) ? ($entry['reason'] ?? null) : null;

            if (!is_string($id) || $id === '' || !is_string($reason) || trim($reason) === '') {
                throw new AuditException('Every audit.ignore entry needs an "id" and a "reason".');
            }

            $until = $entry['until'] ?? null;
            $date = is_string($until) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $until) : null;

            if ($until !== null && $date === false) {
                throw new AuditException("audit.ignore \"{$id}\": until must be a date like 2026-12-31.");
            }

            $ignore[] = ['id' => $id, 'reason' => $reason, 'until' => $date ?: null];
        }

        return new self(
            $failOn,
            ($config['fail_on_abandoned'] ?? false) === true,
            ($config['fail_on_source_error'] ?? true) !== false,
            $ignore,
        );
    }

    public function evaluate(AuditReport $report, \DateTimeImmutable $now): PolicyResult
    {
        $failures = [];
        $ignored = [];
        $expired = [];

        foreach ($report->advisories as $advisory) {
            foreach ($this->ignore as $rule) {
                if (!$advisory->matches($rule['id'])) {
                    continue;
                }

                // An ignore applies through the whole "until" day.
                if ($rule['until'] !== null && $rule['until']->modify('+1 day') <= $now) {
                    $expired[] = "{$rule['id']} (ignored until {$rule['until']->format('Y-m-d')})";

                    continue;
                }

                $ignored[] = ['id' => $advisory->id, 'package' => $advisory->package, 'reason' => $rule['reason'], 'until' => $rule['until']?->format('Y-m-d')];

                continue 2;
            }

            if ($advisory->severity === Severity::Unknown || $advisory->severity->rank() >= $this->failOn->rank()) {
                $failures[] = "{$advisory->package} {$advisory->version}: {$advisory->id} ({$advisory->severity->value})";
            }
        }

        if ($this->failOnAbandoned) {
            foreach ($report->abandoned as $package => $replacement) {
                $failures[] = "{$package} is abandoned" . ($replacement !== '' ? ", use {$replacement}" : '');
            }
        }

        return new PolicyResult(
            $failures,
            $this->failOnSourceError && !$report->complete(),
            $ignored,
            array_values(array_unique($expired)),
        );
    }
}
