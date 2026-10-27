<?php

declare(strict_types=1);

namespace VeyroPHP\Http\Health;

final class HealthReport
{
    /**
     * @param array<string, array{ok: bool, ms: float, error: string|null}> $results
     */
    public function __construct(private readonly array $results) {}

    public function healthy(): bool
    {
        foreach ($this->results as $result) {
            if (!$result['ok']) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, array{ok: bool, ms: float, error: string|null}>
     */
    public function results(): array
    {
        return $this->results;
    }
}
