<?php

declare(strict_types=1);

namespace VeyroPHP\Security\Audit;

/**
 * Runs every source on a lock file and merges their results into one report.
 * Abandoned packages come from the lock file itself as well, so they are
 * reported even when every source fails.
 */
final class Auditor
{
    /**
     * @param list<AuditSource> $sources
     */
    public function __construct(private readonly array $sources) {}

    public function audit(LockFile $lock, bool $includeDev = true, ?\DateTimeImmutable $now = null): AuditReport
    {
        $results = [];
        $advisories = [];
        $abandoned = [];

        foreach ($lock->packages($includeDev) as $package) {
            if ($package['abandoned'] !== null) {
                $abandoned[$package['name']] = $package['abandoned'];
            }
        }

        foreach ($this->sources as $source) {
            try {
                $result = $source->scan($lock, $includeDev);
            } catch (\Throwable $e) {
                $result = SourceResult::failed($source->name(), $e::class . ': ' . $e->getMessage());
            }

            $results[] = $result;
            $abandoned += $result->abandoned;

            foreach ($result->advisories as $advisory) {
                $advisories = self::add($advisories, $advisory);
            }
        }

        usort($advisories, static fn(Advisory $a, Advisory $b): int => [$b->severity->rank(), $a->package, $a->id] <=> [$a->severity->rank(), $b->package, $b->id]);
        ksort($abandoned);

        return new AuditReport($now ?? new \DateTimeImmutable(), $lock, $includeDev, $results, $advisories, $abandoned);
    }

    /**
     * @param list<Advisory> $advisories
     * @return list<Advisory>
     */
    private static function add(array $advisories, Advisory $advisory): array
    {
        $i = self::find($advisories, $advisory);

        if ($i === null) {
            $advisories[] = $advisory;

            return $advisories;
        }

        return array_values(array_replace($advisories, [$i => $advisories[$i]->mergedWith($advisory)]));
    }

    /**
     * The advisory already listed for the same package and issue. Its own id
     * is matched first: databases sometimes alias two related advisories to
     * each other (OSV lists GHSA-f2wf-25xc-69c9 and GHSA-w248-ffj2-4v5q as
     * aliases of each other), and matching on aliases alone would merge them.
     *
     * @param list<Advisory> $advisories
     */
    private static function find(array $advisories, Advisory $advisory): ?int
    {
        foreach ($advisories as $i => $existing) {
            if ($existing->package === $advisory->package && $existing->matches($advisory->id)) {
                return $i;
            }
        }

        foreach ($advisories as $i => $existing) {
            if ($existing->sameAs($advisory)) {
                return $i;
            }
        }

        return null;
    }
}
