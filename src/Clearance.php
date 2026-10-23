<?php

declare(strict_types=1);

namespace Rivalex\Clearance;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Rivalex\Clearance\Contracts\ClearanceUserScope;
use Rivalex\Clearance\Exceptions\ClearanceConfigException;
use Rivalex\Clearance\Exceptions\ClearanceContextChainException;
use Rivalex\Clearance\Models\UserContextPermissionOverride;
use Rivalex\Clearance\Models\UserRoleContext;
use Rivalex\Clearance\Services\ContextService;
use Rivalex\Clearance\Services\GuardService;
use Rivalex\Clearance\Support\Identity;
use Rivalex\Clearance\Support\SuperAdmin;
use Spatie\Permission\Models\Permission;

class Clearance
{
    /**
     * When true, a guest (no authenticated user) passes canAccess(), canPerform() and managesContext().
     * Set only by the package test suite; never set in an application.
     */
    public static bool $bypassGuestChecksForTests = false;

    /**
     * Memo of permission row existence checks, keyed by `guard|name`.
     *
     * @var array<string, bool>
     */
    private array $writePermissionExists = [];

    public function __construct(
        private readonly ContextService $context,
        private readonly GuardService $guards,
    ) {}

    /**
     * Checks if a user has a permission within a context model.
     * Optional $guard restricts resolution to roles with that guard_name.
     */
    public function canIn(
        Authenticatable $user,
        string $permission,
        Model $context,
        ?string $guard = null,
    ): bool {
        return $this->context->canIn($user, $permission, $context, $guard);
    }

    /**
     * Returns effective permission names for a user in a context model.
     *
     * @return Collection<int, string>
     */
    public function resolveFor(
        Authenticatable $user,
        Model $context,
        ?string $guard = null,
    ): Collection {
        return $this->context->resolveFor($user, $context, $guard);
    }

    /**
     * Returns the names of all managed authentication guards.
     *
     * @return array<int, string>
     */
    public function guards(): array
    {
        return $this->guards->names();
    }

    /**
     * Checks if the authenticated user has basic panel access (read-only level).
     * Use in render() methods of display-only components as defense-in-depth.
     */
    public function canAccess(): bool
    {
        if (self::$bypassGuestChecksForTests && auth()->user() === null) {
            return true;
        }

        $user = auth()->user();

        if ($user === null || ! method_exists($user, 'hasPermissionTo')) {
            return false;
        }

        return $this->holdsAccessPermission($user);
    }

    /**
     * Whether the user holds the configured access permission on the identity guard. False when the permission row does
     * not exist on that guard (fail closed) or the user model has no Spatie permission methods. No guest bypass.
     */
    public function holdsAccessPermission(Authenticatable $user): bool
    {
        if (! method_exists($user, 'hasPermissionTo')) {
            return false;
        }

        $guard = Identity::guard();
        $access = (string) config('clearance.access_permission', 'clearance-access');

        if (! $this->permissionExistsOn($access, $guard)) {
            return false;
        }

        return (bool) $user->hasPermissionTo($access, $guard);
    }

    /**
     * Checks if the authenticated user can perform a panel-level write action.
     *
     * Looks for 'clearance-{action}-write' first; falls back to 'clearance-access'
     * when the fine-grained permission has not been seeded (backwards-compatible).
     * Both permissions are checked for existence and holding on Identity::guard(). Returns false
     * when the access permission has no row on that guard.
     */
    public function canPerform(string $action): bool
    {
        $user = auth()->user();

        // Explicit test-only flag (set by the package TestCase): lets component tests run without an
        // actor, independently of the authorization layer. Never true in an application.
        // The authorization behaviour itself is verified in T26CapabilityGuardingTest.
        if (self::$bypassGuestChecksForTests && $user === null) {
            return true;
        }

        if ($user === null || ! method_exists($user, 'hasPermissionTo')) {
            return false;
        }

        $guard = Identity::guard();
        $fineGrained = 'clearance-'.$action.'-write';

        if ($this->permissionExistsOn($fineGrained, $guard)) {
            return $user->hasPermissionTo($fineGrained, $guard);
        }

        $access = (string) config('clearance.access_permission', 'clearance-access');

        // The access permission must exist on the guard: hasPermissionTo() throws when it does not,
        // and a guard without seeded rows has to fail closed.
        if (! $this->permissionExistsOn($access, $guard)) {
            return false;
        }

        return $user->hasPermissionTo($access, $guard);
    }

    /**
     * Whether a permission row exists on the guard (memoized per instance).
     */
    private function permissionExistsOn(string $name, string $guard): bool
    {
        return $this->writePermissionExists[$guard.'|'.$name] ??= Permission::where('name', $name)
            ->where('guard_name', $guard)
            ->exists();
    }

    /**
     * Checks if the authenticated user manages the given context in the users panel.
     *
     * True when the test-only bypass of canPerform() applies, when the user is super_admin, when
     * the user holds the users write permission globally (canPerform('users'), roles or direct,
     * with the clearance-access fallback), or when the user holds 'clearance-users-write' in the
     * context itself (canIn(), parent contexts included). False for a guest, and false (reported) when the
     * context has a cyclic or too deep parent chain.
     */
    public function managesContext(Model $context): bool
    {
        $user = auth()->user();

        // Same test-only bypass as canPerform(): component tests without an actor keep working.
        if (self::$bypassGuestChecksForTests && $user === null) {
            return true;
        }

        if ($user === null) {
            return false;
        }

        if ($this->isSuperAdmin($user) || $this->canPerform('users')) {
            return true;
        }

        try {
            return $this->context->canIn(
                $user,
                'clearance-users-write',
                $context,
                Identity::guard(),
            );
        } catch (ClearanceContextChainException $e) {
            // A context whose parent chain is cyclic or too deep cannot be resolved: it is not managed, and the
            // rest of the panel keeps working.
            report($e);

            return false;
        }
    }

