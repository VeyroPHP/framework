<?php

declare(strict_types=1);

namespace VeyroPHP\Database\Migration;

use VeyroPHP\Database\Schema\Schema;

/**
 * A migration file returns an instance of a class extending this one:
 *
 *     // database/migrations/2026_10_26_000001_create_users_table.php
 *     return new class extends Migration {
 *         public function up(Schema $schema): void { ... }
 *         public function down(Schema $schema): void { ... }
 *     };
 *
 * down() must undo up() exactly, so rollback works.
 */
abstract class Migration
{
    abstract public function up(Schema $schema): void;

    abstract public function down(Schema $schema): void;
}
