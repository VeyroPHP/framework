<?php

declare(strict_types=1);

namespace VeyroPHP\Support;

/**
 * @internal checks optional PHP extensions where a feature uses them (MISC-4). The
 * extensions every application needs are declared in composer.json instead.
 */
final class Extensions
{
    /**
     * @throws MissingExtensionException
     */
    public static function require(string $extension, string $feature): void
    {
        if (!extension_loaded($extension)) {
            throw MissingExtensionException::for($extension, $feature);
        }
    }
}
