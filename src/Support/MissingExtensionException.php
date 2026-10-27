<?php

declare(strict_types=1);

namespace VeyroPHP\Support;

use RuntimeException;
use VeyroPHP\Exception\VeyroPHPException;

/**
 * A feature needs a PHP extension that is not loaded. The message names the extension
 * and the feature, so the fix (enable it in php.ini, or install it) is clear.
 */
final class MissingExtensionException extends RuntimeException implements VeyroPHPException
{
    private function __construct(public readonly string $extension, string $message)
    {
        parent::__construct($message);
    }

    public static function for(string $extension, string $feature): self
    {
        return new self($extension, sprintf('%s needs the PHP extension ext-%s, which is not loaded. Enable it in php.ini (extension=%s) or install it, then restart PHP.', ucfirst($feature), $extension, $extension));
    }
}
