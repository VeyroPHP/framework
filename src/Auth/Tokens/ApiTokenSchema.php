<?php

declare(strict_types=1);

namespace VeyroPHP\Auth\Tokens;

use VeyroPHP\Database\Schema\Blueprint;
use VeyroPHP\Database\Schema\Schema;

/**
 * The personal_access_tokens table. Call it from a migration:
 *
 *     public function up(Schema $schema): void { ApiTokenSchema::up($schema); }
 *     public function down(Schema $schema): void { ApiTokenSchema::down($schema); }
 *
 * Only the SHA-256 of each secret is stored. Times are Unix timestamps.
 */
final class ApiTokenSchema
{
    public static function up(Schema $schema, string $table = 'personal_access_tokens', string $usersTable = 'users'): void
    {
        $schema->create($table, static function (Blueprint $t) use ($usersTable): void {
            $t->id();
            $t->foreignId('user_id')->constrained($usersTable)->cascadeOnDelete();
            $t->string('name', 100);
            $t->string('token_hash', 64)->unique();
            $t->text('abilities');
            $t->bigInteger('last_used_at')->nullable();
            $t->bigInteger('expires_at')->nullable();
            $t->bigInteger('created_at');
            $t->index('user_id');
        });
    }

    public static function down(Schema $schema, string $table = 'personal_access_tokens'): void
    {
        $schema->dropIfExists($table);
    }
}
