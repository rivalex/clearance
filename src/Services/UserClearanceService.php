<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Rivalex\Clearance\Exceptions\ClearanceConfigException;
use Rivalex\Clearance\Models\RoleHierarchy;
use Rivalex\Clearance\Models\UserContextPermissionOverride;
use Rivalex\Clearance\Models\UserRoleContext;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class UserClearanceService
{
    /**
     * Assign a global (non-contextual) role to a user via Spatie.
     */
    public function assignGlobalRole(Authenticatable $user, Role $role): void
    {
        /** @var \Spatie\Permission\Traits\HasRoles $user */
        $user->assignRole($role);
    }

    /**
     * Remove a global role from user and revoke all direct permissions for that guard.
     */
    public function removeGlobalRole(Authenticatable $user, Role $role): void
    {
        /** @var \Spatie\Permission\Traits\HasRoles&\Illuminate\Database\Eloquent\Model $user */
        $directPermNames = $user->permissions
            ->where('guard_name', $role->guard_name)
            ->pluck('name')
            ->toArray();

        $user->removeRole($role);

        if (! empty($directPermNames)) {
            $user->revokePermissionTo($directPermNames);
        }
    }

    /**
     * Create a UserRoleContext binding (user, role, context model instance).
     */
    public function assignContextual(Authenticatable $user, Role $role, Model $context): UserRoleContext
    {
        return UserRoleContext::firstOrCreate([
            'user_id'      => $user->getAuthIdentifier(),
            'role_id'      => $role->id,
            'context_type' => get_class($context),
            'context_id'   => $context->getKey(),
        ]);
    }

    /**
     * Remove a contextual binding AND cascade-delete its UserContextPermissionOverrides.
     */
    public function removeContextual(Authenticatable $user, Role $role, Model $context): void
    {
        $userId      = $user->getAuthIdentifier();
        $roleId      = (int) $role->id;
        $contextType = get_class($context);
        $contextId   = $context->getKey();

        UserContextPermissionOverride::forSubject($userId, $roleId, $contextType, $contextId)
            ->delete();

        UserRoleContext::where('user_id', $userId)
            ->where('role_id', $role->id)
            ->where('context_type', $contextType)
            ->where('context_id', $contextId)
            ->delete();
    }

    /**
     * Sync Spatie direct permissions for a user for a given role's guard (global scope).
     * Excludes permissions already granted by the role itself.
     *
     * @param  array<int>  $permissionIds
     */
    public function syncGlobalExtraPermissions(Authenticatable $user, Role $role, array $permissionIds): void
    {
        /** @var \Spatie\Permission\Traits\HasRoles&\Illuminate\Database\Eloquent\Model $user */
        $rolePermIds = $role->permissions->pluck('id')->map(fn ($id) => (int) $id)->toArray();

        $currentDirectIds = $user->permissions
            ->where('guard_name', $role->guard_name)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->diff($rolePermIds)
            ->values()
            ->toArray();

        $desiredIds = array_map('intval', $permissionIds);

        foreach (array_diff($desiredIds, $currentDirectIds) as $permId) {
            $perm = Permission::find($permId);
            if ($perm !== null) {
                $user->givePermissionTo($perm);
            }
        }

        foreach (array_diff($currentDirectIds, $desiredIds) as $permId) {
            $perm = Permission::find($permId);
            if ($perm !== null) {
                $user->revokePermissionTo($perm);
            }
        }
    }

    /**
     * Sync per-context permission overrides (forced_on) for a user-role-context tuple.
     * Upserts forced_on rows for checked permissions; deletes rows for unchecked.
     *
     * @param  array<int>  $permissionIds  IDs of permissions to force on.
     */
    public function syncContextualExtraPermissions(
        Authenticatable $user,
        Role $role,
        Model $context,
        array $permissionIds,
    ): void {
        $userId      = $user->getAuthIdentifier();
        $roleId      = (int) $role->id;
        $contextType = get_class($context);
        $contextId   = $context->getKey();

        $desiredIds = array_map('intval', $permissionIds);

        // Delete all existing overrides for this tuple, then re-insert desired ones.
        UserContextPermissionOverride::forSubject($userId, $roleId, $contextType, $contextId)->delete();

        foreach ($desiredIds as $permId) {
            UserContextPermissionOverride::create([
                'user_id'       => $userId,
                'role_id'       => $roleId,
                'permission_id' => $permId,
                'context_type'  => $contextType,
                'context_id'    => $contextId,
                'type'          => UserContextPermissionOverride::TYPE_FORCED_ON,
            ]);
        }
    }

    /**
     * Throw ClearanceConfigException if the role is a slave (child_role_id in any RoleHierarchy).
     *
     * @throws ClearanceConfigException
     */
    public function assertNotSlave(Role $role): void
    {
        $isSlave = RoleHierarchy::pluck('child_role_id')->contains((int) $role->id);

        if ($isSlave) {
            throw new ClearanceConfigException(
                "Role [{$role->name}] is a slave role and cannot be assigned directly.",
            );
        }
    }
}
