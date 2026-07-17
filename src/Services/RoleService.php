<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use InvalidArgumentException;
use Rivalex\Clearance\Exceptions\ClearanceProtectedResourceException;
use Rivalex\Clearance\Exceptions\ClearanceScopeViolationException;
use Rivalex\Clearance\Models\Permission;
use Rivalex\Clearance\Models\RoleMeta;
use Rivalex\Clearance\Models\UserRoleContext;
use Spatie\Permission\Models\Permission as SpatiePermission;
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
        $role = Role::create([
            'name' => $name,
            'guard_name' => $guardName,
        ]);

        if (! $role instanceof Role) {
            throw new \LogicException('Expected instance of '.Role::class.'.');
        }

        return $role;
    }

    /**
     * Renames an existing role.
     *
     * @throws ClearanceProtectedResourceException if the role is super_admin.
     */
    public function rename(Role $role, string $name): Role
    {
        if ($role->name === 'super_admin') {
            throw new ClearanceProtectedResourceException(
                'The super_admin role cannot be renamed.'
            );
        }

        if (RoleMeta::where('role_id', $role->id)->value('is_locked')) {
            throw new ClearanceProtectedResourceException(
                'This role is locked and cannot be renamed.'
            );
        }

        $role->update(['name' => $name]);

        return $role->fresh();
    }

    /**
     * Deletes a role.
     *
     * @throws ClearanceProtectedResourceException if the role is locked.
     */
    public function delete(Role $role): void
    {
        if ($role->name === 'super_admin') {
            throw new ClearanceProtectedResourceException(
                'The super_admin role cannot be deleted.'
            );
        }

        if (RoleMeta::where('role_id', $role->id)->value('is_locked')) {
            throw new ClearanceProtectedResourceException(
                'This role is locked and cannot be deleted.'
            );
        }

        // App-side guarantee: null-out parent_role_id on children before deletion
        // (belt-and-suspenders alongside ON DELETE SET NULL on the FK).
        RoleMeta::where('parent_role_id', $role->id)->update(['parent_role_id' => null]);

        $role->delete();
    }

    /**
     * Syncs permissions for a role via PermissionService (V8 - single write path).
     * Only permissions sharing the role's guard_name are accepted.
     * When the role has a ceiling parent (parent_role_id set), incoming permissions are
     * silently filtered to only those within the parent's ceiling (I1 — no exception).
     * After syncing, any removed permissions are cascaded to child roles (I1 cascade).
     *
     * Security ceiling (F9 - security audit): $actor must not add permissions to a role
     * they themselves currently hold unless they are super_admin (self-escalation via
     * role editing), may only add a permission they themselves already hold, and can
     * never add a clearance-* permission to ANY role unless super_admin. Removals carry
     * no escalation risk and are never restricted by the actor ceiling.
     *
     * @param  array<int, Permission>  $permissions
     *
     * @throws InvalidArgumentException when a permission guard does not match the role guard
     * @throws ClearanceScopeViolationException if $actor attempts to escalate beyond their ceiling
     * @throws ClearanceProtectedResourceException if a clearance-* permission is targeted
     */
    public function syncPermissions(Authenticatable $actor, Role $role, array $permissions): void
    {
        foreach ($permissions as $permission) {
            if ($permission->guard_name !== $role->guard_name) {
                throw new InvalidArgumentException(
                    "Permission '{$permission->name}' guard '{$permission->guard_name}' "
                    ."does not match role guard '{$role->guard_name}'.",
                );
            }
        }

        // I1 (child write): silently drop permissions outside the parent's ceiling.
        $parentRoleId = RoleMeta::where('role_id', $role->id)->value('parent_role_id');
        if ($parentRoleId !== null) {
            $parentRole = Role::find($parentRoleId);
            if ($parentRole !== null) {
                $ceilingIds = $parentRole->permissions->pluck('id')->flip()->all();
                $permissions = array_filter(
                    $permissions,
                    fn (Permission $p) => isset($ceilingIds[$p->id]),
                );
            }
        }

        // Spatie's permissions() relation resolves its related model from config() at
        // runtime, so static analysis only knows the base Eloquent Model type - narrow it
        // to the real Spatie Permission contract via whereInstanceOf (zero-op at runtime,
        // every row already is one).
        $current = $role->permissions->keyBy('id')->whereInstanceOf(SpatiePermission::class);
        $desired = collect($permissions)->keyBy('id');

        // clearance-* permissions on super_admin are immutable via the panel — absolute,
        // regardless of actor (even a super_admin actor cannot touch these here).
        if ($role->name === 'super_admin') {
            $toAdd = $desired->filter(fn (Permission $p) => ! $current->has($p->id));
            $toRemove = $current->filter(fn (SpatiePermission $p) => ! $desired->has($p->id));

            $blocked = $toAdd->merge($toRemove)->first(
                fn (SpatiePermission $p) => str_starts_with($p->name, 'clearance-')
            );

            if ($blocked !== null) {
                throw new ClearanceProtectedResourceException(
                    "Permission [{$blocked->name}] is a built-in clearance permission and cannot be added or removed from the super_admin role."
                );
            }
        }

        $addedPermissions = $desired->filter(fn ($p) => ! $current->has($p->id))->values()->all();
        $this->assertRoleSyncWithinCeiling($actor, $role, $addedPermissions);

        foreach ($desired as $id => $permission) {
            if (! $current->has($id)) {
                $this->permissions->assignToRole($role, $permission);
            }
        }

        $removedPermissions = [];
        foreach ($current as $id => $permission) {
            if (! $desired->has($id)) {
                $this->permissions->revokeFromRole($role, $permission);
                $removedPermissions[] = $permission;
            }
        }

        // Cascade removals to child roles automatically.
        if (! empty($removedPermissions)) {
            $this->cascadeToChildren($role, $removedPermissions);
        }
    }

    /**
     * Enforces the F9 privilege ceiling for a role-permission sync: the actor cannot add
     * permissions to a role they themselves currently hold (global or contextual) unless
     * super_admin, cannot add a permission they do not themselves hold, and cannot add
     * any clearance-* permission to any role - unless they are super_admin.
     *
     * @param  array<int, Permission>  $addedPermissions
     */
    private function assertRoleSyncWithinCeiling(Authenticatable $actor, Role $role, array $addedPermissions): void
    {
        if (empty($addedPermissions)) {
            return;
        }

        $actorIsSuperAdmin = method_exists($actor, 'hasRole') && $actor->hasRole('super_admin');

        if ($actorIsSuperAdmin) {
            return;
        }

        $actorHoldsRole = (method_exists($actor, 'hasRole') && $actor->hasRole($role->name))
            || UserRoleContext::where('user_id', $actor->getAuthIdentifier())
                ->where('role_id', $role->id)
                ->exists();

        if ($actorHoldsRole) {
            throw new ClearanceScopeViolationException(
                "Only super_admin may add permissions to role [{$role->name}] while holding it themselves."
            );
        }

        foreach ($addedPermissions as $permission) {
            if (str_starts_with($permission->name, 'clearance-')) {
                throw new ClearanceProtectedResourceException(
                    "Permission [{$permission->name}] is a built-in clearance permission and cannot be added to any role via the panel."
                );
            }

            $actorHasIt = method_exists($actor, 'hasPermissionTo') && $actor->hasPermissionTo($permission);
            if (! $actorHasIt) {
                throw new ClearanceScopeViolationException(
                    "Cannot add permission [{$permission->name}] to role [{$role->name}]: you do not hold it yourself."
                );
            }
        }
    }

    /**
     * Assigns a ceiling (parent) role to a child role.
     * Silently trims child permissions that exceed the parent ceiling (I1).
     *
     * @throws ClearanceScopeViolationException if the child is already a parent (I2)
     *                                          or the parent is already a child (I3).
     */
    public function setParent(Role $child, Role $parent): void
    {
        $childMeta = RoleMeta::firstOrNew(['role_id' => $child->id]);
        $parentMeta = RoleMeta::where('role_id', $parent->id)->first();

        // I2: child cannot itself be a parent.
        if ($childMeta->exists && $childMeta->isParent()) {
            throw new ClearanceScopeViolationException(
                "Role [{$child->name}] already acts as a parent and cannot become a child."
            );
        }

        // I3: parent cannot itself be a child.
        if ($parentMeta !== null && $parentMeta->isChild()) {
            throw new ClearanceScopeViolationException(
                "Role [{$parent->name}] is already a child role and cannot act as a parent."
            );
        }

        // Persist the ceiling link.
        $childMeta->parent_role_id = $parent->id;
        $childMeta->save();

        // I1 silent trim: revoke child permissions not in the parent ceiling.
        $ceilingIds = $parent->permissions->pluck('id')->flip()->all();
        $childFresh = $child->fresh();
        foreach ($childFresh->permissions->whereInstanceOf(SpatiePermission::class) as $perm) {
            if (! isset($ceilingIds[$perm->id])) {
                $this->permissions->revokeFromRole($childFresh, $perm);
            }
        }
    }

    /**
     * Detaches the ceiling role; the child becomes independent and keeps its current permissions.
     */
    public function removeParent(Role $child): void
    {
        RoleMeta::where('role_id', $child->id)->update(['parent_role_id' => null]);
    }

    /**
     * Removes the given permissions from every child role of this parent (automatic cascade).
     *
     * @param  array<int, SpatiePermission>  $removedPermissions
     */
    private function cascadeToChildren(Role $parent, array $removedPermissions): void
    {
        if (empty($removedPermissions)) {
            return;
        }

        $removedIds = collect($removedPermissions)->pluck('id')->flip()->all();

        $childMetas = RoleMeta::where('parent_role_id', $parent->id)->get();
        foreach ($childMetas as $childMeta) {
            $childRole = Role::find($childMeta->role_id);
            if ($childRole === null) {
                continue;
            }
            foreach ($childRole->permissions->whereInstanceOf(SpatiePermission::class) as $perm) {
                if (isset($removedIds[$perm->id])) {
                    $this->permissions->revokeFromRole($childRole, $perm);
                }
            }
        }
    }
}
