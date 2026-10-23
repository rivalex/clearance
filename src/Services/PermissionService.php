<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Services;

use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository;
use Rivalex\Clearance\Exceptions\ClearanceConfigException;
use Rivalex\Clearance\Exceptions\ClearanceNamingException;
use Rivalex\Clearance\Exceptions\ClearanceProtectedResourceException;
use Rivalex\Clearance\Exceptions\ClearanceScopeViolationException;
use Rivalex\Clearance\Models\Permission;
use Rivalex\Clearance\Models\RoleMeta;
use Rivalex\Clearance\Models\UserContextPermissionOverride;
use Rivalex\Clearance\Support\SpatieSchema;
use Rivalex\Clearance\Support\SuperAdmin;
use Spatie\Permission\Exceptions\GuardDoesNotMatch;
use Spatie\Permission\Models\Role;

class PermissionService
{
    private readonly ContextService $contexts;

    /**
     * @param  ContextService|null  $contexts  Resolver whose request memo is flushed after every write; resolved from the container when omitted.
     */
    public function __construct(
        private readonly Repository $config,
        ?ContextService $contexts = null,
    ) {
        $this->contexts = $contexts ?? Container::getInstance()->make(ContextService::class);
    }

    /**
     * Validates a permission name against the gruppo-azione naming convention (V6).
     *
     * @throws ClearanceNamingException
     */
    public function validate(string $name): void
    {
        if (! $this->config->get('clearance.enforce_naming_convention', true)) {
            return;
        }

        $sep = preg_quote(
            $this->config->get('clearance.naming_separator', '-'),
            '/',
        );

        // Must be lowercase alphanumeric (underscore allowed) groups joined by separator, min 2 parts
        if (! preg_match('/^[a-z][a-z0-9_]*('.$sep.'[a-z][a-z0-9_]*)+$/', $name)) {
            throw new ClearanceNamingException(
                "Permission name '{$name}' must follow format gruppo-azione: "
                .'lowercase, underscores allowed, no spaces/dots/other symbols, at least one separator, no bare action.',
            );
        }
    }