    /**
     * Checks if the authenticated user manages at least one context.
     *
     * True for a super_admin or a global holder of the users write permission. Otherwise true
     * when one of the user's contextual role bindings points to a role of the identity guard (Identity::guard()) that
     * holds 'clearance-users-write' (parent contexts do not matter, a binding anywhere is
     * enough), unless the user carries a forced_off override on the permission in that binding's
     * context. The override is matched on user, context and permission only, not on the role of
     * the binding: ContextService::resolveContextualOverrides() is context-wide, so a forced_off
     * keyed to any of the user's bindings in a context denies the permission there.
     * False for a user that is not the contextual subject (ContextService::isContextualSubject()).
     */
    public function managesAnyContext(): bool
    {
        $user = auth()->user();

        if ($user === null) {
            return $this->canPerform('users');
        }

        if ($this->isSuperAdmin($user) || $this->canPerform('users')) {
            return true;
        }

        if (! $this->context->isContextualSubject($user)) {
            return false;
        }

        $guard = Identity::guard();

        $permissionId = Permission::query()
            ->where('name', 'clearance-users-write')
            ->where('guard_name', $guard)
            ->value('id');

        if ($permissionId === null) {
            return false;
        }

        $bindings = (new UserRoleContext)->getTable();
        $overrides = (new UserContextPermissionOverride)->getTable();

        return UserRoleContext::query()
            ->where($bindings.'.user_id', $user->getAuthIdentifier())
            ->whereHas('role', static function ($query) use ($guard, $permissionId): void {
                $query->where('guard_name', $guard)
                    ->whereHas('permissions', static function ($permissions) use ($permissionId): void {
                        $permissions->whereKey($permissionId);
                    });
            })
            ->whereNotExists(static function ($query) use ($bindings, $overrides, $permissionId): void {
                $query->selectRaw('1')
                    ->from($overrides)
                    ->whereColumn($overrides.'.user_id', $bindings.'.user_id')
                    ->whereColumn($overrides.'.context_type', $bindings.'.context_type')
                    ->whereColumn($overrides.'.context_id', $bindings.'.context_id')
                    ->where($overrides.'.permission_id', $permissionId)
                    ->where($overrides.'.type', UserContextPermissionOverride::TYPE_FORCED_OFF);
            })
            ->exists();
    }

    /**
     * Checks if the authenticated user may open and edit the given user in the users panel.
     *
     * With no authenticated actor the answer is true only under the test-only bypass
     * ($bypassGuestChecksForTests), otherwise false. A super_admin and any actor when
     * 'clearance.user_scope' is null are allowed; otherwise the configured ClearanceUserScope,
     * resolved from the container, decides.
     *
     * @throws ClearanceConfigException When 'clearance.user_scope' is not a ClearanceUserScope class.
     */
    public function canManageUser(Authenticatable $user): bool
    {
        $actor = auth()->user();

        if ($actor === null) {
            return self::$bypassGuestChecksForTests;
        }

        return $this->canActorManageUser($actor, $user);
    }

    /**
     * Checks if an explicit actor may open and edit the given user in the users panel.
     *
     * Same rule as canManageUser() but for an actor passed by the caller: it never reads the authenticated user
     * and never consults the test-only guest bypass. A super_admin and any actor when 'clearance.user_scope' is
     * null are allowed; otherwise the configured ClearanceUserScope, resolved from the container, decides.
     *
     * @throws ClearanceConfigException When 'clearance.user_scope' is not a ClearanceUserScope class.
     */
    public function canActorManageUser(Authenticatable $actor, Authenticatable $user): bool
    {
        if ($this->isSuperAdmin($actor)) {
            return true;
        }

        $scopeClass = config('clearance.user_scope');

        if ($scopeClass === null) {
            return true;
        }

        $scope = is_string($scopeClass) ? app($scopeClass) : $scopeClass;

        if (! $scope instanceof ClearanceUserScope) {
            throw new ClearanceConfigException('clearance.user_scope must be a class implementing '.ClearanceUserScope::class.'.');
        }

        return $scope->canManageUser($actor, $user);
    }

    /**
     * Returns the instances of a context class that the authenticated user manages.
     *
     * A super_admin or a global holder of the users write permission gets every instance
     * ($contextClass::all()). Any other actor gets the same collection filtered with
     * managesContext(), which costs one canIn() resolution per instance; enabling
     * clearance.memoize_resolution reduces that cost. A query-level filter needs the host to
     * enumerate child contexts and is a separate decision.
     *
     * @param  class-string<Model>  $contextClass
     * @return Collection<int, Model>
     */
    public function managedInstances(string $contextClass): Collection
    {
        $instances = $contextClass::all();
        $user = auth()->user();

        if (($user !== null && $this->isSuperAdmin($user)) || $this->canPerform('users')) {
            return $instances;
        }

        return $instances->filter(fn (Model $instance): bool => $this->managesContext($instance))->values();
    }

    /**
     * Checks if a user holds the super_admin role.
     */
    private function isSuperAdmin(Authenticatable $user): bool
    {
        return SuperAdmin::is($user);
    }
}
