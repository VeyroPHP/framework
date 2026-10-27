<?php

declare(strict_types=1);

namespace VeyroPHP\Auth\Rbac;

use VeyroPHP\Database\Schema\Blueprint;
use VeyroPHP\Database\Schema\Schema;

/**
 * Creates and drops the RBAC tables. Call it from a migration:
 *
 *     public function up(Schema $schema): void { RbacSchema::up($schema); }
 *     public function down(Schema $schema): void { RbacSchema::down($schema); }
 *
 * Tables: roles, permissions, permission_role, role_user and permission_user
 * (per-user grants with allowed = true, denials with allowed = false).
 */
final class RbacSchema
{
    public static function up(Schema $schema, string $usersTable = 'users'): void
    {
        $schema->create('roles', static function (Blueprint $t): void {
            $t->id();
            $t->string('name', 100)->unique();
            $t->string('label')->nullable();
            $t->timestamps();
        });

        $schema->create('permissions', static function (Blueprint $t): void {
            $t->id();
            $t->string('name', 150)->unique();
            $t->string('label')->nullable();
            $t->timestamps();
        });

        $schema->create('permission_role', static function (Blueprint $t): void {
            $t->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();
            $t->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $t->unique(['permission_id', 'role_id']);
        });

        $schema->create('role_user', static function (Blueprint $t) use ($usersTable): void {
            $t->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained($usersTable)->cascadeOnDelete();
            $t->unique(['role_id', 'user_id']);
        });

        $schema->create('permission_user', static function (Blueprint $t) use ($usersTable): void {
            $t->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained($usersTable)->cascadeOnDelete();
            $t->boolean('allowed');
            $t->unique(['permission_id', 'user_id']);
        });
    }

    public static function down(Schema $schema): void
    {
        foreach (['permission_user', 'role_user', 'permission_role', 'permissions', 'roles'] as $table) {
            $schema->dropIfExists($table);
        }
    }
}
