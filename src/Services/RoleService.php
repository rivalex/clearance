<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Services;

use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Rivalex\Clearance\Exceptions\ClearanceNamingException;
use Rivalex\Clearance\Exceptions\ClearanceProtectedResourceException;
use Rivalex\Clearance\Exceptions\ClearanceScopeViolationException;
use Rivalex\Clearance\Models\ClearanceMeta;
use Rivalex\Clearance\Models\ClearanceSettings;
use Rivalex\Clearance\Models\Permission;
use Rivalex\Clearance\Models\RoleMeta;
use Rivalex\Clearance\Models\UserContextPermissionOverride;
use Rivalex\Clearance\Models\UserRoleContext;
use Rivalex\Clearance\Support\SpatieSchema;
use Rivalex\Clearance\Support\SuperAdmin;
use Spatie\Permission\Exceptions\GuardDoesNotMatch;
use Spatie\Permission\Models\Permission as SpatiePermission;
use Spatie\Permission\Models\Role;

class RoleService
{
    /** Allowed role names: letters, digits, space, underscore, dot, hyphen; 1 to 255 characters. */
    private const NAME_PATTERN = '/^[\pL\pN _.-]{1,255}\z/u';

    private readonly ContextService $contexts;

    /**
     * @param  ContextService|null  $contexts  Resolver whose request memo is flushed after every write; resolved from the container when omitted.
     */
    public function __construct(
        private readonly PermissionService $permissions,
        ?ContextService $contexts = null,
    ) {
        $this->contexts = $contexts ?? Container::getInstance()->make(ContextService::class);
    }

    /**
     * Refuses the reserved super_admin name on every guard.
     *
     * @throws ClearanceProtectedResourceException if the name is super_admin.
     */
    private function assertNameNotReserved(string $name): void
    {
        if ($name === SuperAdmin::ROLE) {
            throw new ClearanceProtectedResourceException(
                'The name super_admin is reserved and cannot be used for a role.'
            );
        }
    }

