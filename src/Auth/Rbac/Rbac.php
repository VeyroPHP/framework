<?php

declare(strict_types=1);

namespace VeyroPHP\Auth\Rbac;

use VeyroPHP\Database\Connection\Connection;
use VeyroPHP\Orm\Model;

/**
 * Role-based access control: roles, permissions, and per-user overrides.
 *
 * Effective permissions of a user =
 *     permissions of all their roles
 *   + permissions granted to the user directly
 *   − permissions denied to the user directly (a denial beats every grant)
 * Users with the super-admin role pass every check, including denied ones.
 *
 * Results are memoized per user for the lifetime of this object (one request
 * when registered as scoped) and cleared when that user's assignments change.
 */
final class Rbac
{
    private const NAME = '/^[a-z0-9][a-z0-9._-]*$/D';

    /** @var array<string, array{roles: list<string>, permissions: list<string>}> */
    private array $memo = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly string $superAdminRole = 'super-admin',
    ) {}

    // Checks

    public function can(Model|int|string $user, string $permission): bool
    {
        $access = $this->access($user);

        return in_array($this->superAdminRole, $access['roles'], true) || in_array($permission, $access['permissions'], true);
    }

    /**
     * True when the user has at least one of the permissions.
     *
     * @param list<string> $permissions
     */
    public function canAny(Model|int|string $user, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->can($user, $permission)) {
                return true;
            }
        }

        return false;
    }

    public function hasRole(Model|int|string $user, string $role): bool
    {
        return in_array($role, $this->access($user)['roles'], true);
    }

    /**
     * @return list<string>
     */
    public function roles(Model|int|string $user): array
    {
        return $this->access($user)['roles'];
    }

    /**
     * Effective permissions (not expanded for super-admins).
     *
     * @return list<string>
     */
    public function permissions(Model|int|string $user): array
    {
        return $this->access($user)['permissions'];
    }

    // Management

    public function createRole(string $name, ?string $label = null): int|string
    {
        return $this->createNamed('roles', 'role', $name, $label);
    }

    public function createPermission(string $name, ?string $label = null): int|string
    {
        return $this->createNamed('permissions', 'permission', $name, $label);
    }

    public function givePermissionToRole(string $role, string ...$permissions): void
    {
        $roleId = $this->idOf('roles', 'role', $role);

        foreach ($permissions as $permission) {
            $permissionId = $this->idOf('permissions', 'permission', $permission);
            $exists = $this->connection->table('permission_role')->where('role_id', $roleId)->where('permission_id', $permissionId)->exists();

            if (!$exists) {
                $this->connection->table('permission_role')->insert(['role_id' => $roleId, 'permission_id' => $permissionId]);
            }
        }

        $this->memo = [];
    }

    public function revokePermissionFromRole(string $role, string ...$permissions): void
    {
        $roleId = $this->idOf('roles', 'role', $role);

        foreach ($permissions as $permission) {
            $this->connection->table('permission_role')
                ->where('role_id', $roleId)
                ->where('permission_id', $this->idOf('permissions', 'permission', $permission))
                ->delete();
        }

        $this->memo = [];
    }

    public function assignRole(Model|int|string $user, string ...$roles): void
    {
        $userId = self::userId($user);

        foreach ($roles as $role) {
            $roleId = $this->idOf('roles', 'role', $role);

            if (!$this->connection->table('role_user')->where('role_id', $roleId)->where('user_id', $userId)->exists()) {
                $this->connection->table('role_user')->insert(['role_id' => $roleId, 'user_id' => $userId]);
            }
        }

        unset($this->memo[(string) $userId]);
    }

    public function removeRole(Model|int|string $user, string ...$roles): void
    {
        $userId = self::userId($user);

        foreach ($roles as $role) {
            $this->connection->table('role_user')->where('role_id', $this->idOf('roles', 'role', $role))->where('user_id', $userId)->delete();
        }

        unset($this->memo[(string) $userId]);
    }

    /**
     * Gives one user a permission regardless of their roles.
     */
    public function grant(Model|int|string $user, string $permission): void
    {
        $this->setOverride($user, $permission, true);
    }

    /**
     * Takes a permission away from one user even if a role grants it.
     */
    public function deny(Model|int|string $user, string $permission): void
    {
        $this->setOverride($user, $permission, false);
    }

    /**
     * Removes a per-user grant or denial; the user's roles decide again.
     */
    public function clearOverride(Model|int|string $user, string $permission): void
    {
        $userId = self::userId($user);
        $this->connection->table('permission_user')
            ->where('user_id', $userId)
            ->where('permission_id', $this->idOf('permissions', 'permission', $permission))
            ->delete();

        unset($this->memo[(string) $userId]);
    }

    // Listing and bulk changes (used by admin screens)

    public function superAdminRole(): string
    {
        return $this->superAdminRole;
    }

    /**
     * @return list<array{name: string, label: string|null}> ordered by name
     */
    public function allRoles(): array
    {
        return self::namedRows($this->connection->table('roles')->select('name', 'label')->orderBy('name')->get());
    }

    /**
     * @return list<array{name: string, label: string|null}> ordered by name
     */
    public function allPermissions(): array
    {
        return self::namedRows($this->connection->table('permissions')->select('name', 'label')->orderBy('name')->get());
    }

    /**
     * Every role with its permission names, in one query.
     *
     * @return array<string, list<string>> role name => permission names (sorted)
     */
    public function permissionsByRole(): array
    {
        $map = array_fill_keys(array_column($this->allRoles(), 'name'), []);

        foreach ($this->connection->table('permission_role')
            ->select('roles.name as role', 'permissions.name as permission')
            ->join('roles', 'roles.id', '=', 'permission_role.role_id')
            ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->orderBy('permissions.name')
            ->get() as $row) {
            if (is_string($row['role'] ?? null) && is_string($row['permission'] ?? null)) {
                $map[$row['role']][] = $row['permission'];
            }
        }

        return $map;
    }

    /**
     * Makes the role have exactly these permissions.
     *
     * @param list<string> $permissions
     */
    public function syncRolePermissions(string $role, array $permissions): void
    {
        $this->connection->transaction(function () use ($role, $permissions): void {
            $roleId = $this->idOf('roles', 'role', $role);
            $ids = array_map(fn(string $p): int|string => $this->idOf('permissions', 'permission', $p), array_values(array_unique($permissions)));

            $this->connection->table('permission_role')->where('role_id', $roleId)->delete();
            if ($ids !== []) {
                $this->connection->table('permission_role')->insert(array_map(static fn(int|string $id): array => ['role_id' => $roleId, 'permission_id' => $id], $ids));
            }
        });

        $this->memo = [];
    }

    /**
     * Makes the user have exactly these roles.
     *
     * @param list<string> $roles
     */
    public function syncRoles(Model|int|string $user, array $roles): void
    {
        $userId = self::userId($user);

        $this->connection->transaction(function () use ($userId, $roles): void {
            $ids = array_map(fn(string $r): int|string => $this->idOf('roles', 'role', $r), array_values(array_unique($roles)));

            $this->connection->table('role_user')->where('user_id', $userId)->delete();
            if ($ids !== []) {
                $this->connection->table('role_user')->insert(array_map(static fn(int|string $id): array => ['role_id' => $id, 'user_id' => $userId], $ids));
            }
        });

        unset($this->memo[(string) $userId]);
    }

    /**
     * The user's personal grants (true) and denials (false).
     *
     * @return array<string, bool> permission name => allowed
     */
    public function overrides(Model|int|string $user): array
    {
        $overrides = [];

        foreach ($this->connection->table('permission_user')
            ->select('permissions.name', 'permission_user.allowed')
            ->join('permissions', 'permissions.id', '=', 'permission_user.permission_id')
            ->where('permission_user.user_id', self::userId($user))
            ->orderBy('permissions.name')
            ->get() as $row) {
            if (is_string($row['name'] ?? null)) {
                $overrides[$row['name']] = in_array($row['allowed'] ?? null, [true, 1, '1', 't'], true);
            }
        }

        return $overrides;
    }

    /**
     * Deletes a role and its links to users and permissions. The super-admin
     * role cannot be deleted.
     */
    public function deleteRole(string $role): void
    {
        if ($role === $this->superAdminRole) {
            throw RbacException::protectedRole($role);
        }

        $roleId = $this->idOf('roles', 'role', $role);

        $this->connection->transaction(function () use ($roleId): void {
            // Explicit, so it also works where foreign keys are not enforced.
            $this->connection->table('permission_role')->where('role_id', $roleId)->delete();
            $this->connection->table('role_user')->where('role_id', $roleId)->delete();
            $this->connection->table('roles')->where('id', $roleId)->delete();
        });

        $this->memo = [];
    }

    public function countUsersWithRole(string $role): int
    {
        return $this->connection->table('role_user')->where('role_id', $this->idOf('roles', 'role', $role))->count();
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array{name: string, label: string|null}>
     */
    private static function namedRows(array $rows): array
    {
        $list = [];
        foreach ($rows as $row) {
            if (is_string($row['name'] ?? null)) {
                $list[] = ['name' => $row['name'], 'label' => is_string($row['label'] ?? null) ? $row['label'] : null];
            }
        }

        return $list;
    }

    /**
     * Creates missing roles and permissions and links them. Safe to run repeatedly;
     * it never removes anything.
     *
     *     ['roles' => ['admin' => 'Administrator'], 'permissions' => ['posts.edit' => null],
     *      'role_permissions' => ['admin' => ['posts.edit']]]
     *
     * The definition usually comes from a config file, so its shape is checked first: a
     * wrong shape throws RbacException naming the key, and nothing is written (SVC-13).
     *
     * @param array<mixed> $definition
     *
     * @throws RbacException
     */
    public function seed(array $definition): void
    {
        $definition = self::checkSeed($definition);

        $this->connection->transaction(function () use ($definition): void {
            foreach ($definition['roles'] as $name => $label) {
                $this->ensure('roles', 'role', $name, $label);
            }

            foreach ($definition['permissions'] as $name => $label) {
                $this->ensure('permissions', 'permission', $name, $label);
            }

            foreach ($definition['role_permissions'] as $role => $permissions) {
                $this->givePermissionToRole($role, ...$permissions);
            }
        });
    }

    /**
     * @return array{roles: list<string>, permissions: list<string>}
     */
    private function access(Model|int|string $user): array
    {
        $key = (string) self::userId($user);

        if (!isset($this->memo[$key])) {
            $this->preload([$user]);
        }

        return $this->memo[$key];
    }

    /**
     * Loads the roles and permissions of many users with three queries in total
     * (per 500 users) instead of three per user. Later can(), roles() and
     * permissions() calls for these users run no queries. Use it before listing
     * users with their roles.
     *
     * @param iterable<Model|int|string> $users
     */
    public function preload(iterable $users): void
    {
        $ids = [];
        foreach ($users as $user) {
            $id = self::userId($user);
            if (!isset($this->memo[(string) $id])) {
                $ids[(string) $id] = $id;
            }
        }

        foreach (array_chunk(array_values($ids), 500) as $chunk) {
            $empty = array_fill_keys(array_map('strval', $chunk), []);
            $roles = $fromRoles = $granted = $denied = $empty;

            foreach ($this->connection->table('roles')
                ->select('roles.name', 'role_user.user_id')
                ->join('role_user', 'role_user.role_id', '=', 'roles.id')
                ->whereIn('role_user.user_id', $chunk)
                ->get() as $row) {
                self::collect($roles, $row);
            }

            foreach ($this->connection->table('permissions')
                ->select('permissions.name', 'role_user.user_id')
                ->join('permission_role', 'permission_role.permission_id', '=', 'permissions.id')
                ->join('role_user', 'role_user.role_id', '=', 'permission_role.role_id')
                ->whereIn('role_user.user_id', $chunk)
                ->get() as $row) {
                self::collect($fromRoles, $row);
            }

            foreach ($this->connection->table('permissions')
                ->select('permissions.name', 'permission_user.user_id', 'permission_user.allowed')
                ->join('permission_user', 'permission_user.permission_id', '=', 'permissions.id')
                ->whereIn('permission_user.user_id', $chunk)
                ->get() as $row) {
                if (in_array($row['allowed'] ?? null, [true, 1, '1', 't'], true)) {
                    self::collect($granted, $row);
                } else {
                    self::collect($denied, $row);
                }
            }

            foreach (array_keys($empty) as $key) {
                $userRoles = array_values(array_unique($roles[$key]));
                $permissions = array_values(array_diff(array_unique([...$fromRoles[$key], ...$granted[$key]]), $denied[$key]));
                sort($userRoles);
                sort($permissions);
                $this->memo[$key] = ['roles' => $userRoles, 'permissions' => $permissions];
            }
        }
    }

    /**
     * @param array<string, list<string>> $byUser
     * @param array<string, mixed> $row
     */
    private static function collect(array &$byUser, array $row): void
    {
        $name = $row['name'] ?? null;
        $userId = $row['user_id'] ?? null;

        if (is_string($name) && (is_int($userId) || is_string($userId)) && isset($byUser[(string) $userId])) {
            $byUser[(string) $userId][] = $name;
        }
    }

    private function setOverride(Model|int|string $user, string $permission, bool $allowed): void
    {
        $userId = self::userId($user);
        $permissionId = $this->idOf('permissions', 'permission', $permission);
        $query = $this->connection->table('permission_user')->where('user_id', $userId)->where('permission_id', $permissionId);

        if ($query->exists()) {
            $this->connection->table('permission_user')->where('user_id', $userId)->where('permission_id', $permissionId)->update(['allowed' => $allowed]);
        } else {
            $this->connection->table('permission_user')->insert(['user_id' => $userId, 'permission_id' => $permissionId, 'allowed' => $allowed]);
        }

        unset($this->memo[(string) $userId]);
    }

    private function createNamed(string $table, string $kind, string $name, ?string $label): int|string
    {
        self::assertName($kind, $name);
        $now = date('Y-m-d H:i:s');

        return $this->connection->table($table)->insertGetId(['name' => $name, 'label' => $label, 'created_at' => $now, 'updated_at' => $now]);
    }

    private function ensure(string $table, string $kind, string $name, ?string $label): void
    {
        self::assertName($kind, $name);

        if (!$this->connection->table($table)->where('name', $name)->exists()) {
            $this->createNamed($table, $kind, $name, $label);
        }
    }

    private function idOf(string $table, string $kind, string $name): int|string
    {
        self::assertName($kind, $name);
        $id = $this->connection->table($table)->where('name', $name)->value('id');

        return is_int($id) || is_string($id) ? $id : throw RbacException::unknown($kind, $name);
    }

    /**
     * @param array<mixed> $definition
     *
     * @return array{roles: array<string, string|null>, permissions: array<string, string|null>, role_permissions: array<string, list<string>>}
     */
    private static function checkSeed(array $definition): array
    {
        $unknown = array_diff(array_keys($definition), ['roles', 'permissions', 'role_permissions']);

        if ($unknown !== []) {
            throw RbacException::invalidSeed((string) reset($unknown), 'only "roles", "permissions" and "role_permissions"');
        }

        $checked = ['roles' => [], 'permissions' => [], 'role_permissions' => []];

        foreach (['roles', 'permissions'] as $section) {
            $entries = $definition[$section] ?? [];

            if (!is_array($entries)) {
                throw RbacException::invalidSeed($section, 'an array of name => label');
            }

            foreach ($entries as $name => $label) {
                if (!is_string($name)) {
                    throw RbacException::invalidSeed($section, 'names as keys: ["admin" => "Administrator"], not a list');
                }

                if ($label !== null && !is_string($label)) {
                    throw RbacException::invalidSeed($section . '.' . $name, 'a label string or null');
                }

                $checked[$section][$name] = $label;
            }
        }

        $links = $definition['role_permissions'] ?? [];

        if (!is_array($links)) {
            throw RbacException::invalidSeed('role_permissions', 'an array of role => list of permissions');
        }

        foreach ($links as $role => $permissions) {
            if (!is_string($role)) {
                throw RbacException::invalidSeed('role_permissions', 'role names as keys');
            }

            if (!is_array($permissions) || !array_is_list($permissions) || array_filter($permissions, 'is_string') !== $permissions) {
                throw RbacException::invalidSeed('role_permissions.' . $role, 'a list of permission names');
            }

            $checked['role_permissions'][$role] = $permissions;
        }

        return $checked;
    }

    private static function assertName(string $kind, string $name): void
    {
        if (preg_match(self::NAME, $name) !== 1 || strlen($name) > 150) {
            throw RbacException::invalidName($kind, $name);
        }
    }

    private static function userId(Model|int|string $user): int|string
    {
        if (!$user instanceof Model) {
            return $user;
        }

        return $user->exists() ? ($user->getKey() ?? throw RbacException::notPersisted()) : throw RbacException::notPersisted();
    }
}
