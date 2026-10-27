<?php

declare(strict_types=1);

namespace VeyroPHP\Port;

/**
 * The result of a porting analysis. It only describes the project: nothing
 * was converted, and counts are estimates from static reading of the code.
 */
final class Report
{
    public const SCHEMA = 1;

    public const NOTICE = 'Analysis only: the project was read, not run or changed, and nothing was converted. A port is complete when its tests and manual checks pass, not when the code parses.';

    /** @var list<Finding> */
    private array $findings = [];

    /** @var list<string> */
    private array $warnings = [];

    public function __construct(
        public readonly string $framework,
        public readonly ?string $version,
        public readonly string $root,
    ) {}

    public function add(Finding $finding): void
    {
        $this->findings[] = $finding;
    }

    public function warn(string $warning): void
    {
        $this->warnings[] = $warning;
    }

    /**
     * @return list<Finding>
     */
    public function findings(): array
    {
        return $this->findings;
    }

    /**
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * @return array{direct: int, manual: int, unsupported: int}
     */
    public function summary(): array
    {
        $summary = ['direct' => 0, 'manual' => 0, 'unsupported' => 0];

        foreach ($this->findings as $finding) {
            $summary[$finding->status->value]++;
        }

        return $summary;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA,
            'framework' => $this->framework,
            'version' => $this->version,
            'root' => $this->root,
            'notice' => self::NOTICE,
            'summary' => $this->summary(),
            'warnings' => $this->warnings,
            'findings' => array_map(static fn(Finding $f): array => $f->toArray(), $this->findings),
        ];
    }
}
