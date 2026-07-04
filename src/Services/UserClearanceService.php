<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Rivalex\Clearance\Exceptions\ClearanceProtectedResourceException;
use Rivalex\Clearance\Exceptions\ClearanceScopeViolationException;
use Rivalex\Clearance\Models\RoleMeta;
use Rivalex\Clearance\Models\UserContextPermissionOverride;
use Rivalex\Clearance\Models\UserRoleContext;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Traits\HasRoles;

class UserClearanceService
{
    /**
     * Assign a global (non-contextual) role to a user via Spatie.
     *
     * @throws ClearanceScopeViolationException if the role's declared scope is contextual.
     */
    public function assignGlobalRole(Authenticatable $user, Role $role): void
    {
        $meta = RoleMeta::where('role_id', $role->id)->first();

        if ($meta?->isContextual()) {
            throw new ClearanceScopeViolationException(
                "Role [{$role->name}] is contextual; it cannot be assigned globally."
            );
        }

        /** @var HasRoles $user */
        $user->assignRole($role);
    }

    /**
     * Remove a global role from user and revoke all direct permissions for that guard.
     */
    public function removeGlobalRole(Authenticatable $user, Role $role): void
    {
        /** @var HasRoles&Model $user */
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
     *
     * @throws ClearanceScopeViolationException if the role is global-only or context type not allowed.
     */
    public function assignContextual(Authenticatable $user, Role $role, Model $context): UserRoleContext
    {
        $meta = RoleMeta::where('role_id', $role->id)->first();
        $contextClass = get_class($context);

        if ($meta?->isGlobal()) {
            throw new ClearanceScopeViolationException(
                "Role [{$role->name}] is global; it cannot be assigned contextually."
            );
        }

        if ($meta !== null && ! $meta->acceptsContext($contextClass)) {
            throw new ClearanceScopeViolationException(
                "Role [{$role->name}] does not accept context type [{$contextClass}]."
            );
        }

        return UserRoleContext::firstOrCreate([
            'user_id' => $user->getAuthIdentifier(),
            'role_id' => $role->id,
            'context_type' => $contextClass,
            'context_id' => $context->getKey(),
        ]);
    }

    /**
     * Remove a contextual binding AND cascade-delete its UserContextPermissionOverrides.
     */
    public function removeContextual(Authenticatable $user, Role $role, Model $context): void
    {
        $userId = $user->getAuthIdentifier();
        $roleId = (int) $role->id;
        $contextType = get_class($context);
        $contextId = $context->getKey();

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
     * Security ceiling (F2 - security audit): $actor must not modify their own direct
     * permissions unless they are super_admin, and may only grant a permission they
     * themselves already hold. Built-in clearance-* permissions can never be granted
     * this way (they are managed exclusively via roles/install command).
     *
     * @param  array<int>  $permissionIds
     *
     * @throws ClearanceScopeViolationException if $actor attempts to escalate their own
     *                                          or another user's privileges beyond their ceiling.
     * @throws ClearanceProtectedResourceException if a clearance-* permission is targeted.
     */
    public function syncGlobalExtraPermissions(
        Authenticatable $actor,
        Authenticatable $user,
        Role $role,
        array $permissionIds,
    ): void {
        /** @var HasRoles&Model $user */
        $rolePermIds = $role->permissions->pluck('id')->map(fn ($id) => (int) $id)->toArray();

        $currentDirectIds = $user->permissions
            ->where('guard_name', $role->guard_name)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->diff($rolePermIds)
            ->values()
            ->toArray();

        $desiredIds = array_map('intval', $permissionIds);
        $addedIds = array_diff($desiredIds, $currentDirectIds);
        $removedIds = array_diff($currentDirectIds, $desiredIds);

        $this->assertWithinCeiling($actor, $user, array_map(
            static fn (int $id): ?Permission => Permission::find($id),
            $addedIds,
        ));

        foreach ($addedIds as $permId) {
            $perm = Permission::find($permId);
            if ($perm !== null) {
                $user->givePermissionTo($perm);
            }
        }

        foreach ($removedIds as $permId) {
            $perm = Permission::find($permId);
            if ($perm !== null) {
                $user->revokePermissionTo($perm);
            }
        }
    }

    /**
     * Enforces the F2 privilege ceiling for a direct-permission grant: the actor cannot
     * touch their own direct permissions unless super_admin, cannot grant a permission
     * they do not themselves hold, and cannot grant a built-in clearance-* permission.
     *
     * @param  array<int, Permission|null>  $permissionsBeingGranted
     */
    private function assertWithinCeiling(Authenticatable $actor, Authenticatable $user, array $permissionsBeingGranted): void
    {
        $actorIsSuperAdmin = method_exists($actor, 'hasRole') && $actor->hasRole('super_admin');

        $isSelf = (string) $actor->getAuthIdentifier() === (string) $user->getAuthIdentifier();
        if ($isSelf && ! $actorIsSuperAdmin) {
            throw new ClearanceScopeViolationException(
                'Only super_admin may modify their own direct permissions.'
            );
        }

        foreach ($permissionsBeingGranted as $permission) {
            if ($permission === null) {
                continue;
            }

            if (str_starts_with($permission->name, 'clearance-')) {
                throw new ClearanceProtectedResourceException(
                    "Permission [{$permission->name}] is a built-in clearance permission and cannot be granted directly."
                );
            }

            $actorHasIt = method_exists($actor, 'hasPermissionTo') && $actor->hasPermissionTo($permission);
            if (! $actorIsSuperAdmin && ! $actorHasIt) {
                throw new ClearanceScopeViolationException(
                    "Cannot grant permission [{$permission->name}]: you do not hold it yourself."
                );
            }
        }
    }

    /**
     * Sync per-context permission overrides (forced_on) for a user-role-context tuple.
     * Upserts forced_on rows for checked permissions; deletes rows for unchecked.
     *
     * Security ceiling (F2 - security audit): same rules as syncGlobalExtraPermissions()
     * apply to the permissions being force-granted (forced_off removals carry no
     * escalation risk and are always allowed).
     *
     * @param  array<int>  $permissionIds  IDs of permissions to force on.
     *
     * @throws ClearanceScopeViolationException if $actor attempts to escalate their own
     *                                          or another user's privileges beyond their ceiling.
     * @throws ClearanceProtectedResourceException if a clearance-* permission is targeted.
     */
    public function syncContextualExtraPermissions(
        Authenticatable $actor,
        Authenticatable $user,
        Role $role,
        Model $context,
        array $permissionIds,
    ): void {
        $userId = $user->getAuthIdentifier();
        $roleId = (int) $role->id;
        $contextType = get_class($context);
        $contextId = $context->getKey();

        $desiredIds = array_map('intval', $permissionIds);

        $currentIds = UserContextPermissionOverride::forSubject($userId, $roleId, $contextType, $contextId)
            ->where('type', UserContextPermissionOverride::TYPE_FORCED_ON)
            ->pluck('permission_id')
            ->map(fn ($id) => (int) $id)
            ->toArray();

        $addedIds = array_diff($desiredIds, $currentIds);

        $this->assertWithinCeiling($actor, $user, array_map(
            static fn (int $id): ?Permission => Permission::find($id),
            $addedIds,
        ));

        // Delete all existing overrides for this tuple, then re-insert desired ones.
        UserContextPermissionOverride::forSubject($userId, $roleId, $contextType, $contextId)->delete();

        foreach ($desiredIds as $permId) {
            UserContextPermissionOverride::create([
                'user_id' => $userId,
                'role_id' => $roleId,
                'permission_id' => $permId,
                'context_type' => $contextType,
                'context_id' => $contextId,
                'type' => UserContextPermissionOverride::TYPE_FORCED_ON,
            ]);
        }
    }
}
