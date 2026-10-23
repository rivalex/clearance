<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Rivalex\Clearance\Contracts\ClearanceHierarchicalContext;
use Rivalex\Clearance\Exceptions\ClearanceConfigException;
use Rivalex\Clearance\Exceptions\ClearanceContextChainException;
use Rivalex\Clearance\Models\RoleMeta;
use Rivalex\Clearance\Models\UserContextPermissionOverride;
use Rivalex\Clearance\Models\UserRoleContext;
use Rivalex\Clearance\Support\Identity;

class ContextService
{
    /**
     * Maximum number of levels in a context chain, the leaf context included.
     */
    public const MAX_CONTEXT_DEPTH = 5;

    /**
     * Request-scoped memo of resolveFor() results, keyed by
     * `user class | user id | context class | context key | guard ?? '*'`.
     * The user class is part of the key because global roles are keyed by model_type.
     *
     * @var array<string, array<int, string>>
     */
    private array $memo = [];

    /**
     * @param  bool  $memoize  When true, resolveFor() results are kept until flush() is called.
     */
    public function __construct(private readonly bool $memoize = false) {}

    /**
     * Clears the memoized resolveFor() results. Called by every Clearance write path
     * (services and model events) so a write is visible to the next check.
     */
    public function flush(): void
    {
        $this->memo = [];
    }

    /**
     * Returns effective permission names for a user in a specific context model.
     * Merges pass 1 (contextual role grants) with pass 3 (user's global roles), then
     * applies pass 2 (per-context forced_on / forced_off overrides) LAST: forced_on is
     * merged in first, then forced_off is rejected - so forced_off is fully
     * deny-authoritative, winning even over a permission held via a global role or
     * granted by a forced_on override for the same permission from another contextual
     * role in the same context. V16 (fixes V14, where forced_off could be silently
     * defeated by a global-role grant of the same permission).
     *
     * When the context implements ClearanceHierarchicalContext, the contextual bindings and
     * overrides of every ancestor are merged in too (context inheritance, not role
     * inheritance); forced_off from any level of the chain is still rejected last.
     *
     * The global pass also holds the user's Spatie direct permissions of the requested guard, so a
     * contextual forced_off reduces them too. A user that is not an instance of the
     * configured user model (isContextualSubject()) gets only the global pass.
     *
     * @return Collection<int, string>
     *
     * @throws ClearanceContextChainException When the parent chain has a cycle or is too deep.
     */
    public function resolveFor(Authenticatable $user, Model $context, ?string $guard = null): Collection
    {
        $key = null;

        if (! $this->isContextualSubject($user)) {
            return $this->resolveGlobal($user, $guard)->merge($this->resolveDirect($user, $guard))->unique()->values();
        }

        $this->assertIntegerKey($user, $user->getAuthIdentifier());

        if ($this->memoize) {
            $key = implode('|', [
                $user instanceof Model ? $user->getMorphClass() : get_class($user),
                (string) $user->getAuthIdentifier(),
                get_class($context),
                (string) $context->getKey(),
                $guard ?? '*',
            ]);

            if (isset($this->memo[$key])) {
                return collect($this->memo[$key]);
            }
        }

        $chain = $this->contextChain($context);

        foreach ($chain as $level) {
            $this->assertIntegerKey($level, $level->getKey());
        }

        $granted = collect($chain)
            ->flatMap(fn (Model $level): Collection => $this->resolveContextualRoleGrants($user, $level, $guard))
            ->merge($this->resolveGlobal($user, $guard))
            ->merge($this->resolveDirect($user, $guard))
            ->unique()
            ->values();

        $forcedOn = collect();
        $forcedOff = collect();

        foreach ($chain as $level) {
            [$levelOn, $levelOff] = $this->resolveContextualOverrides($user, $level, $guard);
            $forcedOn = $forcedOn->merge($levelOn);
            $forcedOff = $forcedOff->merge($levelOff);
        }

        $forcedOn = $forcedOn->unique()->values();
        $forcedOff = $forcedOff->unique()->values();

        $effective = $granted
            ->merge($forcedOn)
            ->unique()
            ->reject(static fn (string $name): bool => $forcedOff->contains($name))
            ->values();

        if ($key !== null) {
            $this->memo[$key] = $effective->all();
        }

        return $effective;
    }

