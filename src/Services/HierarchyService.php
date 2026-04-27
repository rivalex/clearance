<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Services;

use Rivalex\Clearance\Exceptions\ClearanceHierarchyViolationException;
use Rivalex\Clearance\Exceptions\ClearanceInvalidOverrideException;
use Rivalex\Clearance\Models\RoleHierarchy;
use Rivalex\Clearance\Models\RolePermissionOverride;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class HierarchyService
{
    /**
     * Creates a parent→child hierarchy relation (V3: single-level enforced).
     *
     * @throws ClearanceHierarchyViolationException
     */
    public function createRelation(Role $parent, Role $child): RoleHierarchy
    {
        // V3: child cannot already be a parent
        if (RoleHierarchy::where('parent_role_id', $child->id)->exists()) {
            throw new ClearanceHierarchyViolationException(
                "Role '{$child->name}' is already a parent and cannot become a child.",
            );
        }

        // V3: parent cannot already be a child
        if (RoleHierarchy::where('child_role_id', $parent->id)->exists()) {
            throw new ClearanceHierarchyViolationException(
                "Role '{$parent->name}' is already a child and cannot become a parent.",
            );
        }

        return RoleHierarchy::create([
            'parent_role_id' => $parent->id,
            'child_role_id'  => $child->id,
        ]);
    }

    /**
     * Deletes a hierarchy relation and all associated overrides.
     */
    public function deleteRelation(RoleHierarchy $hierarchy): void
    {
        RolePermissionOverride::where('parent_role_id', $hierarchy->parent_role_id)
            ->where('child_role_id', $hierarchy->child_role_id)
            ->delete();

        $hierarchy->delete();
    }

    /**
     * Adds a forced_on or forced_off override for a permission on a child role.
     * V2: forced_on is rejected if the parent does not possess the permission.
     *
     * @throws ClearanceInvalidOverrideException
     */
    public function addOverride(
        RoleHierarchy $hierarchy,
        Permission $permission,
        string $type,
    ): RolePermissionOverride {
        if ($type === RolePermissionOverride::TYPE_FORCED_ON) {
            $parent = $hierarchy->parentRole;
            if (! $parent->hasPermissionTo($permission)) {
                throw new ClearanceInvalidOverrideException(
                    "Cannot force on '{$permission->name}': "
                    . "parent role '{$parent->name}' does not have this permission (V2).",
                );
            }
        }

        return RolePermissionOverride::updateOrCreate(
            [
                'child_role_id' => $hierarchy->child_role_id,
                'permission_id' => $permission->id,
            ],
            [
                'parent_role_id' => $hierarchy->parent_role_id,
                'type'           => $type,
            ],
        );
    }

    /**
     * Removes an existing override.
     */
    public function removeOverride(RolePermissionOverride $override): void
    {
        $override->delete();
    }

    /**
     * Deletes all forced_on overrides for $permission on children of $parent (V9).
     * Call this whenever a parent role loses a permission.
     */
    public function cleanupForcedOnForPermission(Role $parent, Permission $permission): void
    {
        RolePermissionOverride::where('parent_role_id', $parent->id)
            ->where('permission_id', $permission->id)
            ->where('type', RolePermissionOverride::TYPE_FORCED_ON)
            ->delete();
    }

    /**
     * Returns true if the role has any child roles.
     */
    public function isParent(Role $role): bool
    {
        return RoleHierarchy::where('parent_role_id', $role->id)->exists();
    }

    /**
     * Returns true if the role has a parent role.
     */
    public function isChild(Role $role): bool
    {
        return RoleHierarchy::where('child_role_id', $role->id)->exists();
    }
}
