<?php

declare(strict_types=1);

namespace VeyroPHP\Validation;

use LogicException;
use VeyroPHP\Exception\VeyroPHPException;

/**
 * A rule definition is wrong (a programming error, not invalid user input).
 */
final class InvalidRuleException extends LogicException implements VeyroPHPException
{
    public static function unknown(string $rule): self
    {
        return new self(sprintf('Unknown validation rule "%s".', $rule));
    }

    public static function badParameters(string $rule, string $expected): self
    {
        return new self(sprintf('Validation rule "%s" expects %s.', $rule, $expected));
    }
}