    /**
     * Returns [$context, parent, grandparent, ...] by following clearanceParentContext()
     * while the current model implements ClearanceHierarchicalContext and has a parent.
     *
     * @return array<int, Model>
     *
     * @throws ClearanceContextChainException When a (class, key) pair repeats or the chain
     *                                        would exceed MAX_CONTEXT_DEPTH levels.
     */
    private function contextChain(Model $context): array
    {
        $chain = [];
        $seen = [];
        $current = $context;

        while ($current !== null) {
            $id = get_class($current).'|'.$current->getKey();

            if (isset($seen[$id])) {
                throw new ClearanceContextChainException("Context chain contains a cycle at {$id}.");
            }

            if (count($chain) >= self::MAX_CONTEXT_DEPTH) {
                throw new ClearanceContextChainException(
                    'Context chain is deeper than '.self::MAX_CONTEXT_DEPTH.' levels.'
                );
            }

            $seen[$id] = true;
            $chain[] = $current;

            $current = $current instanceof ClearanceHierarchicalContext
                ? $current->clearanceParentContext()
                : null;
        }

        return $chain;
    }

    /**
     * Pass 1: permissions granted by the user's contextual role bindings for the given
     * context (role-declared permissions only - per-context overrides are NOT applied
     * here; see resolveContextualOverrides()). V16.
     *
     * @return Collection<int, string>
     */
    private function resolveContextualRoleGrants(Authenticatable $user, Model $context, ?string $guard): Collection
    {
        $userId = $user->getAuthIdentifier();
        $contextType = get_class($context);
        $contextId = $context->getKey();

        $query = UserRoleContext::where('user_id', $userId)
            ->where('context_type', $contextType)
            ->where('context_id', $contextId)
            ->with('role.permissions');

        if ($guard !== null) {
            $query->whereHas('role', static fn ($q) => $q->where('guard_name', $guard));
        }

        return $query->get()
            ->flatMap(fn ($userContext) => $userContext->role->permissions->pluck('name'))
            ->unique()
            ->values();
    }

    /**
     * Pass 2: per-context permission overrides (forced_on / forced_off) for every
     * contextual role binding the user holds in this context. These are applied by
     * resolveFor() AFTER merging in global-role grants, so a forced_off is
     * deny-authoritative regardless of where the grant came from. V16.
     *
     * When $guard is not null only overrides whose permission has that guard_name are kept
     * (filtered on the eager-loaded permission relation, no extra query), so a permission of
     * another guard never leaks into a guard-scoped resolution. In a guard-scoped resolution a
     * forced_on row is also skipped when its binding role belongs to another guard; a forced_off row is
     * never skipped for that reason. With a null guard no role-guard filter applies.
     *
     * @return array{0: Collection<int, string>, 1: Collection<int, string>} [forcedOn, forcedOff]
     */
    private function resolveContextualOverrides(Authenticatable $user, Model $context, ?string $guard): array
    {
        $userId = $user->getAuthIdentifier();
        $contextType = get_class($context);
        $contextId = $context->getKey();

        $roleIds = UserRoleContext::where('user_id', $userId)
            ->where('context_type', $contextType)
            ->where('context_id', $contextId)
            ->pluck('role_id');

        $forcedOn = collect();
        $forcedOff = collect();

        $overrides = $roleIds->isEmpty()
            ? collect()
            : UserContextPermissionOverride::query()
                ->where('user_id', $userId)
                ->whereIn('role_id', $roleIds)
                ->where('context_type', $contextType)
                ->where('context_id', $contextId)
                ->with(['permission', 'role'])
                ->get();

        foreach ($overrides as $override) {
            if ($guard !== null && $override->permission->guard_name !== $guard) {
                continue;
            }

            // A forced_on pin whose binding role lives on another guard grants nothing in this guard. A forced_off
            // is never skipped: it stays deny-authoritative.
            if ($guard !== null && $override->isForcedOn() && $override->role?->guard_name !== $guard) {
                continue;
            }

            if ($override->isForcedOn()) {
                $forcedOn->push($override->permission->name);
            } elseif ($override->isForcedOff()) {
                $forcedOff->push($override->permission->name);
            }
        }

        return [$forcedOn->unique()->values(), $forcedOff->unique()->values()];
    }

    /**
     * Whether the user is an instance of the configured user model.
     *
     * Contextual bindings and overrides are keyed by user id only, so they are read and written only for
     * the model resolved by Identity::userModel(). The comparison is by morph class and strict: a subclass
     * is not a subject.
     */
    public function isContextualSubject(Authenticatable $user): bool
    {
        $configured = Identity::userModel();

        if ($configured === null || ! class_exists($configured)) {
            return false;
        }

        $configuredMorph = is_subclass_of($configured, Model::class)
            ? (new $configured)->getMorphClass()
            : $configured;

        $userMorph = $user instanceof Model ? $user->getMorphClass() : get_class($user);

        return $userMorph === $configuredMorph;
    }