    /**
     * Refuses a role name outside letters, digits, space, underscore, dot and hyphen (1 to 255 characters).
     *
     * @throws ClearanceNamingException if the name does not match the allowed pattern.
     */
    private function assertNameValid(string $name): void
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new ClearanceNamingException(
                'A role name may contain only letters, digits, spaces, underscores, dots and hyphens (1 to 255 characters).'
            );
        }
    }

    /**
     * Creates a new role for the given guard.
     *
     * @throws ClearanceProtectedResourceException if the name is super_admin.
     * @throws ClearanceNamingException if the name contains characters outside the allowed set.
     */
    public function create(string $name, string $guardName): Role
    {
        $this->assertNameNotReserved($name);
        $this->assertNameValid($name);

        // A role on a guard without a user provider model could never be deleted (Spatie resolves the model on delete).
        // Resolved from the auth config directly: the Spatie helper for this is not available on every supported version.
        $provider = config("auth.guards.{$guardName}.provider");
        $model = is_string($provider) && $provider !== '' ? config("auth.providers.{$provider}.model") : null;

        if (! is_string($model) || ! class_exists($model)) {
            throw new ClearanceScopeViolationException(
                "Guard [{$guardName}] has no user provider model: a role on it could not be deleted."
            );
        }

        try {
            $role = Role::create([
                'name' => $name,
                'guard_name' => $guardName,
            ]);

            if (! $role instanceof Role) {
                throw new \LogicException('Expected instance of '.Role::class.'.');
            }

            return $role;
        } finally {
            $this->contexts->flush();
        }
    }

    /**
     * Renames an existing role and moves its display metadata (`clr_meta`) to the new name in the same transaction.
     *
     * @throws ClearanceProtectedResourceException if the role is super_admin or the new name is super_admin.
     * @throws ClearanceNamingException if the new name contains characters outside the allowed set.
     */
    public function rename(Role $role, string $name): Role
    {
        if ($role->name === SuperAdmin::ROLE && $role->guard_name === SuperAdmin::guard()) {
            throw new ClearanceProtectedResourceException(
                'The super_admin role cannot be renamed.'
            );
        }

        if (RoleMeta::where('role_id', $role->id)->value('is_locked')) {
            throw new ClearanceProtectedResourceException(
                'This role is locked and cannot be renamed.'
            );
        }

        $this->assertNameNotReserved($name);
        $this->assertNameValid($name);

        try {
            $old = $role->name;

            $role->getConnection()->transaction(function () use ($role, $old, $name): void {
                $role->update(['name' => $name]);

                if ($old !== $name) {
                    $this->moveMeta($role, $old, $name);
                }
            });

            return $role->fresh();
        } finally {
            $this->contexts->flush();
        }
    }

    /**
     * Moves the display metadata (`clr_meta`, keyed by role name) of a renamed role to its new name.
     *
     * The row is shared by same-named roles of other guards: it moves only when no other role carries the old
     * name, a stale row at the new name is dropped only when no other role carries the new name, and a row that
     * belongs to a same-named role of another guard is kept.
     */
    private function moveMeta(Role $role, string $old, string $new): void
    {
        $othersOld = Role::where('name', $old)->whereKeyNot($role->getKey())->exists();
        $othersNew = Role::where('name', $new)->whereKeyNot($role->getKey())->exists();

        if ($othersNew) {
            if (! $othersOld) {
                ClearanceMeta::where('subject_type', 'role')->where('subject_key', $old)->delete();
            }

            return;
        }

        ClearanceMeta::where('subject_type', 'role')->where('subject_key', $new)->delete();

        if (! $othersOld) {
            ClearanceMeta::where('subject_type', 'role')->where('subject_key', $old)->update(['subject_key' => $new]);
        }
    }

    /**
     * Renames an existing role on behalf of an actor.
     *
     * A super_admin actor delegates straight to rename(). Any other actor cannot rename a role they hold (globally
     * or in a context), the default role, or a role holding a permission they do not hold on the role's guard.
     *
     * @throws ClearanceScopeViolationException if one of the actor rules above refuses the rename.
     * @throws ClearanceProtectedResourceException if the role is super_admin or locked, or the new name is super_admin.
     * @throws ClearanceNamingException if the new name contains characters outside the allowed set.
     */
    public function renameAs(Authenticatable $actor, Role $role, string $name): Role
    {
        if (SuperAdmin::is($actor)) {
            return $this->rename($role, $name);
        }

        $actorHoldsRole = (method_exists($actor, 'hasRole') && $actor->hasRole($role->name, $role->guard_name))
            || UserRoleContext::where('user_id', $actor->getAuthIdentifier())
                ->where('role_id', $role->id)
                ->exists();

        if ($actorHoldsRole) {
            throw new ClearanceScopeViolationException('Only super_admin may rename a role they hold.');
        }

        if ($this->isDefaultRole($role)) {
            throw new ClearanceScopeViolationException('Only super_admin may rename the default role.');
        }

        $missing = $role->permissions
            ->reject(fn ($permission) => method_exists($actor, 'hasPermissionTo') && $actor->hasPermissionTo($permission))
            ->pluck('name')
            ->sort()
            ->values();

        if ($missing->isNotEmpty()) {
            $names = $missing->implode(', ');

            throw new ClearanceScopeViolationException(
                "Cannot rename role [{$role->name}]: you do not hold permission(s) [{$names}]."
            );
        }

        return $this->rename($role, $name);
    }

    /**
     * Throws when the role may not be deleted: the super_admin role and a locked role.
     *
     * Callers that remove the holders of a role first run this before the first write.
     *
     * @throws ClearanceProtectedResourceException if the role is super_admin or locked.
     */
    public function assertDeletable(Role $role): void
    {
        if ($role->name === SuperAdmin::ROLE && $role->guard_name === SuperAdmin::guard()) {
            throw new ClearanceProtectedResourceException(
                'The super_admin role cannot be deleted.'
            );
        }

        if (RoleMeta::where('role_id', $role->id)->value('is_locked')) {
            throw new ClearanceProtectedResourceException(
                'This role is locked and cannot be deleted.'
            );
        }
    }

    /**
     * Throws when an actor may not delete a role through the panel.
     *
     * Runs assertDeletable() (the super_admin role and a locked role), then refuses the current default role for
     * every actor, then, for an actor who is not super_admin, refuses the role when the actor does not hold every
     * permission of the role on its guard. A permission whose guard differs from the actor's counts as not held.
     * Holders are not considered: a role without holders is checked the same way.
     *
     * @throws ClearanceProtectedResourceException if the role is super_admin, locked or the default role.
     * @throws ClearanceScopeViolationException if the actor does not hold every permission of the role.
     */
    public function assertCanDeleteAs(Authenticatable $actor, Role $role): void
    {
        $this->assertDeletable($role);

        if ($this->isDefaultRole($role)) {
            throw new ClearanceProtectedResourceException(
                'The default role cannot be deleted; choose another default role first.'
            );
        }

        if (SuperAdmin::is($actor)) {
            return;
        }

        $missing = $role->permissions
            ->reject(function ($permission) use ($actor): bool {
                if (! method_exists($actor, 'hasPermissionTo')) {
                    return false;
                }

                try {
                    return (bool) $actor->hasPermissionTo($permission);
                } catch (GuardDoesNotMatch) {
                    return false;
                }
            })
            ->pluck('name')
            ->sort()
            ->values();

        if ($missing->isNotEmpty()) {
            $names = $missing->implode(', ');

            throw new ClearanceScopeViolationException(
                "Cannot delete role [{$role->name}]: you do not hold permission(s) [{$names}]."
            );
        }
    }

    /**
     * Deletes a role on behalf of an actor.
     *
     * Runs assertCanDeleteAs() and then, in one transaction that first locks the role row, refuses a role that still
     * has a global holder or a contextual binding (the caller detaches or reassigns the holders first, inside the
     * same transaction) and calls delete(). The trusted delete() stays without the actor rules.
     *
     * @throws ClearanceProtectedResourceException if the role is super_admin, locked or the default role.
     * @throws ClearanceScopeViolationException if the actor does not hold every permission of the role, or users
     *                                          still hold the role.
     */
    public function deleteAs(Authenticatable $actor, Role $role): void
    {
        $this->assertCanDeleteAs($actor, $role);

        $role->getConnection()->transaction(function () use ($role): void {
            get_class($role)::query()->whereKey($role->getKey())->lockForUpdate()->firstOrFail();

            if ($this->hasGlobalHolders($role, true) || UserRoleContext::where('role_id', $role->id)->lockForUpdate()->exists()) {
                throw new ClearanceScopeViolationException(
                    "Cannot delete role [{$role->name}]: users still hold it; detach or reassign them first."
                );
            }

            $this->delete($role);
        });
    }

    /**
     * Deletes a role.
     *
     * This is the trusted path for host code: it refuses the super_admin role and a locked role, but applies no
     * actor rule and no default-role rule. The panel calls deleteAs(). The metadata, the default role setting (when
     * the role is the default role, it is cleared) and the role row go in one transaction.
     *
     * @throws ClearanceProtectedResourceException if the role is super_admin or locked.
     */
    public function delete(Role $role): void
    {
        $this->assertDeletable($role);

        // App-side guarantee: null-out parent_role_id on children before deletion
        // (belt-and-suspenders alongside ON DELETE SET NULL on the FK).
        try {
            $role->getConnection()->transaction(function () use ($role): void {
                RoleMeta::where('parent_role_id', $role->id)->update(['parent_role_id' => null]);

                // The display metadata is keyed by name: keep it while a same-named role of another guard exists.
                if (! Role::where('name', $role->name)->whereKeyNot($role->getKey())->exists()) {
                    ClearanceMeta::where('subject_type', 'role')->where('subject_key', $role->name)->delete();
                }

                // A trusted delete of the default role leaves no default role, so the id can never be reused.
                if ((string) $role->getKey() === ClearanceSettings::get('default_role_id')) {
                    ClearanceSettings::set('default_role_id', null);
                }

                $role->delete();
            });
        } finally {
            $this->contexts->flush();
        }
    }

    /**
     * Syncs permissions for a role via PermissionService (V8 - single write path).
     * Only permissions sharing the role's guard_name are accepted.
     * When the role has a ceiling parent (parent_role_id set), incoming permissions are
     * silently filtered to only those within the parent's ceiling (I1 - no exception).
     * After syncing, any removed permissions are cascaded to child roles (I1 cascade).
     *
     * Security ceiling (F9 - security audit): $actor must not add permissions to a role
     * they themselves currently hold unless they are super_admin (self-escalation via
     * role editing), may only add a permission they themselves already hold, and can
     * never add a protected permission (PermissionService::isProtected(): clearance-* or the
     * configured access permission) to any role unless super_admin.
     *
     * The super_admin role (on SuperAdmin::guard()): protected permissions can be neither added
     * nor removed by any actor, super_admin included; any other change to its permission set is
     * refused unless the actor is super_admin. An unchanged set is not a change.
     *
     * Non-delegable permissions: adding a permission listed in
     * `clearance.non_delegable` to a role that has holders (a global `model_has_roles` row
     * or a contextual binding) is refused for every actor, super_admin included, because
     * it would grant the permission at once to every holder. A role without holders
     * accepts it.
     *
     * Removal ceiling: an actor who is not super_admin must hold every permission removed from the role
     * (and from its child roles by the cascade), cannot change a locked role, and a cascade that would
     * change a locked child is refused. Removing a permission from a role also deletes the forced_on
     * contextual overrides of that role and permission; forced_off overrides stay.
     *
     * @param  array<int, Permission>  $permissions
     *
     * @throws InvalidArgumentException when a permission guard does not match the role guard
     * @throws ClearanceScopeViolationException if $actor attempts to escalate beyond their ceiling, removes a permission they do not hold, or is not super_admin and changes the super_admin role
     * @throws ClearanceProtectedResourceException if a protected permission is targeted, a non-delegable permission is added to a role with holders, or a non-super_admin actor changes a locked role or cascades into a locked child
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

        // Protected permissions (clearance-* and the configured access permission) on super_admin are
        // immutable via the panel - absolute, regardless of actor (even a super_admin actor cannot touch
        // these here). Any other change to the super_admin role is reserved to a super_admin actor.
        if (SuperAdmin::isRole($role) && $role->guard_name === SuperAdmin::guard()) {
            $toAdd = $desired->filter(fn (Permission $p) => ! $current->has($p->id));
            $toRemove = $current->filter(fn (SpatiePermission $p) => ! $desired->has($p->id));

            $blocked = $toAdd->merge($toRemove)->first(
                fn (SpatiePermission $p) => $this->permissions->isProtected($p->name)
            );

            if ($blocked !== null) {
                throw new ClearanceProtectedResourceException(
                    "Permission [{$blocked->name}] is a built-in clearance permission and cannot be added or removed from the super_admin role."
                );
            }

            if (($toAdd->isNotEmpty() || $toRemove->isNotEmpty()) && ! SuperAdmin::is($actor)) {
                throw new ClearanceScopeViolationException('Only super_admin may change the super_admin role.');
            }
        }

        $addedPermissions = $desired->filter(fn ($p) => ! $current->has($p->id))->values()->all();
        $removedPermissions = $current->filter(fn (SpatiePermission $p) => ! $desired->has($p->id))->values()->all();

        // A locked role cannot be changed by an actor who is not super_admin; an unchanged set is not a change.
        if ($addedPermissions !== [] || $removedPermissions !== []) {
            $this->assertNotLockedForActor($actor, $role);
        }

        // The default role reaches every later sign-up: only super_admin adds permissions to it.
        if ($addedPermissions !== [] && ! SuperAdmin::is($actor) && $this->isDefaultRole($role)) {
            throw new ClearanceScopeViolationException('Only super_admin may add permissions to the default role.');
        }

        try {
            // The non-delegable check (a locking read of the existing holder rows) and the writes share one transaction;
            // see assertNoNonDelegableAddedToHeldRole() for what the lock does not cover.
            $role->getConnection()->transaction(function () use ($actor, $role, $addedPermissions, $removedPermissions, $desired, $current): void {
                // The role-row lock comes first: assignments of this role (UserClearanceService) take the same lock.
                get_class($role)::query()->whereKey($role->getKey())->lockForUpdate()->firstOrFail();

                $this->assertNoNonDelegableAddedToHeldRole($role, $addedPermissions);
                $this->assertRoleSyncWithinCeiling($actor, $role, $addedPermissions);
                $this->assertRoleRemovalWithinCeiling($actor, $role, $removedPermissions);

                foreach ($desired as $id => $permission) {
                    if (! $current->has($id)) {
                        $this->permissions->assignToRole($role, $permission);
                    }
                }

                foreach ($removedPermissions as $permission) {
                    $this->revokeAndUnpin($role, $permission);
                }

                // Cascade removals to child roles automatically.
                if (! empty($removedPermissions)) {
                    $this->cascadeToChildren($actor, $role, $removedPermissions);
                }
            });
        } finally {
            $this->contexts->flush();
        }
    }

    /**
     * Refuses to add a non-delegable permission to a role that currently has holders.
     * Absolute: no actor is exempt. Runs before any write, inside the
     * transaction of the sync, after the lock on the role row. The assignment paths of
     * UserClearanceService take the same role-row lock and re-check the role on fresh permissions,
     * so a concurrent first assignment waits for the sync.
     *
     * @param  array<int, Permission>  $addedPermissions
     *
     * @throws ClearanceProtectedResourceException
     */
    private function assertNoNonDelegableAddedToHeldRole(Role $role, array $addedPermissions): void
    {
        $blocked = null;

        foreach ($addedPermissions as $permission) {
            if (! $this->permissions->isDelegable($permission->name)) {
                $blocked = $permission;

                break;
            }
        }

        if ($blocked === null) {
            return;
        }

        $hasHolders = $this->hasGlobalHolders($role, true)
            || UserRoleContext::where('role_id', $role->id)->lockForUpdate()->exists();

        if ($hasHolders) {
            throw new ClearanceProtectedResourceException(
                "Permission [{$blocked->name}] is non-delegable and cannot be added to role [{$role->name}] while users hold it."
            );
        }
    }

    /**
     * Enforces the F9 privilege ceiling for a role-permission sync: the actor cannot add
     * permissions to a role they themselves currently hold (global or contextual) unless
     * super_admin, cannot add a permission they do not themselves hold, and cannot add
     * any protected permission (clearance-* or the configured access permission) to any role - unless they are super_admin.
     *
     * @param  array<int, Permission>  $addedPermissions
     */
    private function assertRoleSyncWithinCeiling(Authenticatable $actor, Role $role, array $addedPermissions): void
    {
        if (empty($addedPermissions)) {
            return;
        }

        $actorIsSuperAdmin = SuperAdmin::is($actor);

        if ($actorIsSuperAdmin) {
            return;
        }

        $actorHoldsRole = (method_exists($actor, 'hasRole') && $actor->hasRole($role->name, $role->guard_name))
            || UserRoleContext::where('user_id', $actor->getAuthIdentifier())
                ->where('role_id', $role->id)
                ->exists();

        if ($actorHoldsRole) {
            throw new ClearanceScopeViolationException(
                "Only super_admin may add permissions to role [{$role->name}] while holding it themselves."
            );
        }

        foreach ($addedPermissions as $permission) {
            if ($this->permissions->isProtected($permission->name)) {
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
     * Enforces the removal ceiling: an actor who is not super_admin must hold every permission removed from the role.
     *
     * Holding is evaluated on the state before any write, on the permission's own guard, with the object form of
     * `hasPermissionTo()`. Skipped for super_admin.
     *
     * @param  array<int, SpatiePermission>  $removedPermissions
     *
     * @throws ClearanceScopeViolationException if the actor does not hold a removed permission.
     */
    private function assertRoleRemovalWithinCeiling(Authenticatable $actor, Role $role, array $removedPermissions): void
    {
        if ($removedPermissions === [] || SuperAdmin::is($actor)) {
            return;
        }

        $missing = collect($removedPermissions)
            ->reject(fn (SpatiePermission $permission) => method_exists($actor, 'hasPermissionTo') && $actor->hasPermissionTo($permission))
            ->pluck('name')
            ->sort()
            ->values();

        if ($missing->isNotEmpty()) {
            $names = $missing->implode(', ');

            throw new ClearanceScopeViolationException(
                "Cannot remove permission(s) [{$names}] from role [{$role->name}]: you do not hold them."
            );
        }
    }

    /**
     * Writes the Clearance metadata of a role (lock flag, scope, allowed context types).
     *
     * The scope of the super_admin role and of any role with holders (a global
     * `model_has_roles` row or a contextual binding) cannot change, because the
     * existing assignments would silently stop matching the declared scope.
     * super_admin is always saved locked.
     *
     * Context types: removing a stored type from a role with holders is refused (narrowing), and a type
     * that is not already stored must be declared in `clearance.contextual_models`.
     *
     * @param  array<int, string>|null  $contextTypes  Allowed context model FQCNs (contextual scope only).
     *
     * @throws ClearanceScopeViolationException if the scope is neither global nor contextual, the scope of super_admin
     *                                          or of a role with holders changes, a role with holders loses a context type,
     *                                          or a new context type is unknown.
     */
    public function updateMeta(Role $role, bool $isLocked, string $scope, ?array $contextTypes): void
    {
        if (! in_array($scope, [RoleMeta::SCOPE_GLOBAL, RoleMeta::SCOPE_CONTEXTUAL], true)) {
            throw new ClearanceScopeViolationException("Scope [{$scope}] is not valid: use global or contextual.");
        }

        $currentScope = RoleMeta::where('role_id', $role->id)->value('scope') ?? RoleMeta::SCOPE_GLOBAL;

        if ($currentScope !== $scope) {
            if ($role->name === SuperAdmin::ROLE && $role->guard_name === SuperAdmin::guard()) {
                throw new ClearanceScopeViolationException(
                    'The scope of the super_admin role cannot be changed.'
                );
            }

            $hasHolders = $this->hasGlobalHolders($role)
                || UserRoleContext::where('role_id', $role->id)->exists();

            if ($hasHolders) {
                throw new ClearanceScopeViolationException(
                    "The scope of role [{$role->name}] cannot be changed while users hold it."
                );
            }
        }

        $storedTypes = array_values((array) (RoleMeta::firstOrNew(['role_id' => $role->id])->context_types ?? []));
        $newTypes = array_values((array) ($contextTypes ?? []));

        $narrowed = array_diff($storedTypes, $newTypes);
        if ($narrowed !== [] && $this->roleHasHolders($role)) {
            throw new ClearanceScopeViolationException(
                "The context types of role [{$role->name}] cannot be narrowed while users hold it."
            );
        }

        $unknown = array_diff($newTypes, $storedTypes, array_keys((array) config('clearance.contextual_models', [])));
        if ($unknown !== []) {
            throw new ClearanceScopeViolationException(
                'Context type ['.implode(', ', $unknown).'] is not declared in clearance.contextual_models.'
            );
        }

        $isSuperAdminRole = $role->name === SuperAdmin::ROLE && $role->guard_name === SuperAdmin::guard();

        try {
            RoleMeta::updateOrCreate(
                ['role_id' => $role->id],
                [
                    'is_locked' => $isSuperAdminRole ? true : $isLocked,
                    'scope' => $scope,
                    'context_types' => $contextTypes,
                ],
            );
        } finally {
            $this->contexts->flush();
        }
    }

    /**
     * Writes the Clearance metadata of a role on behalf of an actor.
     *
     * A super_admin actor delegates straight to updateMeta(). Any other actor cannot change the super_admin role,
     * cannot change a locked role, cannot set or clear the lock flag, and cannot change the scope of the default role.
     *
     * @param  array<int, string>|null  $contextTypes  Allowed context model FQCNs (contextual scope only).
     *
     * @throws ClearanceScopeViolationException if the actor is not super_admin and targets the super_admin role or changes the scope of the default role, or updateMeta() refuses.
     * @throws ClearanceProtectedResourceException if the role is locked, or the actor changes the lock flag, without being super_admin.
     */
    public function updateMetaAs(Authenticatable $actor, Role $role, bool $isLocked, string $scope, ?array $contextTypes): void
    {
        if (SuperAdmin::is($actor)) {
            $this->updateMeta($role, $isLocked, $scope, $contextTypes);

            return;
        }

        if (SuperAdmin::isRole($role) && $role->guard_name === SuperAdmin::guard()) {
            throw new ClearanceScopeViolationException('Only super_admin may change the super_admin role.');
        }

        $this->assertNotLockedForActor($actor, $role);

        $currentlyLocked = (bool) (RoleMeta::where('role_id', $role->id)->value('is_locked') ?? false);

        if ($isLocked !== $currentlyLocked) {
            throw new ClearanceProtectedResourceException('Only super_admin may lock or unlock a role.');
        }

        $currentScope = RoleMeta::where('role_id', $role->id)->value('scope') ?? RoleMeta::SCOPE_GLOBAL;

        if ($this->isDefaultRole($role) && $scope !== $currentScope) {
            throw new ClearanceScopeViolationException('Only super_admin may change the scope of the default role.');
        }

        $this->updateMeta($role, $isLocked, $scope, $contextTypes);
    }

    /**
     * Refuses a change to a locked role unless the actor is super_admin.
     *
     * @throws ClearanceProtectedResourceException if the role is locked and the actor is not super_admin.
     */
    private function assertNotLockedForActor(Authenticatable $actor, Role $role): void
    {
        if (SuperAdmin::is($actor)) {
            return;
        }

        if (RoleMeta::where('role_id', $role->id)->value('is_locked')) {
            throw new ClearanceProtectedResourceException('This role is locked and cannot be changed.');
        }
    }

    /**
     * True when a global `model_has_roles` row or a contextual binding references the role.
     */
    private function roleHasHolders(Role $role): bool
    {
        return $this->hasGlobalHolders($role)
            || UserRoleContext::where('role_id', $role->id)->exists();
    }

    /**
     * True when a global `model_has_roles` row references the role, using the configured pivot column.
     *
     * With $lock the rows are read with a locking read (`FOR UPDATE`, ignored by SQLite).
     */
    private function hasGlobalHolders(Role $role, bool $lock = false): bool
    {
        $query = $role->getConnection()
            ->table(SpatieSchema::table('model_has_roles'))
            ->where(SpatieSchema::rolePivotKey(), $role->id);

        return $lock ? $query->lockForUpdate()->first() !== null : $query->exists();
    }

    /**
     * Refuses a ceiling relationship that can never be valid: self, different guards or super_admin on either side.
     *
     * @throws ClearanceScopeViolationException if the child is the parent itself, the guards differ or either side is super_admin.
     */
    private function assertCanBeParent(Role $child, Role $parent): void
    {
        if ($child->is($parent)) {
            throw new ClearanceScopeViolationException(
                "Role [{$child->name}] cannot be its own ceiling parent."
            );
        }

        if ($child->guard_name !== $parent->guard_name) {
            throw new ClearanceScopeViolationException(
                "Role [{$child->name}] and parent [{$parent->name}] must share the same guard."
            );
        }

        foreach ([$child, $parent] as $side) {
            if ($side->name === SuperAdmin::ROLE && $side->guard_name === SuperAdmin::guard()) {
                throw new ClearanceScopeViolationException(
                    'The super_admin role cannot take part in a ceiling relationship.'
                );
            }
        }
    }

    /**
     * Assigns a ceiling (parent) role to a child role.
     * Silently trims child permissions that exceed the parent ceiling (I1). The single-level rule is checked in a
     * transaction that first locks both role rows (ascending key order), so two concurrent assignments cannot
     * build a two-level chain.
     *
     * @throws ClearanceScopeViolationException if the child is the parent itself, the guards differ,
     *                                          either side is super_admin, the child is already a parent (I2)
     *                                          or the parent is already a child (I3).
     */
    public function setParent(Role $child, Role $parent): void
    {
        $this->assertCanBeParent($child, $parent);

        try {
            $child->getConnection()->transaction(function () use ($child, $parent): void {
                // Lock the two role rows (not the clr_role_meta rows: creating a meta row for a role without
                // one would change its contextual assignability) in ascending key order, then re-read the metas.
                get_class($child)::query()
                    ->whereKey([$child->getKey(), $parent->getKey()])
                    ->orderBy($child->getKeyName())
                    ->lockForUpdate()
                    ->get();

                // The trim below must see the parent permissions as committed now, not as loaded earlier.
                $parent->load('permissions');

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
                        $this->revokeAndUnpin($childFresh, $perm);
                    }
                }
            });
        } finally {
            $this->contexts->flush();
        }
    }

    /**
     * Assigns a ceiling (parent) role to a child role on behalf of an actor.
     *
     * A super_admin actor delegates straight to setParent(). Any other actor cannot change a locked child, and must
     * hold every child permission that the new parent ceiling trims away. Replacing an existing parent also requires
     * every permission of the old parent.
     *
     * @throws ClearanceProtectedResourceException if the child is locked and the actor is not super_admin.
     * @throws ClearanceScopeViolationException if the actor does not hold a permission the trim removes, or setParent() refuses.
     */
    public function setParentAs(Authenticatable $actor, Role $child, Role $parent): void
    {
        if (SuperAdmin::is($actor)) {
            $this->setParent($child, $parent);

            return;
        }

        $this->assertCanBeParent($child, $parent);

        $child->getConnection()->transaction(function () use ($actor, $child, $parent): void {
            // Same ascending two-row lock as setParent(), so the checks below read what the write will use.
            get_class($child)::query()
                ->whereKey([$child->getKey(), $parent->getKey()])
                ->orderBy($child->getKeyName())
                ->lockForUpdate()
                ->get();

            $child->load('permissions');
            $parent->load('permissions');

            $this->assertNotLockedForActor($actor, $child);

            $oldParentId = RoleMeta::where('role_id', $child->getKey())->value('parent_role_id');

            if ($oldParentId !== null && (int) $oldParentId !== (int) $parent->getKey()) {
                $oldParent = get_class($child)::query()->find($oldParentId);

                if ($oldParent !== null) {
                    $missing = $this->missingParentPermissions($actor, $oldParent);

                    if ($missing->isNotEmpty()) {
                        $names = $missing->implode(', ');

                        throw new ClearanceScopeViolationException("Cannot replace the ceiling parent [{$oldParent->name}] of role [{$child->name}]: you do not hold permission(s) [{$names}].");
                    }
                }
            }

            $ceilingIds = $parent->permissions->pluck('id')->flip()->all();
            $trimmed = $child->permissions
                ->whereInstanceOf(SpatiePermission::class)
                ->filter(fn (SpatiePermission $perm) => ! isset($ceilingIds[$perm->id]))
                ->values()
                ->all();

            $this->assertRoleRemovalWithinCeiling($actor, $child, $trimmed);

            $this->setParent($child, $parent);
        });
    }

    /**
     * Detaches the ceiling role; the child becomes independent and keeps its current permissions.
     */
    public function removeParent(Role $child): void
    {
        try {
            RoleMeta::where('role_id', $child->id)->update(['parent_role_id' => null]);
        } finally {
            $this->contexts->flush();
        }
    }

    /**
     * Detaches the ceiling role on behalf of an actor.
     *
     * A super_admin actor, and a child without a parent, delegate straight to removeParent(). Any other actor
     * cannot change a locked child and must hold every permission of the parent role being detached.
     *
     * @throws ClearanceProtectedResourceException if the child is locked and the actor is not super_admin.
     * @throws ClearanceScopeViolationException if the actor does not hold every permission of the parent.
     */
    public function removeParentAs(Authenticatable $actor, Role $child): void
    {
        if (SuperAdmin::is($actor)) {
            $this->removeParent($child);

            return;
        }

        $parentId = RoleMeta::where('role_id', $child->id)->value('parent_role_id');

        if ($parentId === null) {
            $this->removeParent($child);

            return;
        }

        $this->assertNotLockedForActor($actor, $child);

        $parent = Role::find($parentId);

        if ($parent !== null) {
            $missing = $this->missingParentPermissions($actor, $parent);

            if ($missing->isNotEmpty()) {
                $names = $missing->implode(', ');

                throw new ClearanceScopeViolationException("Cannot remove the ceiling parent [{$parent->name}] of role [{$child->name}]: you do not hold permission(s) [{$names}].");
            }
        }

        $this->removeParent($child);
    }

    /**
     * Returns the sorted names of the permissions of a parent role that the actor does not hold.
     *
     * @return Collection<int, string>
     */
    private function missingParentPermissions(Authenticatable $actor, Role $parent): Collection
    {
        return $parent->permissions
            ->reject(function ($permission) use ($actor): bool {
                if (! method_exists($actor, 'hasPermissionTo')) {
                    return false;
                }

                try {
                    return (bool) $actor->hasPermissionTo($permission);
                } catch (GuardDoesNotMatch) {
                    return false;
                }
            })
            ->pluck('name')
            ->sort()
            ->values();
    }

    /**
     * Removes the given permissions from every child role of this parent (automatic cascade).
     *
     * For an actor who is not super_admin, a cascade that would change a locked child is refused; the caller runs
     * this inside the transaction of the sync, so the whole sync rolls back.
     *
     * @param  array<int, SpatiePermission>  $removedPermissions
     *
     * @throws ClearanceProtectedResourceException if a non-super_admin actor's cascade would change a locked child.
     */
    private function cascadeToChildren(Authenticatable $actor, Role $parent, array $removedPermissions): void
    {
        if (empty($removedPermissions)) {
            return;
        }

        $removedIds = collect($removedPermissions)->pluck('id')->flip()->all();
        $actorIsSuperAdmin = SuperAdmin::is($actor);

        $childMetas = RoleMeta::where('parent_role_id', $parent->id)->get();
        foreach ($childMetas as $childMeta) {
            $childRole = Role::find($childMeta->role_id);
            if ($childRole === null) {
                continue;
            }

            $affected = $childRole->permissions
                ->whereInstanceOf(SpatiePermission::class)
                ->filter(fn (SpatiePermission $perm) => isset($removedIds[$perm->id]));

            if ($affected->isNotEmpty() && ! $actorIsSuperAdmin && $childMeta->is_locked) {
                throw new ClearanceProtectedResourceException(
                    "Cannot remove permission(s) from role [{$parent->name}]: its child role [{$childRole->name}] is locked."
                );
            }

            foreach ($affected as $perm) {
                $this->revokeAndUnpin($childRole, $perm);
            }
        }
    }

    /**
     * Revokes a permission from a role and deletes the forced_on contextual overrides of that role and permission.
     *
     * The forced_off overrides stay: a deny keeps winning (per-context deny is authoritative).
     */
    private function revokeAndUnpin(Role $role, SpatiePermission $permission): void
    {
        $this->permissions->revokeFromRole($role, $permission);

        UserContextPermissionOverride::where('role_id', $role->id)
            ->where('permission_id', $permission->id)
            ->where('type', UserContextPermissionOverride::TYPE_FORCED_ON)
            ->delete();
    }

    /**
     * Whether the role is the current default role: its id is the stored default role id.
     */
    public function isDefaultRole(Role $role): bool
    {
        return (string) $role->getKey() === (string) ClearanceSettings::get('default_role_id');
    }
}
