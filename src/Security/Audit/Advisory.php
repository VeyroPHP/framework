<?php

declare(strict_types=1);

namespace VeyroPHP\Security\Audit;

/**
 * One published advisory that matches an installed package version. The same
 * advisory reported by several sources is merged into one (see mergedWith()).
 */
final class Advisory
{
    /**
     * @param list<string> $aliases other identifiers (CVE, GHSA, ...)
     * @param list<string> $sources audit sources that reported it
     */
    public function __construct(
        public readonly string $package,
        public readonly string $version,
        public readonly string $id,
        public readonly array $aliases,
        public readonly string $title,
        public readonly Severity $severity,
        public readonly ?string $link,
        public readonly ?string $affectedVersions,
        public readonly array $sources,
    ) {}

    /**
     * @return list<string> the id and aliases, upper-case, without duplicates
     */
    public function identifiers(): array
    {
        return array_values(array_unique(array_map('strtoupper', [$this->id, ...$this->aliases])));
    }

    public function matches(string $identifier): bool
    {
        return in_array(strtoupper($identifier), $this->identifiers(), true);
    }

    public function sameAs(self $other): bool
    {
        return $this->package === $other->package
            && array_intersect($this->identifiers(), $other->identifiers()) !== [];
    }

    /**
     * Combines two reports of the same advisory: all identifiers and sources,
     * the higher known severity, and this report's other details when present.
     */
    public function mergedWith(self $other): self
    {
        $aliases = array_values(array_diff(
            array_unique([...$this->aliases, $other->id, ...$other->aliases]),
            [$this->id],
        ));
        $severity = $other->severity->rank() > $this->severity->rank() ? $other->severity : $this->severity;

        return new self(
            $this->package,
            $this->version,
            $this->id,
            $aliases,
            $this->title !== '' ? $this->title : $other->title,
            $severity,
            $this->link ?? $other->link,
            $this->affectedVersions ?? $other->affectedVersions,
            array_values(array_unique([...$this->sources, ...$other->sources])),
        );
    }

    /**
     * @return array{package: string, version: string, id: string, aliases: list<string>, title: string, severity: string, link: ?string, affected_versions: ?string, sources: list<string>}
     */
    public function toArray(): array
    {
        return [
            'package' => $this->package,
            'version' => $this->version,
            'id' => $this->id,
            'aliases' => $this->aliases,
            'title' => $this->title,
            'severity' => $this->severity->value,
            'link' => $this->link,
            'affected_versions' => $this->affectedVersions,
            'sources' => $this->sources,
        ];
    }
}
