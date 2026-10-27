<?php

declare(strict_types=1);

namespace VeyroPHP\Logging;

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Stringable;

/**
 * Decorates any PSR-3 logger and redacts the context before passing it on.
 *
 * Use it to add redaction to third-party loggers. FileLogger already redacts.
 */
final class RedactingLogger extends AbstractLogger
{
    public function __construct(
        private readonly LoggerInterface $inner,
        private readonly Redactor $redactor = new Redactor(),
    ) {}

    /**
     * @param mixed $level
     * @param array<array-key, mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->inner->log($level, $message, $this->redactor->redact($context));
    }
}
