<?php

declare(strict_types=1);

namespace VeyroPHP\Database\Migration;

use Throwable;
use VeyroPHP\Database\Exception\DatabaseException;

final class MigrationException extends DatabaseException
{
    public static function invalidFileName(string $file): self
    {
        return new self(sprintf('Migration file "%s" must be named like 2026_10_26_000001_create_users_table.php.', $file));
    }

    public static function notAMigration(string $file, string $type): self
    {
        return new self(sprintf('Migration file "%s" must return a Migration instance, %s returned.', $file, $type));
    }

    public static function missingFile(string $migration): self
    {
        return new self(sprintf('Cannot roll back "%s": its migration file no longer exists.', $migration));
    }

    public static function directoryNotFound(string $directory): self
    {
        return new self(sprintf('Migrations directory "%s" does not exist.', $directory));
    }

    public static function failed(string $migration, string $direction, bool $rolledBack, Throwable $previous): self
    {
        return new self(
            sprintf(
                'Migration "%s" failed during %s: %s%s',
                $migration,
                $direction,
                $previous->getMessage(),
                $rolledBack ? ' (its changes were rolled back)' : ' (the database does not support transactional DDL; earlier statements of this migration may have been applied)',
            ),
            0,
            $previous,
        );
    }
}
