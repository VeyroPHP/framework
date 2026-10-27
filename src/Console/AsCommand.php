<?php

declare(strict_types=1);

namespace VeyroPHP\Console;

use Attribute;

/**
 * Names a console command. Read with reflection, so listing commands does not
 * construct them (or their dependencies, such as a database connection).
 *
 *     #[AsCommand('migrate:status', 'Show which migrations have run')]
 *     final class MigrateStatusCommand extends Command { ... }
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class AsCommand
{
    public function __construct(
        public readonly string $name,
        public readonly string $description = '',
    ) {}
}
