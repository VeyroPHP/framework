<?php

declare(strict_types=1);

namespace VeyroPHP\Scheduling;

use RuntimeException;
use VeyroPHP\Exception\VeyroPHPException;

/**
 * An invalid schedule (cron expression, time, time zone) or a task that failed.
 */
final class ScheduleException extends RuntimeException implements VeyroPHPException {}
