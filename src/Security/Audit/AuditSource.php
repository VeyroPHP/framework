<?php

declare(strict_types=1);

namespace VeyroPHP\Security\Audit;

/**
 * A database of published advisories. Implementations only read the lock
 * file's package names and versions; they never install or run package code.
 * Errors are returned as a failed SourceResult, not thrown.
 */
interface AuditSource
{
    public function name(): string;

    public function scan(LockFile $lock, bool $includeDev): SourceResult;
}
