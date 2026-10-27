<?php

declare(strict_types=1);

namespace VeyroPHP\Auth\Remember;

use VeyroPHP\Database\Schema\Blueprint;
use VeyroPHP\Database\Schema\Schema;

/**
 * Creates and drops the "remember me" token table. Call it from a migration:
 *
 *     public function up(Schema $schema): void { RememberSchema::up($schema); }
 *     public function down(Schema $schema): void { RememberSchema::down($schema); }
 *
 * One row per remembered browser. The validator is stored only as a SHA-256 hash, so
 * a leaked table does not log anyone in.
 */
final class RememberSchema
{
    public const TABLE = 'remember_tokens';

    public static function up(Schema $schema, string $table = self::TABLE): void
    {
        $schema->create($table, static function (Blueprint $t): void {
            $t->id();
            $t->string('user_id', 64)->index();
            $t->char('selector', 22)->unique();
            $t->char('validator_hash', 64);
            $t->char('password_fingerprint', 64);
            $t->dateTime('expires_at')->index();
            $t->dateTime('last_used_at')->nullable();
            $t->timestamps();
        });
    }

    public static function down(Schema $schema, string $table = self::TABLE): void
    {
        $schema->dropIfExists($table);
    }
}