    /**
     * Whether a permission can be granted through a user-facing path. False when the name
     * equals a `clearance.non_delegable` entry or matches a `prefix*` entry whose prefix
     * ends with the configured naming separator.
     *
     * @throws ClearanceConfigException when an entry has a misplaced `*` or a prefix that does not end with the separator.
     */
    public function isDelegable(string $name): bool
    {
        $separator = (string) $this->config->get('clearance.naming_separator', '-');

        foreach ((array) $this->config->get('clearance.non_delegable', []) as $entry) {
            $entry = (string) $entry;

            if (! str_contains($entry, '*')) {
                if ($name === $entry) {
                    return false;
                }

                continue;
            }

            $prefix = substr($entry, 0, -1);

            if (! str_ends_with($entry, '*') || str_contains($prefix, '*') || ! str_ends_with($prefix, $separator)) {
                throw new ClearanceConfigException(
                    "Invalid clearance.non_delegable entry [{$entry}]: use an exact name or a pattern like [group{$separator}*]."
                );
            }

            if (str_starts_with($name, $prefix)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether the role contains at least one non-delegable permission, so only trusted code may assign it.
     */
    public function isSystemAssignedOnly(Role $role): bool
    {
        foreach ($role->permissions as $permission) {
            if (! $this->isDelegable($permission->name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the name is a built-in `clearance-*` permission or the configured access permission.
     */
    public function isProtected(string $name): bool
    {
        return str_starts_with($name, 'clearance-')
            || $name === (string) $this->config->get('clearance.access_permission', 'clearance-access');
    }

    /**
     * Refuses a name reserved for the installer: `clearance-*` and the configured access permission.
     *
     * Deliberately outside validate(), which only checks the naming convention.
     *
     * @throws ClearanceProtectedResourceException
     */
    private function assertNameNotReserved(string $name): void
    {
        if ($this->isProtected($name)) {
            throw new ClearanceProtectedResourceException(
                "Permission name [{$name}] is reserved for built-in clearance permissions and cannot be used."
            );
        }
    }

    /**
     * Creates a new permission after validating the name.
     *
     * @throws ClearanceProtectedResourceException if the name is reserved (`clearance-*` or the configured access permission).
     */
    public function create(string $name, string $guardName): Permission
    {
        $this->assertNameNotReserved($name);
        $this->validate($name);

        try {
            $permission = Permission::create([
                'name' => $name,
                'guard_name' => $guardName,
            ]);

            if (! $permission instanceof Permission) {
                throw new \LogicException('Expected instance of '.Permission::class.'.');
            }

            return $permission;
        } finally {
            $this->contexts->flush();
        }
    }

    /**
     * Renames an existing permission after validating the new name.
     *
     * A permission that has holders (a role, a direct model permission or a forced_on contextual
     * override) cannot be renamed to a non-delegable name, and a non-delegable permission cannot be
     * renamed while it has holders.
     *
     * @throws ClearanceProtectedResourceException if the permission is a built-in clearance-* or access permission, if the permission is non-delegable and has holders, if the new name is reserved, or if the new name is non-delegable and the permission has holders.
     */
    public function rename(Permission $permission, string $name): Permission
    {
        if ($this->isProtected($permission->name)) {
            throw new ClearanceProtectedResourceException(
                "Permission [{$permission->name}] is a built-in clearance permission and cannot be renamed."
            );
        }

        if (! $this->isDelegable($permission->name) && $this->hasGrantingHolders($permission)) {
            throw new ClearanceProtectedResourceException(
                "Permission [{$permission->name}] is non-delegable and cannot be renamed while it has holders."
            );
        }

        $this->assertNameNotReserved($name);
        $this->validate($name);

        if (! $this->isDelegable($name) && $this->hasGrantingHolders($permission)) {
            throw new ClearanceProtectedResourceException(
                "Permission [{$permission->name}] has holders and cannot be renamed to the non-delegable name [{$name}]."
            );
        }

        try {
            $permission->update(['name' => $name]);

            return $permission->fresh();
        } finally {
            $this->contexts->flush();
        }
    }

    /**
     * Whether a role, a direct model permission or a forced_on contextual override grants the permission (used by rename checks).
     */
    private function hasGrantingHolders(Permission $permission): bool
    {
        $connection = (new Permission)->getConnection();
        $permissionKey = SpatieSchema::permissionPivotKey();

        return $connection->table(SpatieSchema::table('role_has_permissions'))
            ->where($permissionKey, $permission->id)
            ->exists()
            || $connection->table(SpatieSchema::table('model_has_permissions'))
                ->where($permissionKey, $permission->id)
                ->exists()
            || UserContextPermissionOverride::where('permission_id', $permission->id)
                ->where('type', UserContextPermissionOverride::TYPE_FORCED_ON)
                ->exists();
    }

    /**
     * Whether a role, a direct model permission or a contextual override (forced_on or forced_off) holds the permission (used by deletion).
     */
    private function hasHolders(Permission $permission): bool
    {
        $connection = (new Permission)->getConnection();
        $permissionKey = SpatieSchema::permissionPivotKey();

        return $connection->table(SpatieSchema::table('role_has_permissions'))
            ->where($permissionKey, $permission->id)
            ->exists()
            || $connection->table(SpatieSchema::table('model_has_permissions'))
                ->where($permissionKey, $permission->id)
                ->exists()
            || UserContextPermissionOverride::where('permission_id', $permission->id)
                ->exists();
    }

    /**
     * Deletes a permission.
     *
     * @throws ClearanceProtectedResourceException if the permission is a built-in clearance-* or the configured access permission.
     */
    public function delete(Permission $permission): void
    {
        if ($this->isProtected($permission->name)) {
            throw new ClearanceProtectedResourceException(
                "Permission [{$permission->name}] is a built-in clearance permission and cannot be deleted."
            );
        }

        // Spatie automatically handles pivot cleanup in its boot() method
        // if the model is registered correctly in config/permission.php.
        // To be safe and fulfill requirement: "logica per rimuovere i permessi dati manualmente agli utenti"
        // we can try to detach from all models using the model_has_permissions table directly
        // to avoid crashes if the 'users' relationship is not perfectly configured in tests.

        try {
            (new Permission)->getConnection()->table(SpatieSchema::table('model_has_permissions'))
                ->where(SpatieSchema::permissionPivotKey(), $permission->id)
                ->delete();

            $permission->delete();
        } finally {
            $this->contexts->flush();
        }
    }

    /**
     * Deletes a permission on behalf of an actor.
     *
     * Runs assertCanDeleteAs() and then delete(). The trusted delete() stays without the actor rules.
     *
     * @throws ClearanceProtectedResourceException if the permission is built-in, or non-delegable and the actor is not a super_admin.
     * @throws ClearanceScopeViolationException if the permission has holders and the actor does not hold it.
     */
    public function deleteAs(Authenticatable $actor, Permission $permission): void
    {
        $this->assertCanDeleteAs($actor, $permission);

        $this->delete($permission);
    }

    /**
     * Deletes every permission of a group on behalf of an actor, all or nothing.
     *
     * A protected member refuses the whole group. The actor rules are checked for every member before the first
     * write, then the deletions run in one transaction through delete().
     *
     * @throws ClearanceProtectedResourceException if a member is built-in, or non-delegable and the actor is not a super_admin.
     * @throws ClearanceScopeViolationException if a member has holders and the actor does not hold it.
     */
    public function deleteGroupAs(Authenticatable $actor, string $prefix, string $guardName): void
    {
        $permissions = Permission::forGroup($prefix, $guardName);

        foreach ($permissions as $permission) {
            if ($this->isProtected($permission->name)) {
                throw new ClearanceProtectedResourceException(
                    "Permission [{$permission->name}] is a built-in clearance permission and cannot be deleted."
                );
            }
        }

        foreach ($permissions as $permission) {
            $this->assertCanDeleteAs($actor, $permission);
        }

        try {
            (new Permission)->getConnection()->transaction(function () use ($permissions): void {
                foreach ($permissions as $permission) {
                    $this->delete($permission);
                }
            });
        } finally {
            $this->contexts->flush();
        }
    }

    /**
     * Refuses the deletion of a permission the actor may not delete.
     *
     * A super_admin passes. Any other actor is refused when the permission is non-delegable, or when it has holders
     * (a role, a direct model permission or a contextual override) and the actor does not hold it, or when the
     * super_admin role or a locked role holds it. A permission on another guard than the actor's counts as not held.
     *
     * @throws ClearanceProtectedResourceException
     * @throws ClearanceScopeViolationException
     */
    private function assertCanDeleteAs(Authenticatable $actor, Permission $permission): void
    {
        if (SuperAdmin::is($actor)) {
            return;
        }

        if (! $this->isDelegable($permission->name)) {
            throw new ClearanceProtectedResourceException(
                "Permission [{$permission->name}] is non-delegable and can only be deleted by a super_admin."
            );
        }

        $roleIds = (new Permission)->getConnection()->table(SpatieSchema::table('role_has_permissions'))
            ->where(SpatieSchema::permissionPivotKey(), $permission->id)
            ->pluck(SpatieSchema::rolePivotKey())->all();

        if ($roleIds !== [] && (
            Role::query()->whereKey($roleIds)->where('name', SuperAdmin::ROLE)->where('guard_name', SuperAdmin::guard())->exists()
            || RoleMeta::query()->whereIn('role_id', $roleIds)->where('is_locked', true)->exists()
        )) {
            throw new ClearanceProtectedResourceException("Cannot delete permission [{$permission->name}]: the super_admin role or a locked role holds it.");
        }

        if (! $this->hasHolders($permission)) {
            return;
        }

        $held = false;

        if (method_exists($actor, 'hasPermissionTo')) {
            try {
                $held = (bool) $actor->hasPermissionTo($permission);
            } catch (GuardDoesNotMatch) {
                $held = false;
            }
        }

        if (! $held) {
            throw new ClearanceScopeViolationException(
                "Cannot delete permission [{$permission->name}]: users hold it and you do not hold it yourself."
            );
        }
    }

    /**
     * Assigns a permission to a role. Single write path per V8.
     */
    public function assignToRole(Role $role, \Spatie\Permission\Models\Permission $permission): void
    {
        try {
            $role->givePermissionTo($permission);
        } finally {
            $this->contexts->flush();
        }
    }

    /**
     * Revokes a permission from a role. Single write path per V8.
     */
    public function revokeFromRole(Role $role, \Spatie\Permission\Models\Permission $permission): void
    {
        try {
            $role->revokePermissionTo($permission);
        } finally {
            $this->contexts->flush();
        }
    }

    /**
     * Extracts the group portion from a permission name.
     */
    public function groupFor(string $name): string
    {
        $sep = $this->config->get('clearance.naming_separator', '-');

        return explode($sep, $name)[0];
    }

    /**
     * Creates a group of permissions for one prefix and multiple abilities (V6, V8).
     *
     * @param  array<int, string>  $abilities
     * @return array<int, Permission>
     */
    public function createGroup(string $prefix, string $guardName, array $abilities): array
    {
        $sep = $this->config->get('clearance.naming_separator', '-');
        $created = [];

        foreach ($abilities as $ability) {
            $this->assertNameNotReserved($prefix.$sep.$ability);
        }

        try {
            (new Permission)->getConnection()->transaction(function () use ($abilities, $prefix, $sep, $guardName, &$created): void {
                foreach ($abilities as $ability) {
                    $created[] = $this->create($prefix.$sep.$ability, $guardName);
                }
            });

            return $created;
        } finally {
            $this->contexts->flush();
        }
    }

    /**
     * Updates a permission group by creating added and deleting removed abilities (V6, V8).
     *
     * Trusted path: it applies no actor rule. The additions and the removals run in one transaction.
     *
     * @param  array<int, string>  $old  current ability names
     * @param  array<int, string>  $new  desired ability names
     */
    public function updateGroup(string $prefix, string $guardName, array $old, array $new): void
    {
        $sep = $this->config->get('clearance.naming_separator', '-');

        // Every target name is checked before the first write.
        foreach (array_merge(array_diff($new, $old), array_diff($old, $new)) as $ability) {
            $this->assertNameNotReserved($prefix.$sep.$ability);
        }

        try {
            (new Permission)->getConnection()->transaction(function () use ($prefix, $guardName, $old, $new, $sep): void {
                foreach (array_diff($new, $old) as $ability) {
                    $this->create($prefix.$sep.$ability, $guardName);
                }

                foreach (array_diff($old, $new) as $ability) {
                    /** @var Permission|null $perm */
                    $perm = Permission::where('name', $prefix.$sep.$ability)
                        ->where('guard_name', $guardName)
                        ->first();

                    if ($perm !== null) {
                        $this->delete($perm);
                    }
                }
            });
        } finally {
            $this->contexts->flush();
        }
    }

    /**
     * Updates a permission group on behalf of an actor.
     *
     * Removing an ability counts as deleting its permission: the deletion rules of deleteAs() are checked for every
     * existing permission named by the removed abilities before the first write, then updateGroup() runs.
     *
     * @param  array<int, string>  $old  current ability names
     * @param  array<int, string>  $new  desired ability names
     *
     * @throws ClearanceProtectedResourceException if a removed permission is non-delegable and the actor is not a super_admin, or the name is reserved.
     * @throws ClearanceScopeViolationException if a removed permission has holders and the actor does not hold it.
     */
    public function updateGroupAs(Authenticatable $actor, string $prefix, string $guardName, array $old, array $new): void
    {
        $sep = $this->config->get('clearance.naming_separator', '-');

        foreach (array_diff($old, $new) as $ability) {
            /** @var Permission|null $perm */
            $perm = Permission::where('name', $prefix.$sep.$ability)
                ->where('guard_name', $guardName)
                ->first();

            if ($perm !== null) {
                $this->assertCanDeleteAs($actor, $perm);
            }
        }

        $this->updateGroup($prefix, $guardName, $old, $new);
    }
}
