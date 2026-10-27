<?php

declare(strict_types=1);

namespace VeyroPHP\Ai;

/**
 * The model's answer and what it cost in tokens.
 */
final class Response
{
    public function __construct(
        public readonly string $text,
        public readonly string $model,
        public readonly ?string $stopReason,
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
    ) {}

    /**
     * The model declined to answer (for example for safety reasons).
     */
    public function refused(): bool
    {
        return $this->stopReason === 'refusal';
    }

    /**
     * The answer was cut off by the token limit.
     */
    public function truncated(): bool
    {
        return $this->stopReason === 'max_tokens';
    }
}
