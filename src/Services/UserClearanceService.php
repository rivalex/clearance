<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Services;

use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Rivalex\Clearance\Clearance;
use Rivalex\Clearance\Exceptions\ClearanceConfigException;
use Rivalex\Clearance\Exceptions\ClearanceProtectedResourceException;
use Rivalex\Clearance\Exceptions\ClearanceScopeViolationException;
use Rivalex\Clearance\Models\RoleMeta;
use Rivalex\Clearance\Models\UserContextPermissionOverride;
use Rivalex\Clearance\Models\UserRoleContext;
use Rivalex\Clearance\Support\Identity;
use Rivalex\Clearance\Support\SpatieSchema;
use Rivalex\Clearance\Support\SuperAdmin;
use Spatie\Permission\Exceptions\GuardDoesNotMatch;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class UserClearanceService
{
    private readonly ContextService $contexts;

    private readonly PermissionService $permissions;

    private readonly RoleService $roles;

    /**
     * @param  ContextService|null  $contexts  Resolver whose request memo is flushed after every write; resolved from the container when omitted.
     * @param  PermissionService|null  $permissions  Source of the non-delegable rule; resolved from the container when omitted.
     * @param  RoleService|null  $roles  Source of the role deletion rule; resolved from the container when omitted.
     */
    public function __construct(?ContextService $contexts = null, ?PermissionService $permissions = null, ?RoleService $roles = null)
    {
        $this->contexts = $contexts ?? Container::getInstance()->make(ContextService::class);
        $this->permissions = $permissions ?? Container::getInstance()->make(PermissionService::class);
        $this->roles = $roles ?? Container::getInstance()->make(RoleService::class);
    }

    /**
     * Assign a global (non-contextual) role to a user via Spatie.
     *
     * No actor ceiling. Use only from trusted code paths (installer, seeders, host system
     * actions). User-facing code must call assignGlobalRoleAs(). A unique-constraint violation from a
     * concurrent identical assignment (double submit) counts as already assigned.
     *
     * @throws ClearanceScopeViolationException if the role's declared scope is contextual.
     * @throws GuardDoesNotMatch if the role belongs to another guard than the user.
     */
    public function assignGlobalRole(Authenticatable $user, Role $role): void
    {
        $this->assertGlobalScope($role);

        if (! method_exists($user, 'assignRole')) {
            throw new \LogicException('The user model must use the Spatie HasRoles trait (via HasClearance).');
        }

        try {
            // The inner transaction is a savepoint: on PostgreSQL a failed insert would otherwise abort the outer one.
            $role->getConnection()->transaction(fn () => $user->assignRole($role));
        } catch (UniqueConstraintViolationException) {
            // A concurrent request assigned the role first: the target state is reached.
            if (method_exists($user, 'unsetRelation')) {
                $user->unsetRelation('roles');
            }
        } finally {
            $this->contexts->flush();
        }
    }

    /**
     * Assign a global role on behalf of an actor, enforcing the actor ceiling.
     *
     * Order: scope check, refusal of a system-assigned-only role (super_admin included), self-assignment by a non-super_admin, the super_admin role (only
     * a super_admin may assign it, whatever permissions the actor holds), then every permission
     * of the role must be held by the actor (global check, hasPermissionTo()) unless the actor
     * is super_admin. Then delegates to assignGlobalRole(). The checks and the write run in one transaction that starts
     * by locking the role row and reloading its permissions.
     *
     * @throws ClearanceScopeViolationException if the scope, the self-assignment rule or the ceiling is violated.
     * @throws ClearanceProtectedResourceException if the role contains a non-delegable permission.
     */
    public function assignGlobalRoleAs(Authenticatable $actor, Authenticatable $user, Role $role): void
    {
        // The role row is locked and its permissions reloaded first, so the checks see fresh permissions and a
        // concurrent change of the role (RoleService::syncPermissions() takes the same lock) waits.
        $role->getConnection()->transaction(function () use ($actor, $user, $role): void {
            $this->lockRole($role);

            $this->assertGlobalScope($role);
            $this->assertNotSystemAssignedOnly($role);
            $actorIsSuperAdmin = $this->assertNotSelfAssignment($actor, $user);
            $this->assertGlobalRoleCeiling($actor, $role, $actorIsSuperAdmin, 'assign');

            $this->assignGlobalRole($user, $role);
        });
    }

    /**
     * Check, without a target user, that an actor may assign a global role.
     *
     * Runs the scope check, the refusal of a system-assigned-only role,
     * the super_admin role rule and the permission ceiling, exactly as assignGlobalRoleAs() does,
     * minus the self-assignment rule, which needs a target. Used before a bulk write.
     *
     * @throws ClearanceScopeViolationException if the scope, the super_admin rule or the ceiling is violated.
     * @throws ClearanceProtectedResourceException if the role contains a non-delegable permission.
     */
    public function assertCanAssignGlobalRole(Authenticatable $actor, Role $role): void
    {
        $this->assertGlobalScope($role);
        $this->assertNotSystemAssignedOnly($role);

        $actorIsSuperAdmin = SuperAdmin::is($actor);

        $this->assertGlobalRoleCeiling($actor, $role, $actorIsSuperAdmin, 'assign');
    }

    /**
     * Check that a role may be used as the default role for new or existing users.
     *
     * The super_admin role, any system-assigned-only role and any role holding a
     * clearance-* or the configured access permission can never be a default role, and a contextual role cannot be assigned globally.
     *
     * @throws ClearanceProtectedResourceException if the role is super_admin, system-assigned only or holds a clearance-* or access permission.
     * @throws ClearanceScopeViolationException if the role is contextual.
     */
    public function assertCanBeDefaultRole(Role $role): void
    {
        if (SuperAdmin::isRole($role)) {
            throw new ClearanceProtectedResourceException('The super_admin role cannot be the default role.');
        }

        foreach ($role->permissions as $permission) {
            if ($this->permissions->isProtected($permission->name)) {
                throw new ClearanceProtectedResourceException(
                    "Role [{$role->name}] contains the protected permission [{$permission->name}] and cannot be the default role."
                );
            }
        }

        $this->assertNotSystemAssignedOnly($role);
        $this->assertGlobalScope($role);
    }

    /**
     * Assign a global role to many users on behalf of an actor, enforcing the actor ceiling.
     *
     * The default-role rule and the actor ceiling are checked per call, inside its transaction, after locking the
     * role row (which reloads its permissions). The writes run in the same transaction on the Spatie connection. A non-super_admin actor is skipped
     * (self-assignment rule), and so is any user that already has the role or cannot hold roles.
     *
     * @param  iterable<Authenticatable>  $users
     * @return int The number of users the role was assigned to.
     *
     * @throws ClearanceScopeViolationException if the scope, the super_admin rule or the ceiling is violated.
     * @throws ClearanceProtectedResourceException if the role is super_admin or contains a non-delegable permission.
     */
    public function bulkAssignGlobalRoleAs(Authenticatable $actor, Role $role, iterable $users): int
    {
        $actorIsSuperAdmin = SuperAdmin::is($actor);

        try {
            return $role->getConnection()->transaction(function () use ($actor, $role, $users, $actorIsSuperAdmin): int {
                $this->lockRole($role);
                $this->assertCanBeDefaultRole($role);
                $this->assertCanAssignGlobalRole($actor, $role);

                $assigned = 0;

                foreach ($users as $user) {
                    if (! $actorIsSuperAdmin && (string) $user->getAuthIdentifier() === (string) $actor->getAuthIdentifier()) {
                        continue;
                    }

                    if (! method_exists($user, 'assignRole') || (method_exists($user, 'hasRole') && $user->hasRole($role))) {
                        continue;
                    }

                    $this->assignGlobalRole($user, $role);
                    $assigned++;
                }

                return $assigned;
            });
        } finally {
            $this->contexts->flush();
        }
    }

    /**
     * Remove a global role from user and revoke all direct permissions for that guard.
     *
     * No actor bound. Use only from trusted code paths. User-facing code must call
     * removeGlobalRoleAs().
     */
    public function removeGlobalRole(Authenticatable $user, Role $role): void
    {
        if (! method_exists($user, 'permissions') || ! method_exists($user, 'removeRole') || ! method_exists($user, 'revokePermissionTo')) {
            throw new \LogicException('The user model must use the Spatie HasRoles trait (via HasClearance).');
        }

        $directPermNames = $user->permissions()
            ->where('guard_name', $role->guard_name)
            ->pluck('name')
            ->toArray();

        try {
            $user->removeRole($role);

            if (! empty($directPermNames)) {
                $user->revokePermissionTo($directPermNames);
            }
        } finally {
            $this->contexts->flush();
        }
    }

    /**
     * Remove a global role on behalf of an actor, following the grant ceiling.
     *
     * Every check runs before any write. The super_admin role can be removed only by a
     * super_admin and never from its last holder. A system-assigned-only role can be
     * removed only by a super_admin. For any other role a non-super_admin actor must pass the same
     * permission ceiling as for assigning it. The target must hold the role globally,
     * and only a super_admin may change the roles of a super_admin. The target's direct permissions
     * of the role's guard are revoked only when the role is the last global role the target holds
     * on that guard; a non-super_admin actor must then hold every one of them, otherwise the whole
     * removal is refused. Unlike removeGlobalRole(), direct permissions are kept while another
     * global role on the same guard remains. The writes run in one transaction.
     *
     * @throws ClearanceScopeViolationException if a non-super_admin actor targets super_admin or a super_admin user, a role or a direct permission above their ceiling, or the target does not hold the role.
     * @throws ClearanceProtectedResourceException if the removal would leave super_admin without a holder, or the role is system-assigned only and the actor is not super_admin.
     */
    public function removeGlobalRoleAs(Authenticatable $actor, Authenticatable $user, Role $role): void
    {
        $actorIsSuperAdmin = SuperAdmin::is($actor);

        try {
            // The checks (the last-super_admin check locks the holder rows) and the write share one transaction.
            $role->getConnection()->transaction(function () use ($actor, $user, $role, $actorIsSuperAdmin): void {
                $directPermissions = $this->assertCanRemoveGlobalRole($actor, $user, $role, $actorIsSuperAdmin);

                $this->writeGlobalRoleRemoval($user, $role, $directPermissions);
            });
        } finally {
            $this->contexts->flush();
        }
    }

    /**
     * Remove every holder of a role on behalf of an actor, as the first half of deleting the role.
     *
     * Every check runs before the first write: the role must be deletable (RoleService::assertDeletable(),
     * so the super_admin role and a locked role are refused), every global holder must pass the checks of
     * removeGlobalRoleAs(), every contextual binding the checks of removeContextualAs(), and a binding that
     * carries forced_off rows can be removed only by a super_admin (the deny would disappear).
     * Then one transaction on the Spatie connection performs the removals with the semantics of
     * removeGlobalRoleAs() (including its direct-permission rule) and removeContextualAs(). The role row is locked
     * first and the holders and bindings are read after the lock. A non-super_admin actor must also be allowed to
     * manage every resolved holder under 'clearance.user_scope', and the user behind a binding must not be a
     * super_admin and must be manageable too (a user record that cannot be found or resolved is skipped).
     *
     * Holders and contexts are looked up ignoring global scopes and including soft-deleted records. A holder
     * row whose class is not an Eloquent model or whose record does not exist (an orphan), and a binding whose
     * context record does not exist, are tolerated and deleted in the same transaction: an orphan holder row skips
     * every per-holder check, an orphan binding skips only the context ceiling and keeps the system-assigned-only
     * and forced_off refusals. A record that exists but lacks
     * the Spatie role methods is refused.
     *
     * @throws ClearanceScopeViolationException if a holder or a context is above the ceiling of the actor, or an existing holder cannot be resolved to a model with Spatie role methods.
     * @throws ClearanceProtectedResourceException if the role is super_admin, locked or system-assigned only (for a non-super_admin actor), or a binding carries forced_off rows and the actor is not super_admin.
     */
    public function detachRoleHoldersAs(Authenticatable $actor, Role $role): void
    {
        $this->roles->assertDeletable($role);

        try {
            // Checks and removals share one transaction, so a refusal after the holder resolution
            // leaves nothing written.
            $role->getConnection()->transaction(function () use ($actor, $role): void {
                // Lock the role row first, then read the holders and bindings: a concurrent assignment waits.
                $this->lockRole($role);

                $actorIsSuperAdmin = SuperAdmin::is($actor);

                ['holders' => $holders, 'orphans' => $orphans] = $this->resolveHolderRows($role, $this->globalHolderRows($role), 'remove', true);
                $directByHolder = [];

                foreach ($holders as $index => $holder) {
                    if (! $holder instanceof Authenticatable) {
                        throw new ClearanceScopeViolationException(
                            "Cannot remove role [{$role->name}]: holder [".get_class($holder).'#'.$holder->getKey().'] cannot be resolved.'
                        );
                    }

                    $directByHolder[$index] = $this->assertCanRemoveGlobalRole($actor, $holder, $role, $actorIsSuperAdmin);

                    if (! $actorIsSuperAdmin) {
                        $this->assertHolderManageable($actor, $holder, $role);
                    }
                }

                $bindings = UserRoleContext::where('role_id', $role->id)->get();

                // A binding whose context no longer exists skips only the context ceiling (nothing to check against):
                // it keeps the system-assigned-only refusal and the forced_off refusal below, and is deleted with the rest.
                foreach ($bindings->unique(static fn (UserRoleContext $binding): string => $binding->context_type.'#'.$binding->context_id) as $binding) {
                    $context = $this->findBindingContext($binding);

                    if ($context !== null) {
                        $this->assertCanRemoveContextual($actor, $role, $context, $actorIsSuperAdmin);
                    } elseif (! $actorIsSuperAdmin) {
                        $this->assertSystemAssignedOnlyRemovableBySuperAdmin($role);
                    }
                }

                if (! $actorIsSuperAdmin) {
                    foreach ($bindings as $binding) {
                        $carriesDeny = UserContextPermissionOverride::forSubject($binding->user_id, (int) $role->id, (string) $binding->context_type, $binding->context_id)
                            ->where('type', UserContextPermissionOverride::TYPE_FORCED_OFF)
                            ->exists();

                        if ($carriesDeny) {
                            throw new ClearanceProtectedResourceException(
                                "Cannot remove role [{$role->name}]: a binding carries denied permissions and only a super_admin may remove it."
                            );
                        }
                    }

                    $this->assertBindingUsersManageable($actor, $role, $bindings, 'remove');
                }

                foreach ($holders as $index => $holder) {
                    /** @var Model&Authenticatable $holder checked by the validation loop above */
                    $this->writeGlobalRoleRemoval($holder, $role, $directByHolder[$index]);
                }

                foreach ($orphans as $row) {
                    $role->getConnection()
                        ->table(SpatieSchema::table('model_has_roles'))
                        ->where(SpatieSchema::rolePivotKey(), $role->id)
                        ->where('model_type', $row->model_type)
                        ->where(SpatieSchema::modelMorphKey(), $row->model_id)
                        ->delete();
                }

                foreach ($bindings as $binding) {
                    UserContextPermissionOverride::forSubject($binding->user_id, (int) $role->id, (string) $binding->context_type, $binding->context_id)
                        ->delete();

                    $binding->delete();
                }
            });
        } finally {
            $this->contexts->flush();
        }
    }

    /**
     * Checks that an actor may remove a global role from a user and returns the direct permissions to revoke.
     *
     * Shared by removeGlobalRoleAs() and detachRoleHoldersAs(), so the rules exist once.
     *
     * @return list<string>
     *
     * @throws ClearanceScopeViolationException
     * @throws ClearanceProtectedResourceException
     */
    private function assertCanRemoveGlobalRole(Authenticatable $actor, Authenticatable $user, Role $role, bool $actorIsSuperAdmin): array
    {
        if (! method_exists($user, 'hasRole') || ! method_exists($user, 'roles') || ! method_exists($user, 'permissions') || ! method_exists($user, 'removeRole') || ! method_exists($user, 'revokePermissionTo')) {
            throw new \LogicException('The user model must use the Spatie HasRoles trait (via HasClearance).');
        }

        if ($role->name === SuperAdmin::ROLE && $role->guard_name === SuperAdmin::guard()) {
            if (! $actorIsSuperAdmin) {
                throw new ClearanceScopeViolationException('Only super_admin may remove the super_admin role.');
            }

            $this->assertKeepsAnotherSuperAdmin($user, $role);
        }

        if (! $actorIsSuperAdmin) {
            $this->assertSystemAssignedOnlyRemovableBySuperAdmin($role);
            $this->assertGlobalRoleCeiling($actor, $role, false, 'remove');
        }

        if (! $user->hasRole($role)) {
            throw new ClearanceScopeViolationException("User does not hold role [{$role->name}].");
        }

        if (! $actorIsSuperAdmin && SuperAdmin::is($user)) {
            throw new ClearanceScopeViolationException('Only super_admin may change the roles of a super_admin.');
        }

        $directPermissions = $this->directPermissionsToRevoke($user, $role);

        if ($directPermissions !== [] && ! $actorIsSuperAdmin) {
            $missing = array_values(array_filter(
                $directPermissions,
                static fn (string $name): bool => ! (method_exists($actor, 'hasPermissionTo') && $actor->hasPermissionTo($name, $role->guard_name)),
            ));

            if ($missing !== []) {
                throw new ClearanceScopeViolationException(
                    "Cannot remove role [{$role->name}]: the user holds direct permission(s) [".implode(', ', $missing).'] that you do not hold.'
                );
            }
        }

        return $directPermissions;
    }

    /**
     * Writes the removal of a global role and of the direct permissions chosen by assertCanRemoveGlobalRole().
     *
     * @param  list<string>  $directPermissions
     */
    private function writeGlobalRoleRemoval(Authenticatable $user, Role $role, array $directPermissions): void
    {
        if (! method_exists($user, 'removeRole') || ! method_exists($user, 'revokePermissionTo')) {
            throw new \LogicException('The user model must use the Spatie HasRoles trait (via HasClearance).');
        }

        $user->removeRole($role);

        if ($directPermissions !== []) {
            $user->revokePermissionTo($directPermissions);
        }
    }

    /**
     * Looks a record up by class and key, ignoring the host's global scopes and including soft-deleted records.
     *
     * A tenant scope or soft deletion must never make a real record look missing, otherwise a holder or a
     * context of another tenant would be treated as an orphan and skip its checks.
     *
     * @param  class-string<Model>  $class
     */
    private function findUnscoped(string $class, mixed $key): ?Model
    {
        // withoutGlobalScopes() also removes the SoftDeletingScope, so trashed records are found too.
        return $class::withoutGlobalScopes()->find($key);
    }

    /**
     * Resolves a morph type (morph map first) to an Eloquent model class, or null when it is not one.
     *
     * @return class-string<Model>|null
     */
    private function modelClassFor(mixed $type): ?string
    {
        if (! is_string($type)) {
            return null;
        }

        $class = Relation::getMorphedModel($type) ?? $type;

        return class_exists($class) && is_subclass_of($class, Model::class) ? $class : null;
    }

    /**
     * Reads the global holder rows (model_id, model_type) of a role.
     *
     * @return Collection<int, \stdClass>
     */
    private function globalHolderRows(Role $role): Collection
    {
        return $role->getConnection()
            ->table(SpatieSchema::table('model_has_roles'))
            ->where(SpatieSchema::rolePivotKey(), $role->id)
            ->get([SpatieSchema::modelMorphKey().' as model_id', 'model_type']);
    }

    /**
     * Resolves holder rows (model_id, model_type) to models.
     *
     * With $tolerateOrphans (detach only) a row whose class is not an Eloquent model, or whose record does not
     * exist, is returned in `orphans` instead of throwing; a record that exists but lacks the Spatie role
     * methods is always refused.
     *
     * @param  Collection<int, \stdClass>  $holderRows
     * @param  string  $verb  Used in the error message ("remove" or "reassign").
     * @return array{holders: list<Model>, orphans: list<\stdClass>}
     *
     * @throws ClearanceScopeViolationException
     */
    private function resolveHolderRows(Role $role, Collection $holderRows, string $verb, bool $tolerateOrphans = false): array
    {
        $holders = [];
        $orphans = [];

        foreach ($holderRows as $row) {
            $modelClass = $this->modelClassFor($row->model_type);
            $holder = $modelClass !== null ? $this->findUnscoped($modelClass, $row->model_id) : null;

            if ($holder === null && $tolerateOrphans) {
                $orphans[] = $row;

                continue;
            }

            if (! $holder instanceof Model || ! method_exists($holder, 'removeRole') || ! method_exists($holder, 'assignRole')) {
                throw new ClearanceScopeViolationException(
                    "Cannot {$verb} role [{$role->name}]: holder [{$row->model_type}#{$row->model_id}] cannot be resolved."
                );
            }

            $holders[] = $holder;
        }

        return ['holders' => $holders, 'orphans' => $orphans];
    }

    /**
     * Resolves the context record of a binding, or null when its class or record does not exist.
     */
    private function findBindingContext(UserRoleContext $binding): ?Model
    {
        $class = $this->modelClassFor((string) $binding->context_type);

        return $class !== null ? $this->findUnscoped($class, $binding->context_id) : null;
    }

    /**
     * Resolves the distinct context models the bindings of a role live in (strict: reassign).
     *
     * @param  Collection<int, UserRoleContext>  $bindings
     * @param  string  $verb  Used in the error message ("remove" or "reassign").
     * @return list<Model>
     *
     * @throws ClearanceScopeViolationException if a context cannot be resolved.
     */
    private function resolveBindingContexts(Role $role, Collection $bindings, string $verb): array
    {
        $contexts = [];

        foreach ($bindings->unique(static fn (UserRoleContext $binding): string => $binding->context_type.'#'.$binding->context_id) as $binding) {
            $type = (string) $binding->context_type;
            $context = $this->findBindingContext($binding);

            if (! $context instanceof Model) {
                throw new ClearanceScopeViolationException(
                    "Cannot {$verb} role [{$role->name}]: context [{$type}#{$binding->context_id}] cannot be resolved."
                );
            }

            $contexts[] = $context;
        }

        return $contexts;
    }

    /**
     * Names of the direct permissions to revoke when a global role is removed.
     *
     * The target's direct permissions on the role's guard, but only when the target holds no
     * other global role on that guard; otherwise an empty list.
     *
     * @return list<string>
     */
    private function directPermissionsToRevoke(Authenticatable $user, Role $role): array
    {
        if (! method_exists($user, 'roles') || ! method_exists($user, 'permissions')) {
            throw new \LogicException('The user model must use the Spatie HasRoles trait (via HasClearance).');
        }

        $otherRoleOnGuard = $user->roles()
            ->where('guard_name', $role->guard_name)
            ->where($role->getQualifiedKeyName(), '!=', $role->getKey())
            ->exists();

        if ($otherRoleOnGuard) {
            return [];
        }

        return $user->permissions()
            ->where('guard_name', $role->guard_name)
            ->pluck('name')
            ->all();
    }

    /**
     * Create a UserRoleContext binding (user, role, context model instance).
     *
     * No actor ceiling. Use only from trusted code paths (installer, seeders, host system
     * actions). User-facing code must call assignContextualAs().
     *
     * Runs in one transaction: when no binding exists for the tuple, its orphan forced_on rows are deleted first
     * (forced_off rows are kept), then the binding is created with createOrFirst(), so an identical concurrent
     * call returns the existing row.
     *
     * @throws ClearanceScopeViolationException if the role is global-only or context type not allowed.
     * @throws \LogicException if the user is not an instance of the configured user model.
     */
    public function assignContextual(Authenticatable $user, Role $role, Model $context): UserRoleContext
    {
        $this->assertIntegerKeys($user, $context);
        $this->assertContextualSubject($user);
        $this->assertContextualScope($role, $context);
        $contextClass = get_class($context);
        $tuple = [
            'user_id' => $user->getAuthIdentifier(),
            'role_id' => $role->id,
            'context_type' => $contextClass,
            'context_id' => $context->getKey(),
        ];

        try {
            return UserRoleContext::query()->getConnection()->transaction(function () use ($user, $role, $context, $contextClass, $tuple): UserRoleContext {
                // Forced_on rows left behind by a binding removed outside the service must not come back with a
                // new binding. Forced_off rows stay: a returning deny fails closed.
                if (UserRoleContext::query()->where($tuple)->lockForUpdate()->first() === null) {
                    UserContextPermissionOverride::forSubject($user->getAuthIdentifier(), (int) $role->id, $contextClass, $context->getKey())
                        ->where('type', UserContextPermissionOverride::TYPE_FORCED_ON)
                        ->delete();
                }

                return UserRoleContext::createOrFirst($tuple);
            });
        } finally {
            $this->contexts->flush();
        }
    }

    /**
     * Create a contextual binding on behalf of an actor, enforcing the actor ceiling.
     *
     * Order: scope checks, refusal of a system-assigned-only role (super_admin included), self-assignment by a non-super_admin, the super_admin role (only
     * a super_admin may assign it, whatever permissions the actor holds), then every permission
     * of the role must be held by the actor in the target context, meaning present in
     * ContextService::resolveFor(), which includes the actor's Spatie direct permissions and
     * applies the contextual forced_off, never hasPermissionTo(),
     * unless the actor is super_admin. Then delegates to assignContextual(). The checks and the write run in one
     * transaction that starts by locking the role row and reloading its permissions.
     *
     * @throws ClearanceScopeViolationException if the scope, the self-assignment rule or the ceiling is violated.
     * @throws ClearanceProtectedResourceException if the role contains a non-delegable permission.
     */
    public function assignContextualAs(Authenticatable $actor, Authenticatable $user, Role $role, Model $context): UserRoleContext
    {
        // The role row is locked and its permissions reloaded first (see assignGlobalRoleAs()).
        return $role->getConnection()->transaction(function () use ($actor, $user, $role, $context): UserRoleContext {
            $this->lockRole($role);

            $this->assertIntegerKeys($user, $context);
            $this->assertContextualScope($role, $context);
            $this->assertNotSystemAssignedOnly($role);
            $actorIsSuperAdmin = $this->assertNotSelfAssignment($actor, $user);
            $this->assertSuperAdminRoleRule($role, $actorIsSuperAdmin);

            if (! $actorIsSuperAdmin) {
                $this->assertContextualRoleCeiling($actor, $role, $context, 'assign');
            }

            return $this->assignContextual($user, $role, $context);
        });
    }

    /**
     * Move every holder of a role to another role on behalf of an actor, enforcing the actor ceiling.
     *
     * Used before a role is deleted with reassignment. Everything runs in one transaction on the Spatie
     * connection: both role rows are locked first (in ascending key order), then the holders and bindings
     * are read. Every check runs before the first write: the target is not system-assigned only, the
     * super_admin role rule, a non-super_admin actor does not hold the role being replaced, and the target
     * is valid for the global holders (scope and permission ceiling) and for every context the contextual
     * bindings live in (scope and contextual permission ceiling). A non-super_admin actor must also pass the
     * removal ceiling on the replaced role (globally and in every binding context), no holder (global or
     * behind a binding) may be a super_admin, and the actor must be allowed to manage every resolved holder
     * under 'clearance.user_scope' (Clearance::canActorManageUser()); one refusal refuses the whole operation.
     * A binding whose user record does not exist (an orphan) is skipped.
     * A global holder that is not Authenticatable is refused for a non-super_admin actor (the user scope cannot be checked on it).
     * Global holders keep their direct permissions (raw removeRole()/assignRole()). A contextual
     * binding is moved to the target, or deleted when the user already holds the target in that
     * context. The override rows (forced_on and forced_off) of every moved binding are re-keyed to the
     * target role, merged with its existing rows without duplicates, a forced_off winning; a forced_on row
     * whose permission the target role lacks is deleted (it would become a hidden grant).
     * The super_admin role cannot be reassigned, and a system-assigned-only role only by a super_admin.
     * A non-super_admin actor cannot reassign a role when one of its global holders is a super_admin
     * (same rule as removeGlobalRoleAs()).
     *
     * @throws ClearanceScopeViolationException if the roles are the same, or if the scope, the super_admin rule, a super_admin holder, the ceiling or a holder or context lookup fails.
     * @throws ClearanceProtectedResourceException if the target role contains a non-delegable permission, the source is the super_admin role, or the source is system-assigned only and the actor is not super_admin.
     */
    public function reassignRoleHoldersAs(Authenticatable $actor, Role $from, Role $to): void
    {
        if ($from->id === $to->id) {
            throw new ClearanceScopeViolationException('Cannot reassign a role to itself.');
        }

        if ($from->name === SuperAdmin::ROLE && $from->guard_name === SuperAdmin::guard()) {
            throw new ClearanceProtectedResourceException('The super_admin role cannot be reassigned.');
        }

        $actorIsSuperAdmin = SuperAdmin::is($actor);

        try {
            // The checks, the reads of the holders and bindings (after the lock on both role rows) and the writes
            // share one transaction, so a concurrent change of a role waits and the checks see fresh permissions.
            $from->getConnection()->transaction(function () use ($actor, $from, $to, $actorIsSuperAdmin): void {
                $this->lockRoleRows($from, $to);

                $this->assertNotSystemAssignedOnly($to);

                if (! $actorIsSuperAdmin) {
                    $this->assertSystemAssignedOnlyRemovableBySuperAdmin($from);
                }

                $this->assertSuperAdminRoleRule($to, $actorIsSuperAdmin);

                $bindings = UserRoleContext::where('role_id', $from->id)->get();
                $holderRows = $this->globalHolderRows($from);

                if (! $actorIsSuperAdmin) {
                    $holdsFrom = (method_exists($actor, 'hasRole') && $actor->hasRole($from))
                        || $bindings->contains(static fn (UserRoleContext $binding): bool => (string) $binding->user_id === (string) $actor->getAuthIdentifier());

                    if ($holdsFrom) {
                        throw new ClearanceScopeViolationException('Only super_admin may reassign a role they hold.');
                    }
                }

                $holders = [];

                if ($holderRows->isNotEmpty()) {
                    $this->assertGlobalScope($to);

                    if (! $actorIsSuperAdmin) {
                        $this->assertGlobalRoleCeiling($actor, $to, false, 'reassign');
                        $this->assertGlobalRoleCeiling($actor, $from, false, 'remove');
                    }

                    $holders = $this->resolveHolderRows($from, $holderRows, 'reassign')['holders'];

                    if (! $actorIsSuperAdmin) {
                        foreach ($holders as $holder) {
                            if ($holder instanceof Authenticatable && SuperAdmin::is($holder)) {
                                throw new ClearanceScopeViolationException('Only super_admin may change the roles of a super_admin.');
                            }
                        }

                        foreach ($holders as $holder) {
                            if (! $holder instanceof Authenticatable) {
                                throw new ClearanceScopeViolationException(
                                    "Cannot reassign role [{$from->name}]: holder [".get_class($holder).'#'.$holder->getKey().'] cannot be resolved.'
                                );
                            }

                            $this->assertHolderManageable($actor, $holder, $from);
                        }
                    }
                }

                foreach ($this->resolveBindingContexts($from, $bindings, 'reassign') as $context) {
                    $this->assertContextualScope($to, $context);

                    if (! $actorIsSuperAdmin) {
                        $this->assertContextualRoleCeiling($actor, $to, $context, 'reassign');
                        $this->assertContextualRoleCeiling($actor, $from, $context, 'remove');
                    }
                }

                if (! $actorIsSuperAdmin) {
                    $this->assertBindingUsersManageable($actor, $from, $bindings, 'reassign');
                }

                foreach ($holders as $holder) {
                    if (! method_exists($holder, 'removeRole') || ! method_exists($holder, 'assignRole')) {
                        throw new \LogicException('The user model must use the Spatie HasRoles trait (via HasClearance).');
                    }

                    $holder->removeRole($from);
                    $holder->assignRole($to);
                }

                foreach ($bindings as $binding) {
                    $alreadyHeld = UserRoleContext::where('user_id', $binding->user_id)
                        ->where('role_id', $to->id)
                        ->where('context_type', $binding->context_type)
                        ->where('context_id', $binding->context_id)
                        ->exists();

                    $this->rekeyOverrides($binding, $from, $to);

                    if ($alreadyHeld) {
                        $binding->delete();
                    } else {
                        $binding->update(['role_id' => $to->id]);
                    }
                }
            });
        } finally {
            $this->contexts->flush();
        }
    }

    /**
     * Moves the override rows of a binding from one role to another.
     *
     * Rows of the target role that already exist for the same user, context and permission are
     * merged without duplicates: a forced_off wins over a forced_on. A forced_on row whose permission the
     * target role lacks is deleted, because under the target role it would be a hidden grant; forced_off
     * rows are always re-keyed (they only deny).
     */
    private function rekeyOverrides(UserRoleContext $binding, Role $from, Role $to): void
    {
        $rows = UserContextPermissionOverride::forSubject($binding->user_id, (int) $from->id, (string) $binding->context_type, $binding->context_id)->get();
        $targetIds = $to->permissions->pluck('id')->map(fn ($id) => (int) $id)->flip()->all();

        foreach ($rows as $row) {
            if (! $row->isForcedOff() && ! isset($targetIds[(int) $row->permission_id])) {
                $row->delete();

                continue;
            }

            $existing = UserContextPermissionOverride::forSubject($binding->user_id, (int) $to->id, (string) $binding->context_type, $binding->context_id)
                ->where('permission_id', $row->permission_id)
                ->first();

            if ($existing === null) {
                $row->update(['role_id' => $to->id]);

                continue;
            }

            if ($row->isForcedOff() && ! $existing->isForcedOff()) {
                $existing->update(['type' => UserContextPermissionOverride::TYPE_FORCED_OFF]);
            }

            $row->delete();
        }
    }

    /**
     * Locks one role row (a locking read, ignored by SQLite) and reloads its permissions.
     *
     * Must run inside a transaction. It serialises the caller against a concurrent change of the role
     * (RoleService::syncPermissions() takes the same lock), and the checks that follow see fresh permissions.
     */
    private function lockRole(Role $role): void
    {
        get_class($role)::query()->whereKey($role->getKey())->lockForUpdate()->firstOrFail();

        $role->load('permissions');
    }

    /**
     * Locks two role rows in ascending key order in one statement and reloads their permissions.
     *
     * Must run inside a transaction. The fixed order keeps two opposite reassignments from deadlocking.
     */
    private function lockRoleRows(Role $from, Role $to): void
    {
        get_class($from)::query()
            ->whereKey([$from->getKey(), $to->getKey()])
            ->orderBy($from->getKeyName())
            ->lockForUpdate()
            ->get();

        $from->load('permissions');
        $to->load('permissions');
    }

    /**
     * Throws when the actor may not manage a holder under the configured user scope.
     *
     * @throws ClearanceScopeViolationException
     */
    private function assertHolderManageable(Authenticatable $actor, Authenticatable $holder, Role $role): void
    {
        if (! Container::getInstance()->make(Clearance::class)->canActorManageUser($actor, $holder)) {
            throw new ClearanceScopeViolationException(
                "Cannot change role [{$role->name}]: it is held by a user you may not manage."
            );
        }
    }

    /**
     * Checks, for a non-super_admin actor, the users behind the contextual bindings of a role.
     *
     * Each distinct user is resolved ignoring global scopes. A user that cannot be found is an orphan and is skipped.
     * A found record that is not Authenticatable is refused when reassigning and skipped when detaching. A
     * super_admin user is refused, and so is a user the actor may not manage under the configured user scope.
     *
     * @param  Collection<int, UserRoleContext>  $bindings
     * @param  string  $verb  "reassign" (an unresolvable record is refused) or "remove" (it is skipped).
     *
     * @throws ClearanceScopeViolationException
     */
    private function assertBindingUsersManageable(Authenticatable $actor, Role $role, Collection $bindings, string $verb): void
    {
        $userClass = Identity::userModel();

        if ($userClass === null) {
            return;
        }

        foreach ($bindings->pluck('user_id')->unique() as $userId) {
            $user = $this->findUnscoped($userClass, $userId);

            if ($user === null) {
                continue;
            }

            if (! $user instanceof Authenticatable) {
                if ($verb === 'reassign') {
                    throw new ClearanceScopeViolationException(
                        "Cannot reassign role [{$role->name}]: holder [".get_class($user)."#{$userId}] cannot be resolved."
                    );
                }

                continue;
            }

            if (SuperAdmin::is($user)) {
                throw new ClearanceScopeViolationException('Only super_admin may change the roles of a super_admin.');
            }

            $this->assertHolderManageable($actor, $user, $role);
        }
    }

    /**
     * Throws when removing the role from the user would leave the role without any other global holder.
     *
     * Reads the holder rows with a locking read, so it must run inside the transaction of the removal. Only holders
     * that resolve to an existing, non-trashed record of an authenticatable model class count.
     *
     * @throws ClearanceProtectedResourceException
     */
    private function assertKeepsAnotherSuperAdmin(Authenticatable $user, Role $role): void
    {
        $morphClass = $user instanceof Model ? $user->getMorphClass() : get_class($user);
        $userId = $user->getAuthIdentifier();

        $morphKey = SpatieSchema::modelMorphKey();

        // Locking read: a concurrent removal of another holder waits until this transaction ends.
        $others = $role->getConnection()
            ->table(SpatieSchema::table('model_has_roles'))
            ->where(SpatieSchema::rolePivotKey(), $role->id)
            ->lockForUpdate()
            ->get([$morphKey.' as model_id', 'model_type'])
            ->filter(static fn (\stdClass $row): bool => $row->model_type !== $morphClass || (string) $row->model_id !== (string) $userId)
            ->filter(fn (\stdClass $row): bool => $this->isActiveAuthenticatableHolder($row))
            ->count();

        if ($others < 1) {
            throw new ClearanceProtectedResourceException('The last super_admin cannot be removed.');
        }
    }

    /**
     * Whether a holder row points to an existing, non-trashed record of an authenticatable model class.
     *
     * Global scopes are ignored so a tenant scope never hides a real holder; the soft-delete column is checked
     * explicitly when the class uses soft deletes.
     */
    private function isActiveAuthenticatableHolder(\stdClass $row): bool
    {
        $class = $this->modelClassFor($row->model_type);

        if ($class === null || ! is_a($class, Authenticatable::class, true)) {
            return false;
        }

        $query = $class::withoutGlobalScopes()->whereKey($row->model_id);

        $model = new $class;

        if (in_array(SoftDeletes::class, class_uses_recursive($model), true) && method_exists($model, 'getQualifiedDeletedAtColumn')) {
            $query->whereNull($model->getQualifiedDeletedAtColumn());
        }

        return $query->exists();
    }

    /**
     * Throws when the role is system-assigned only: only a super_admin may remove it through an actor-checked path.
     *
     * @throws ClearanceProtectedResourceException
     */
    private function assertSystemAssignedOnlyRemovableBySuperAdmin(Role $role): void
    {
        if ($this->permissions->isSystemAssignedOnly($role)) {
            throw new ClearanceProtectedResourceException(
                "Role [{$role->name}] contains non-delegable permissions and can only be removed by a super_admin."
            );
        }
    }

    /**
     * Throws when the role is contextual-only.
     *
     * @throws ClearanceScopeViolationException
     */
    private function assertGlobalScope(Role $role): void
    {
        $meta = RoleMeta::where('role_id', $role->id)->first();

        if ($meta?->isContextual()) {
            throw new ClearanceScopeViolationException(
                "Role [{$role->name}] is contextual; it cannot be assigned globally."
            );
        }
    }

    /**
     * Throws when the role is global-only or does not accept the context type.
     *
     * @throws ClearanceScopeViolationException
     */
    private function assertContextualScope(Role $role, Model $context): void
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
    }

    /**
     * Throws when the role contains a non-delegable permission: such roles are assigned only by trusted code.
     *
     * @throws ClearanceProtectedResourceException
     */
    private function assertNotSystemAssignedOnly(Role $role): void
    {
        if ($this->permissions->isSystemAssignedOnly($role)) {
            throw new ClearanceProtectedResourceException(
                "Role [{$role->name}] contains non-delegable permissions and can only be assigned by the system."
            );
        }
    }

    /**
     * Throws when a non-super_admin actor assigns a role to themselves. Returns whether the actor is super_admin.
     *
     * @throws ClearanceScopeViolationException
     */
    private function assertNotSelfAssignment(Authenticatable $actor, Authenticatable $user): bool
    {
        $actorIsSuperAdmin = SuperAdmin::is($actor);

        if (! $actorIsSuperAdmin && (string) $actor->getAuthIdentifier() === (string) $user->getAuthIdentifier()) {
            throw new ClearanceScopeViolationException(
                'Only super_admin may assign roles to themselves.'
            );
        }

        return $actorIsSuperAdmin;
    }

    /**
     * Throws when a non-super_admin actor assigns the super_admin role, whatever permissions the actor holds.
     *
     * @throws ClearanceScopeViolationException
     */
    private function assertSuperAdminRoleRule(Role $role, bool $actorIsSuperAdmin): void
    {
        if (SuperAdmin::isRole($role) && ! $actorIsSuperAdmin) {
            throw new ClearanceScopeViolationException(
                'Only super_admin may assign the super_admin role.'
            );
        }
    }

    /**
     * Throws when the actor may not assign, reassign or remove the role globally: the super_admin role rule, then every permission of the role must be held by the actor (hasPermissionTo()) unless the actor is super_admin.
     *
     * @param  string  $verb  Used in the error message ("assign", "reassign" or "remove").
     *
     * @throws ClearanceScopeViolationException
     */
    private function assertGlobalRoleCeiling(Authenticatable $actor, Role $role, bool $actorIsSuperAdmin, string $verb): void
    {
        $this->assertSuperAdminRoleRule($role, $actorIsSuperAdmin);

        if (! $actorIsSuperAdmin) {
            $missing = $role->permissions
                ->reject(static fn (Permission $permission): bool => method_exists($actor, 'hasPermissionTo') && $actor->hasPermissionTo($permission))
                ->pluck('name');

            $this->assertNothingMissing($role, $missing, $verb);
        }
    }

    /**
     * Throws when the actor lacks, in the target context, a permission of the role being assigned, reassigned or removed.
     *
     * "Held" means present in ContextService::resolveFor(), which includes the actor's Spatie direct
     * permissions and applies the contextual forced_off.
     *
     * @param  string  $verb  Used in the error message ("assign", "reassign" or "remove").
     *
     * @throws ClearanceScopeViolationException
     */
    private function assertContextualRoleCeiling(Authenticatable $actor, Role $role, Model $context, string $verb): void
    {
        $held = $this->contexts->resolveFor($actor, $context, $role->guard_name);

        $missing = $role->permissions
            ->reject(static fn (Permission $permission): bool => $held->contains($permission->name))
            ->pluck('name');

        $this->assertNothingMissing($role, $missing, $verb);
    }

    /**
     * Throws, listing the names, when the actor lacks permissions of the role being assigned, reassigned or removed.
     *
     * @param  Collection<int, string>  $missing
     * @param  string  $verb  Used in the error message ("assign", "reassign" or "remove").
     *
     * @throws ClearanceScopeViolationException
     */
    private function assertNothingMissing(Role $role, Collection $missing, string $verb): void
    {
        if ($missing->isNotEmpty()) {
            throw new ClearanceScopeViolationException(
                "Cannot {$verb} role [{$role->name}]: you do not hold permission(s) [{$missing->implode(', ')}]."
            );
        }
    }

    /**
     * Remove a contextual binding on behalf of an actor, following the grant ceiling.
     *
     * Every check runs before any write, inside the write transaction after a locking read of the binding row.
     * A system-assigned-only role can be removed
     * only by a super_admin; for any other role a non-super_admin actor must hold every permission
     * of the role in the target context. A binding that carries forced_off rows (a deny set
     * by host code) can be removed only by a super_admin, because removing it would drop the
     * deny. A non-super_admin actor cannot remove a binding of a super_admin user. Then delegates to removeContextual().
     *
     * @throws ClearanceScopeViolationException if the actor lacks, in the context, a permission of the role, or the user is a super_admin and the actor is not.
     * @throws ClearanceProtectedResourceException if the role is system-assigned only and the actor is not super_admin, or the binding carries forced_off rows and the actor is not super_admin.
     * @throws \LogicException if the user is not an instance of the configured user model.
     */
    public function removeContextualAs(Authenticatable $actor, Authenticatable $user, Role $role, Model $context): void
    {
        $this->assertIntegerKeys($user, $context);
        $this->assertContextualSubject($user);

        $actorIsSuperAdmin = SuperAdmin::is($actor);

        if (! $actorIsSuperAdmin && SuperAdmin::is($user)) {
            throw new ClearanceScopeViolationException('Only super_admin may change the roles of a super_admin.');
        }

        $userId = $user->getAuthIdentifier();
        $roleId = (int) $role->id;
        $contextType = get_class($context);
        $contextId = $context->getKey();

        // The ceiling and the deny check run in the same transaction as the delete, after a locking read of the
        // binding row, so a deny written meanwhile is seen (a missing binding is not an error).
        UserRoleContext::query()->getConnection()->transaction(function () use ($actor, $user, $role, $context, $actorIsSuperAdmin, $userId, $roleId, $contextType, $contextId): void {
            UserRoleContext::where('user_id', $userId)
                ->where('role_id', $roleId)
                ->where('context_type', $contextType)
                ->where('context_id', $contextId)
                ->lockForUpdate()
                ->first();

            $this->assertCanRemoveContextual($actor, $role, $context, $actorIsSuperAdmin);

            if (! $actorIsSuperAdmin) {
                $carriesDeny = UserContextPermissionOverride::forSubject($userId, $roleId, $contextType, $contextId)
                    ->where('type', UserContextPermissionOverride::TYPE_FORCED_OFF)
                    ->exists();

                if ($carriesDeny) {
                    throw new ClearanceProtectedResourceException(
                        'This assignment carries a deny override set by the application; only super_admin can remove it.'
                    );
                }
            }

            $this->removeContextual($user, $role, $context);
        });
    }

    /**
     * Checks that an actor may remove a contextual binding of a role in a context.
     *
     * Shared by removeContextualAs() and detachRoleHoldersAs(), so the rules exist once.
     *
     * @throws ClearanceScopeViolationException if the actor lacks, in the context, a permission of the role.
     * @throws ClearanceProtectedResourceException if the role is system-assigned only and the actor is not super_admin.
     */
    private function assertCanRemoveContextual(Authenticatable $actor, Role $role, Model $context, bool $actorIsSuperAdmin): void
    {
        if (! $actorIsSuperAdmin) {
            $this->assertSystemAssignedOnlyRemovableBySuperAdmin($role);
            $this->assertContextualRoleCeiling($actor, $role, $context, 'remove');
        }
    }

    /**
     * Remove a contextual binding AND cascade-delete its UserContextPermissionOverrides.
     *
     * No actor bound. Use only from trusted code paths. User-facing code must call
     * removeContextualAs(). The binding and its overrides are deleted in one transaction, binding first.
     *
     * @throws \LogicException if the user is not an instance of the configured user model.
     */
    public function removeContextual(Authenticatable $user, Role $role, Model $context): void
    {
        $this->assertIntegerKeys($user, $context);
        $this->assertContextualSubject($user);

        $userId = $user->getAuthIdentifier();
        $roleId = (int) $role->id;
        $contextType = get_class($context);
        $contextId = $context->getKey();

        try {
            // One transaction: the binding is deleted first, then its overrides, so a failure leaves neither half-removed.
            UserRoleContext::query()->getConnection()->transaction(function () use ($userId, $roleId, $contextType, $contextId): void {
                UserRoleContext::where('user_id', $userId)
                    ->where('role_id', $roleId)
                    ->where('context_type', $contextType)
                    ->where('context_id', $contextId)
                    ->delete();

                UserContextPermissionOverride::forSubject($userId, $roleId, $contextType, $contextId)
                    ->delete();
            });
        } finally {
            $this->contexts->flush();
        }
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
     * Revocation: when a direct permission is removed, the target must hold $role
     * globally (no super_admin exemption), a non-super_admin actor cannot target a super_admin,
     * and a non-super_admin actor must hold every removed permission. Every check runs before
     * the first write and the writes run in one transaction on the role connection.
     *
     * @param  array<int>  $permissionIds
     *
     * @throws ClearanceScopeViolationException if $actor attempts to escalate their own
     *                                          or another user's privileges beyond their ceiling,
     *                                          or a removal targets a user who does not hold $role,
     *                                          a super_admin user (non-super_admin actor), or a
     *                                          permission the actor does not hold, or an added id
     *                                          is not a permission of the role's guard.
     * @throws ClearanceProtectedResourceException if a clearance-* permission or a clearance.non_delegable permission (super_admin included) is targeted.
     */
    public function syncGlobalExtraPermissions(
        Authenticatable $actor,
        Authenticatable $user,
        Role $role,
        array $permissionIds,
    ): void {
        if (! method_exists($user, 'permissions') || ! method_exists($user, 'givePermissionTo') || ! method_exists($user, 'revokePermissionTo')) {
            throw new \LogicException('The user model must use the Spatie HasRoles trait (via HasClearance).');
        }

        try {
            $role->getConnection()->transaction(function () use ($actor, $user, $role, $permissionIds): void {
                // The role row is locked first; what the checks read (role permissions, current direct permissions)
                // is read after the lock, so the checks and the writes see the same state.
                $this->lockRole($role);

                $rolePermIds = $role->permissions->pluck('id')->map(fn ($id) => (int) $id)->toArray();

                $currentDirectIds = $user->permissions()
                    ->where('guard_name', $role->guard_name)
                    ->lockForUpdate()
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->diff($rolePermIds)
                    ->values()
                    ->toArray();

                $desiredIds = array_map('intval', $permissionIds);
                $addedIds = array_diff($desiredIds, $currentDirectIds);
                $removedIds = array_diff($currentDirectIds, $desiredIds);

                if ($removedIds !== []) {
                    $this->assertCanRevokeDirectPermissions($actor, $user, $role, $removedIds);
                }

                $this->assertWithinCeiling($actor, $user, $this->loadAddedPermissions($role, $addedIds));

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
            });
        } finally {
            $this->contexts->flush();
        }
    }

    /**
     * Loads the permissions about to be added, restricted to the guard of the role.
     *
     * An id that does not exist, or exists on another guard, is refused with the first offending id.
     *
     * @param  array<int, int>  $addedIds
     * @return array<int, Permission>
     *
     * @throws ClearanceScopeViolationException if an id is not a permission of the role's guard.
     */
    private function loadAddedPermissions(Role $role, array $addedIds): array
    {
        $ids = array_values(array_unique($addedIds));

        $permissions = Permission::query()
            ->whereKey($ids)
            ->where('guard_name', $role->guard_name)
            ->get();

        if ($permissions->count() !== count($ids)) {
            $known = $permissions->pluck('id')->map(fn ($id) => (int) $id)->all();
            $missing = array_values(array_diff($ids, $known))[0];

            throw new ClearanceScopeViolationException(
                "Permission [{$missing}] does not exist on guard [{$role->guard_name}]."
            );
        }

        return $permissions->all();
    }

    /**
     * Checks that an actor may revoke direct permissions of a user under a role.
     *
     * The target must hold the role globally (no super_admin exemption), only a super_admin may
     * target a super_admin, and a non-super_admin actor must hold every permission being revoked.
     *
     * @param  array<int, int>  $removedIds
     *
     * @throws ClearanceScopeViolationException
     */
    private function assertCanRevokeDirectPermissions(Authenticatable $actor, Authenticatable $user, Role $role, array $removedIds): void
    {
        if (! method_exists($user, 'hasRole') || ! $user->hasRole($role)) {
            throw new ClearanceScopeViolationException("User does not hold role [{$role->name}].");
        }

        $actorIsSuperAdmin = SuperAdmin::is($actor);

        if (! $actorIsSuperAdmin && SuperAdmin::is($user)) {
            throw new ClearanceScopeViolationException('Only super_admin may change the roles of a super_admin.');
        }

        if ($actorIsSuperAdmin) {
            return;
        }

        $missing = [];

        foreach ($removedIds as $permId) {
            $perm = Permission::find($permId);

            if ($perm !== null && ! (method_exists($actor, 'hasPermissionTo') && $actor->hasPermissionTo($perm))) {
                $missing[] = $perm->name;
            }
        }

        if ($missing !== []) {
            throw new ClearanceScopeViolationException(
                "Cannot revoke from role [{$role->name}]: the user holds direct permission(s) [".implode(', ', $missing).'] that you do not hold.'
            );
        }
    }

    /**
     * Enforces the F2 privilege ceiling for a direct-permission grant: the actor cannot
     * touch their own direct permissions unless super_admin, cannot grant a permission
     * they do not themselves hold, and cannot grant a protected permission (clearance-* or the configured access permission).
     * A permission listed in clearance.non_delegable can never be granted this way:
     * the super_admin exemption does not cover it.
     *
     * When $actorPermissionNames is given (the actor's effective permissions in a target
     * context, see ContextService::resolveFor()), "the actor holds the permission" means the
     * name is in that collection. resolveFor() already includes the actor's direct permissions
     * and applies the contextual forced_off; never hasPermissionTo(), which
     * would ignore a contextual forced_off. When null, the actor's global hasPermissionTo() is used.
     *
     * @param  array<int, Permission|null>  $permissionsBeingGranted
     * @param  Collection<int, string>|null  $actorPermissionNames
     */
    private function assertWithinCeiling(
        Authenticatable $actor,
        Authenticatable $user,
        array $permissionsBeingGranted,
        ?Collection $actorPermissionNames = null,
    ): void {
        $actorIsSuperAdmin = SuperAdmin::is($actor);

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

            if ($this->permissions->isProtected($permission->name)) {
                throw new ClearanceProtectedResourceException(
                    "Permission [{$permission->name}] is a built-in clearance permission and cannot be granted directly."
                );
            }

            if (! $this->permissions->isDelegable($permission->name)) {
                throw new ClearanceProtectedResourceException(
                    "Permission [{$permission->name}] is non-delegable and cannot be granted through the panel."
                );
            }

            $actorHasIt = $actorPermissionNames !== null
                ? $actorPermissionNames->contains($permission->name)
                : (method_exists($actor, 'hasPermissionTo') && $actor->hasPermissionTo($permission));
            if (! $actorIsSuperAdmin && ! $actorHasIt) {
                throw new ClearanceScopeViolationException(
                    "Cannot grant permission [{$permission->name}]: you do not hold it yourself."
                );
            }
        }
    }

    /**
     * Sync per-context permission overrides (forced_on) for a user-role-context tuple.
     * Upserts forced_on rows for checked permissions; deletes forced_on rows for unchecked.
     * forced_off rows of the tuple are never touched, and a desired permission
     * that is already forced_off on the tuple is skipped, not turned into a forced_on.
     * The tuple must be bound: without a UserRoleContext row for the user, role and context
     * nothing is written (the overrides would be orphaned and never resolved). The binding
     * is read first, with a locking read inside the transaction, and its absence is reported after the other checks,
     * before any write. The current overrides and the added ids are computed inside the same transaction.
     * The added ids must be permissions of the role's guard (unknown or other-guard ids are refused),
     * and a non-super_admin actor cannot remove forced_on rows of a super_admin user.
     *
     * Security ceiling (F2 - security audit): same rules as syncGlobalExtraPermissions()
     * apply to the permissions being force-granted (forced_off removals carry no
     * escalation risk and are always allowed). "The actor holds it" is evaluated in the
     * target context: ContextService::resolveFor() (parent contexts, global roles and the actor's
     * Spatie direct permissions included, a contextual forced_off applied).
     *
     * @param  array<int>  $permissionIds  IDs of permissions to force on.
     *
     * @throws ClearanceScopeViolationException if the user has no binding for the role and context, if an added
     *                                          id is not a permission of the role's guard, if a non-super_admin actor
     *                                          removes a forced_on permission it does not hold in the context, or if
     *                                          $actor attempts to escalate their own
     *                                          or another user's privileges beyond their ceiling, or a
     *                                          non-super_admin actor removes forced_on rows of a super_admin user.
     * @throws ClearanceProtectedResourceException if a clearance-* permission or a clearance.non_delegable permission (super_admin included) is targeted.
     * @throws \LogicException if the user is not an instance of the configured user model.
     */
    public function syncContextualExtraPermissions(
        Authenticatable $actor,
        Authenticatable $user,
        Role $role,
        Model $context,
        array $permissionIds,
    ): void {
        $this->assertIntegerKeys($user, $context);
        $this->assertContextualSubject($user);

        $userId = $user->getAuthIdentifier();
        $roleId = (int) $role->id;
        $contextType = get_class($context);
        $contextId = $context->getKey();

        try {
            $this->connection()->transaction(function () use ($actor, $user, $role, $context, $permissionIds, $userId, $roleId, $contextType, $contextId): void {
                // The binding is read first, with a locking read, so a concurrent removal of the binding waits.
                // A missing binding is reported after the other checks (see below).
                $binding = UserRoleContext::where('user_id', $userId)
                    ->where('role_id', $roleId)
                    ->where('context_type', $contextType)
                    ->where('context_id', $contextId)
                    ->lockForUpdate()
                    ->first();

                $currentOnIds = UserContextPermissionOverride::forSubject($userId, $roleId, $contextType, $contextId)
                    ->where('type', UserContextPermissionOverride::TYPE_FORCED_ON)
                    ->pluck('permission_id')
                    ->map(fn ($id) => (int) $id)
                    ->toArray();

                $offIds = UserContextPermissionOverride::forSubject($userId, $roleId, $contextType, $contextId)
                    ->where('type', UserContextPermissionOverride::TYPE_FORCED_OFF)
                    ->pluck('permission_id')
                    ->map(fn ($id) => (int) $id)
                    ->toArray();

                // A forced_off is deny-authoritative and the unique index excludes type:
                // a permission already denied on the tuple is never turned into a forced_on.
                $desiredIds = array_values(array_diff(array_map('intval', $permissionIds), $offIds));

                $addedIds = array_diff($desiredIds, $currentOnIds);
                $removedIds = array_diff($currentOnIds, $desiredIds);

                if ($removedIds !== [] && ! SuperAdmin::is($actor) && SuperAdmin::is($user)) {
                    throw new ClearanceScopeViolationException('Only super_admin may change the roles of a super_admin.');
                }

                $actorPermissions = $this->contexts->resolveFor($actor, $context, $role->guard_name);

                if ($removedIds !== [] && ! SuperAdmin::is($actor)) {
                    $missing = Permission::query()->whereKey($removedIds)->pluck('name')
                        ->reject(fn ($name): bool => $actorPermissions->contains($name))
                        ->sort()
                        ->values()
                        ->all();

                    if ($missing !== []) {
                        throw new ClearanceScopeViolationException('Cannot remove pinned permission(s) ['.implode(', ', $missing).']: you do not hold them in this context.');
                    }
                }

                $permissions = $this->loadAddedPermissions($role, $addedIds);

                $this->assertWithinCeiling($actor, $user, $permissions, $actorPermissions);

                if ($binding === null) {
                    throw new ClearanceScopeViolationException('No binding for this user, role and context.');
                }

                // Only forced_on rows are removed; forced_off rows of the tuple are never touched.
                UserContextPermissionOverride::forSubject($userId, $roleId, $contextType, $contextId)
                    ->where('type', UserContextPermissionOverride::TYPE_FORCED_ON)
                    ->whereNotIn('permission_id', $desiredIds)
                    ->delete();

                // `type` is not part of the lookup: the unique index excludes it, so a concurrent forced_off is
                // returned as it is and stays deny-authoritative; a concurrent identical forced_on is not an error.
                foreach ($addedIds as $permId) {
                    UserContextPermissionOverride::query()->createOrFirst([
                        'user_id' => $userId,
                        'role_id' => $roleId,
                        'permission_id' => $permId,
                        'context_type' => $contextType,
                        'context_id' => $contextId,
                    ], [
                        'type' => UserContextPermissionOverride::TYPE_FORCED_ON,
                    ]);
                }
            });
        } finally {
            $this->contexts->flush();
        }
    }

    /**
     * Refuses a user or context whose key is not an integer (an int, or a string of digits).
     * The contextual tables store `user_id` and `context_id` as integers, so any other key
     * could be coerced and match another row.
     *
     * @throws ClearanceConfigException
     */
    private function assertIntegerKeys(Authenticatable $user, Model $context): void
    {
        foreach ([[$user, $user->getAuthIdentifier()], [$context, $context->getKey()]] as [$subject, $key]) {
            if (is_int($key) || (is_string($key) && preg_match('/^[0-9]+$/', $key) === 1)) {
                continue;
            }

            throw new ClearanceConfigException(
                'Contextual roles and permissions need integer keys: ['.get_class($subject).'] key ['.(string) $key.'] is not an integer.'
            );
        }
    }

    /**
     * Throws when the user is not an instance of the configured user model: contextual rows are keyed
     * by user id only, so any other model would read and write the bindings of an unrelated user.
     *
     * @throws \LogicException
     */
    private function assertContextualSubject(Authenticatable $user): void
    {
        if (! $this->contexts->isContextualSubject($user)) {
            throw new \LogicException(
                'Contextual roles and permissions are available only for the configured user model ['.get_class($user).' is not it].'
            );
        }
    }

    /**
     * Connection of the Clearance override table, used for transactions without facades.
     */
    private function connection(): ConnectionInterface
    {
        return UserContextPermissionOverride::query()->getConnection();
    }
}
