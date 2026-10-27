<?php

declare(strict_types=1);

namespace VeyroPHP\Security\Audit;

final class PolicyResult
{
    /**
     * @param list<string> $failures findings that fail the policy
     * @param bool $incomplete a source failed and the policy does not allow that
     * @param list<array{id: string, package: string, reason: string, until: ?string}> $ignored
     * @param list<string> $expiredIgnores ignore rules that no longer apply
     */
    public function __construct(
        public readonly array $failures,
        public readonly bool $incomplete,
        public readonly array $ignored,
        public readonly array $expiredIgnores,
    ) {}

    public function passed(): bool
    {
        return $this->failures === [] && !$this->incomplete;
    }

    /**
     * @return array{passed: bool, failures: list<string>, incomplete: bool, ignored: list<array{id: string, package: string, reason: string, until: ?string}>, expired_ignores: list<string>}
     */
    public function toArray(): array
    {
        return [
            'passed' => $this->passed(),
            'failures' => $this->failures,
            'incomplete' => $this->incomplete,
            'ignored' => $this->ignored,
            'expired_ignores' => $this->expiredIgnores,
        ];
    }
}
