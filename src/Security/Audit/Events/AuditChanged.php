<?php

declare(strict_types=1);

namespace VeyroPHP\Security\Audit\Events;

use VeyroPHP\Security\Audit\AuditDiff;
use VeyroPHP\Security\Audit\AuditReport;
use VeyroPHP\Security\Audit\PolicyResult;

/**
 * Dispatched by "security:audit --notify" when the result differs from the
 * previous saved report. Listen for it to send the change elsewhere (chat,
 * database notifications for admins, an issue tracker).
 */
final class AuditChanged
{
    public function __construct(
        public readonly AuditReport $report,
        public readonly PolicyResult $policy,
        public readonly AuditDiff $diff,
    ) {}
}
