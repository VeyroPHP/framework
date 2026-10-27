<?php

declare(strict_types=1);

namespace VeyroPHP\Security\Audit;

/**
 * What changed between the previous saved report and a new audit: the basis
 * of scheduled-audit notifications, so people hear about changes instead of
 * the same findings every day.
 *
 * An advisory counts as known when the previous report listed one for the
 * same package sharing any identifier (PKSA, CVE, GHSA); a version change
 * alone does not make it new.
 */
final class AuditDiff
{
    /**
     * @param list<Advisory> $new advisories not in the previous report
     * @param list<array{package: string, id: string, title: string}> $resolved previous advisories no longer reported
     * @param array<string, string> $newlyAbandoned package => suggested replacement ('' when none)
     * @param array<string, string> $failedSources source => error, for sources that did not fail last time
     */
    private function __construct(
        public readonly bool $firstRun,
        public readonly array $new,
        public readonly array $resolved,
        public readonly array $newlyAbandoned,
        public readonly array $failedSources,
    ) {}

    /**
     * @param array<mixed>|null $previous a decoded saved report, or null when there is none
     */
    public static function between(?array $previous, AuditReport $current): self
    {
        $before = is_array($previous['advisories'] ?? null) ? $previous['advisories'] : [];
        $known = [];

        foreach ($before as $entry) {
            if (is_array($entry) && is_string($entry['package'] ?? null) && is_string($entry['id'] ?? null)) {
                $aliases = array_filter(is_array($entry['aliases'] ?? null) ? $entry['aliases'] : [], 'is_string');
                $known[] = [
                    'package' => $entry['package'],
                    'id' => $entry['id'],
                    'title' => is_string($entry['title'] ?? null) ? $entry['title'] : '',
                    'ids' => array_map('strtoupper', [$entry['id'], ...$aliases]),
                ];
            }
        }

        $stillReported = [];
        $unmatched = [];

        // Own ids first, then shared aliases with entries nothing else matched:
        // databases alias related advisories to each other, and an alias-only
        // match would pair two current advisories with one old entry.
        foreach ($current->advisories as $n => $advisory) {
            foreach ($known as $i => $old) {
                if (!isset($stillReported[$i]) && $old['package'] === $advisory->package && strtoupper($old['id']) === strtoupper($advisory->id)) {
                    $stillReported[$i] = true;

                    continue 2;
                }
            }

            $unmatched[$n] = $advisory;
        }

        $new = [];

        foreach ($unmatched as $advisory) {
            foreach ($known as $i => $old) {
                if (!isset($stillReported[$i]) && $old['package'] === $advisory->package && array_intersect($old['ids'], $advisory->identifiers()) !== []) {
                    $stillReported[$i] = true;

                    continue 2;
                }
            }

            $new[] = $advisory;
        }

        $resolved = [];

        // Only a complete scan can say an advisory is gone: a failed source may simply have missed it.
        if ($current->complete()) {
            foreach ($known as $i => $old) {
                if (!isset($stillReported[$i])) {
                    $resolved[] = ['package' => $old['package'], 'id' => $old['id'], 'title' => $old['title']];
                }
            }
        }

        $abandonedBefore = is_array($previous['abandoned'] ?? null) ? $previous['abandoned'] : [];
        $failedBefore = [];

        foreach (is_array($previous['sources'] ?? null) ? $previous['sources'] : [] as $source) {
            if (is_array($source) && is_string($source['name'] ?? null) && ($source['status'] ?? null) === 'failed') {
                $failedBefore[$source['name']] = true;
            }
        }

        $failed = [];

        foreach ($current->results as $result) {
            if (!$result->succeeded() && !isset($failedBefore[$result->source])) {
                $failed[$result->source] = (string) $result->error;
            }
        }

        return new self(
            $previous === null,
            $new,
            $resolved,
            array_diff_key($current->abandoned, $abandonedBefore),
            $failed,
        );
    }

    public function changed(): bool
    {
        return $this->new !== [] || $this->resolved !== [] || $this->newlyAbandoned !== [] || $this->failedSources !== [];
    }

    public function summary(): string
    {
        $parts = [];

        if ($this->new !== []) {
            $parts[] = count($this->new) . ' new ' . (count($this->new) === 1 ? 'advisory' : 'advisories');
        }

        if ($this->failedSources !== []) {
            $parts[] = count($this->failedSources) === 1 ? 'a source failed' : count($this->failedSources) . ' sources failed';
        }

        if ($this->newlyAbandoned !== []) {
            $parts[] = count($this->newlyAbandoned) . ' newly abandoned ' . (count($this->newlyAbandoned) === 1 ? 'package' : 'packages');
        }

        if ($this->resolved !== []) {
            $parts[] = count($this->resolved) . ' resolved';
        }

        return $parts === [] ? 'no changes' : implode(', ', $parts);
    }

    /**
     * @return array{first_run: bool, new: list<array<string, mixed>>, resolved: list<array{package: string, id: string, title: string}>, newly_abandoned: array<string, string>, failed_sources: array<string, string>}
     */
    public function toArray(): array
    {
        return [
            'first_run' => $this->firstRun,
            'new' => array_map(static fn(Advisory $a): array => $a->toArray(), $this->new),
            'resolved' => $this->resolved,
            'newly_abandoned' => $this->newlyAbandoned,
            'failed_sources' => $this->failedSources,
        ];
    }
}
