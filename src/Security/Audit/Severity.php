<?php

declare(strict_types=1);

namespace VeyroPHP\Security\Audit;

/**
 * Advisory severity as published by the source. "unknown" means the source
 * gave none; policies treat it as failing because its impact is not known.
 */
enum Severity: string
{
    case Unknown = 'unknown';
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';

    public static function parse(mixed $value): self
    {
        return match (is_string($value) ? strtolower(trim($value)) : '') {
            'low' => self::Low,
            'medium', 'moderate' => self::Medium,
            'high' => self::High,
            'critical' => self::Critical,
            default => self::Unknown,
        };
    }

    public function rank(): int
    {
        return match ($this) {
            self::Unknown => 0,
            self::Low => 1,
            self::Medium => 2,
            self::High => 3,
            self::Critical => 4,
        };
    }
}
