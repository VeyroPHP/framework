<?php

declare(strict_types=1);

namespace VeyroPHP\Ai;

/**
 * Per-call settings. Anything left null uses the provider's configured default.
 */
final class Options
{
    public const EFFORTS = ['low', 'medium', 'high', 'xhigh', 'max'];

    /**
     * @param string|null $system instructions for the model (kept apart from user input)
     * @param string|null $effort how much the model thinks: low, medium, high, xhigh or max
     */
    public function __construct(
        public readonly ?string $system = null,
        public readonly ?string $model = null,
        public readonly ?int $maxTokens = null,
        public readonly ?string $effort = null,
    ) {
        if ($maxTokens !== null && $maxTokens < 1) {
            throw new AiException('maxTokens must be at least 1.');
        }

        if ($effort !== null && !in_array($effort, self::EFFORTS, true)) {
            throw new AiException(sprintf('effort must be one of %s.', implode(', ', self::EFFORTS)));
        }
    }
}
