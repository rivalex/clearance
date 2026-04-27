<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Services;

use InvalidArgumentException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleService
{
    public function __construct(
        private readonly PermissionService $permissions,
    ) {}

    /**
     * Creates a new role for the given guard.
     */
    public function create(string $name, string $guardName): Role
    {
        return Role::create([
            'name' => $name,
            'guard_name' => $guardName,
        ]);
    }

    /**
     * Renames an existing role.
     */
    public function rename(Role $role, string $name): Role
    {
        $role->update(['name' => $name]);

        return $role->fresh();
    }

    /**
     * Deletes a role.
     */
    public function delete(Role $role): void
    {
        $role->delete();
    }

    /**
     * Syncs permissions for a role via PermissionService (V8 — single write path).
     * Only permissions sharing the role's guard_name are accepted.
     *
     * @param  array<int, Permission>  $permissions
     *
     * @throws InvalidArgumentException when a permission guard does not match the role guard
     */
    public function syncPermissions(Role $role, array $permissions): void
    {
        foreach ($permissions as $permission) {
            if ($permission->guard_name !== $role->guard_name) {
                throw new InvalidArgumentException(
                    "Permission '{$permission->name}' guard '{$permission->guard_name}' "
                    ."does not match role guard '{$role->guard_name}'.",
                );
            }
        }

        $current = $role->permissions->keyBy('id');
        $desired = collect($permissions)->keyBy('id');

        foreach ($desired as $id => $permission) {
            if (! $current->has($id)) {
                $this->permissions->assignToRole($role, $permission);
            }
        }

        foreach ($current as $id => $permission) {
            if (! $desired->has($id)) {
                $this->permissions->revokeFromRole($role, $permission);
            }
        }
    }
}
