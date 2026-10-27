<?php

declare(strict_types=1);

namespace VeyroPHP\Auth\Rbac;

use LogicException;
use VeyroPHP\Exception\VeyroPHPException;

final class RbacException extends LogicException implements VeyroPHPException
{
    public static function invalidName(string $kind, string $name): self
    {
        return new self(sprintf('Invalid %s name "%s": use lowercase letters, digits, ".", "_" and "-".', $kind, $name));
    }

    public static function unknown(string $kind, string $name): self
    {
        return new self(sprintf('Unknown %s "%s". Create it first (for example with the RBAC seeder).', $kind, $name));
    }

    public static function protectedRole(string $role): self
    {
        return new self(sprintf('The role "%s" cannot be deleted.', $role));
    }

    public static function invalidSeed(string $key, string $expected): self
    {
        return new self(sprintf('Invalid RBAC seed definition at "%s": expected %s (config/rbac.php, key "seed").', $key, $expected));
    }

    public static function notPersisted(): self
    {
        return new self('The user must be saved before roles or permissions can be assigned.');
    }
}
