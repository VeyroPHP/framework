<?php

declare(strict_types=1);

namespace VeyroPHP\Security\Audit;

/**
 * The result of one audit. Its status is one of:
 *
 * - vulnerabilities_found: at least one source reported an advisory
 * - incomplete: nothing was reported, but a source failed (or none ran)
 * - no_known_vulnerabilities: every source ran and none matched
 *
 * None of these means the dependencies are safe: sources only know published
 * advisories, and only for the versions in the lock file at scan time.
 */
final class AuditReport
{
    public const SCHEMA = 1;

    public const NOTICE = 'This report lists published advisories that matched the locked versions when it was generated. An empty result does not mean the dependencies are safe.';

    /**
     * @param list<SourceResult> $results
     * @param list<Advisory> $advisories
     * @param array<string, string> $abandoned package => suggested replacement ('' when none)
     */
    public function __construct(
        public readonly \DateTimeImmutable $generatedAt,
        public readonly LockFile $lock,
        public readonly bool $includeDev,
        public readonly array $results,
        public readonly array $advisories,
        public readonly array $abandoned,
    ) {}

    public function complete(): bool
    {
        if ($this->results === []) {
            return false;
        }

        foreach ($this->results as $result) {
            if (!$result->succeeded()) {
                return false;
            }
        }

        return true;
    }

    public function status(): string
    {
        return match (true) {
            $this->advisories !== [] => 'vulnerabilities_found',
            !$this->complete() => 'incomplete',
            default => 'no_known_vulnerabilities',
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(?PolicyResult $policy = null): array
    {
        $data = [
            'schema' => self::SCHEMA,
            'generated_at' => $this->generatedAt->format(\DateTimeInterface::ATOM),
            'status' => $this->status(),
            'complete' => $this->complete(),
            'notice' => self::NOTICE,
            'lock' => [
                'path' => $this->lock->path,
                'sha256' => $this->lock->sha256,
                'packages' => count($this->lock->packages($this->includeDev)),
                'include_dev' => $this->includeDev,
            ],
            'sources' => array_map(static fn(SourceResult $r): array => [
                'name' => $r->source,
                'status' => $r->succeeded() ? 'ok' : 'failed',
                'error' => $r->error,
                'advisories' => count($r->advisories),
                'not_checked' => $r->notChecked,
            ], $this->results),
            'advisories' => array_map(static fn(Advisory $a): array => $a->toArray(), $this->advisories),
            'abandoned' => (object) $this->abandoned,
        ];

        if ($policy !== null) {
            $data['policy'] = $policy->toArray();
        }

        return $data;
    }

    /**
     * Reasons a saved report no longer describes the project: another schema,
     * a different lock file, an incomplete scan or an age over $maxAgeDays.
     *
     * @param array<mixed> $saved a decoded report
     * @return list<string> empty when the report is still current
     */
    public static function staleness(array $saved, LockFile $lock, \DateTimeImmutable $now, int $maxAgeDays): array
    {
        if (($saved['schema'] ?? null) !== self::SCHEMA) {
            return ['the report has an unknown format'];
        }

        $problems = [];
        $sha = is_array($saved['lock'] ?? null) ? ($saved['lock']['sha256'] ?? null) : null;

        if ($sha !== $lock->sha256) {
            $problems[] = 'composer.lock has changed since the report was generated';
        }

        if (($saved['complete'] ?? false) !== true) {
            $problems[] = 'the scan was incomplete: a source failed';
        }

        $generated = is_string($saved['generated_at'] ?? null)
            ? \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $saved['generated_at'])
            : false;

        if ($generated === false) {
            $problems[] = 'the report has no valid generation time';
        } elseif ($generated->modify("+{$maxAgeDays} days") < $now) {
            $problems[] = sprintf('the report is older than %d days (generated %s); advisories are published every day', $maxAgeDays, $generated->format('Y-m-d H:i'));
        }

        return $problems;
    }
}
