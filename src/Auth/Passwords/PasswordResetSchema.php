<?php

declare(strict_types=1);

namespace VeyroPHP\Auth\Passwords;

use VeyroPHP\Database\Schema\Blueprint;
use VeyroPHP\Database\Schema\Schema;

/**
 * The password_reset_tokens table. Call it from a migration:
 *
 *     public function up(Schema $schema): void { PasswordResetSchema::up($schema); }
 *     public function down(Schema $schema): void { PasswordResetSchema::down($schema); }
 *
 * "identifier" is the user's primary key; "token_hash" the SHA-256 of the
 * token (the token itself is never stored).
 */
final class PasswordResetSchema
{
    public static function up(Schema $schema, string $table = 'password_reset_tokens'): void
    {
        $schema->create($table, static function (Blueprint $t): void {
            $t->string('identifier', 100)->unique();
            $t->string('token_hash', 64);
            $t->bigInteger('created_at');
        });
    }

    public static function down(Schema $schema, string $table = 'password_reset_tokens'): void
    {
        $schema->dropIfExists($table);
    }
}