    /**
     * Pass 3b: the user's Spatie direct permissions, filtered by guard when one is given.
     * Joined to the global pass so a contextual forced_off reduces them like any other grant.
     *
     * @return Collection<int, string>
     */
    private function resolveDirect(Authenticatable $user, ?string $guard): Collection
    {
        if (! ($user instanceof Model) || ! method_exists($user, 'getDirectPermissions')) {
            return collect();
        }

        return $user->getDirectPermissions()
            ->when($guard !== null, fn (Collection $permissions): Collection => $permissions->where('guard_name', $guard))
            ->pluck('name')
            ->unique()
            ->values();
    }

    /**
     * Pass 3: permissions from the user's globally-assigned Spatie roles (scope=global or no RoleMeta).
     * Allows super-admin and other global roles to satisfy canIn() for any context. V14, V15.
     *
     * @return Collection<int, string>
     */
    private function resolveGlobal(Authenticatable $user, ?string $guard): Collection
    {
        if (! ($user instanceof Model) || ! method_exists($user, 'roles')) {
            return collect();
        }

        $roles = $user->roles()
            ->when($guard !== null, fn ($q) => $q->where('guard_name', $guard))
            ->with('permissions')
            ->get();

        if ($roles->isEmpty()) {
            return collect();
        }

        $metas = RoleMeta::whereIn('role_id', $roles->pluck('id')->all())
            ->get()
            ->keyBy('role_id');

        return $roles
            ->filter(fn ($role) => ! isset($metas[$role->id]) || $metas[$role->id]->isGlobal())
            ->flatMap(fn ($role) => $role->permissions->pluck('name'))
            ->unique()
            ->values();
    }

    /**
     * Checks if a user has a specific permission within a context model (V4).
     * Optional $guard filters roles by guard_name, matching Spatie's guard-specific check pattern.
     * Returns false for a guest (null $user) instead of throwing, so @canin() is safe on
     * views reachable by unauthenticated visitors (F7 - security audit).
     */
    public function canIn(?Authenticatable $user, string $permission, Model $context, ?string $guard = null): bool
    {
        if ($user === null) {
            return false;
        }

        return $this->resolveFor($user, $context, $guard)->contains($permission);
    }

    /**
     * Alias of canIn() for backward compatibility.
     */
    public function hasPermissionIn(Authenticatable $user, string $permission, Model $context, ?string $guard = null): bool
    {
        return $this->canIn($user, $permission, $context, $guard);
    }

    /**
     * Checks if a user holds a specific role within a context model.
     * Checks the exact context only: roles bound on a parent context are NOT considered.
     * Optional $guard filters by guard_name, consistent with Spatie's guard-scoped role checks.
     * Returns false for a guest (null $user) instead of throwing, so @hasrolein() is safe on
     * views reachable by unauthenticated visitors (F7 - security audit).
     */
    public function hasRoleIn(?Authenticatable $user, string $role, Model $context, ?string $guard = null): bool
    {
        if ($user === null || ! $this->isContextualSubject($user)) {
            return false;
        }

        $this->assertIntegerKey($user, $user->getAuthIdentifier());
        $this->assertIntegerKey($context, $context->getKey());

        return UserRoleContext::where('user_id', $user->getAuthIdentifier())
            ->where('context_type', get_class($context))
            ->where('context_id', $context->getKey())
            ->whereHas('role', static function ($q) use ($role, $guard): void {
                $q->where('name', $role);
                if ($guard !== null) {
                    $q->where('guard_name', $guard);
                }
            })
            ->exists();
    }

    /**
     * Refuses a user or context whose key is not an integer (an int, or a string of digits).
     * The contextual tables store `user_id` and `context_id` as integers, so any other key
     * could be coerced and match another row.
     *
     * @throws ClearanceConfigException
     */
    private function assertIntegerKey(object $subject, mixed $key): void
    {
        if (is_int($key) || (is_string($key) && preg_match('/^[0-9]+$/', $key) === 1)) {
            return;
        }

        throw new ClearanceConfigException(
            'Contextual roles and permissions need integer keys: ['.get_class($subject).'] key ['.(string) $key.'] is not an integer.'
        );
    }
}
