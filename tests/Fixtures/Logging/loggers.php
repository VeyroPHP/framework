<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Fixtures\Logging;

use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

/**
 * Keeps log records in memory for assertions.
 */
final class ArrayLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<array-key, mixed>}> */
    public array $records = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }
}

/**
 * Fails on every call, to test behavior when logging is broken.
 */
final class FailingLogger extends AbstractLogger
{
    public function log($level, string|Stringable $message, array $context = []): void
    {
        throw new RuntimeException('disk full');
    }
}
