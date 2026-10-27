<?php

declare(strict_types=1);

namespace VeyroPHP\Security\Audit;

/**
 * What one audit source found, or why it could not finish. A failed source is
 * never read as "nothing found".
 */
final class SourceResult
{
    /**
     * @param list<Advisory> $advisories
     * @param array<string, string> $abandoned package => suggested replacement ('' when none)
     * @param list<string> $notChecked packages this source could not check, with the reason
     */
    private function __construct(
        public readonly string $source,
        public readonly ?string $error,
        public readonly array $advisories,
        public readonly array $abandoned,
        public readonly array $notChecked,
    ) {}

    /**
     * @param list<Advisory> $advisories
     * @param array<string, string> $abandoned
     * @param list<string> $notChecked
     */
    public static function ok(string $source, array $advisories, array $abandoned = [], array $notChecked = []): self
    {
        return new self($source, null, $advisories, $abandoned, $notChecked);
    }

    public static function failed(string $source, string $error): self
    {
        return new self($source, $error, [], [], []);
    }

    public function succeeded(): bool
    {
        return $this->error === null;
    }
}
