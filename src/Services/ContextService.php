<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Rivalex\Clearance\Models\RoleMeta;
use Rivalex\Clearance\Models\UserContextPermissionOverride;
use Rivalex\Clearance\Models\UserRoleContext;

class ContextService
{
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
     * @return Collection<int, string>
     */
    public function resolveFor(Authenticatable $user, Model $context, ?string $guard = null): Collection
    {
        $granted = $this->resolveContextualRoleGrants($user, $context, $guard)
            ->merge($this->resolveGlobal($user, $guard))
            ->unique()
            ->values();

        [$forcedOn, $forcedOff] = $this->resolveContextualOverrides($user, $context);

        return $granted
            ->merge($forcedOn)
            ->unique()
            ->reject(static fn (string $name): bool => $forcedOff->contains($name))
            ->values();
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
     * @return array{0: Collection<int, string>, 1: Collection<int, string>} [forcedOn, forcedOff]
     */
    private function resolveContextualOverrides(Authenticatable $user, Model $context): array
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

        foreach ($roleIds as $roleId) {
            $overrides = UserContextPermissionOverride::forSubject(
                $userId,
                (int) $roleId,
                $contextType,
                $contextId,
            )->with('permission')->get();

            foreach ($overrides as $override) {
                if ($override->isForcedOn()) {
                    $forcedOn->push($override->permission->name);
                } elseif ($override->isForcedOff()) {
                    $forcedOff->push($override->permission->name);
                }
            }
        }

        return [$forcedOn->unique()->values(), $forcedOff->unique()->values()];
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
     * Optional $guard filters by guard_name, consistent with Spatie's guard-scoped role checks.
     * Returns false for a guest (null $user) instead of throwing, so @hasrolein() is safe on
     * views reachable by unauthenticated visitors (F7 - security audit).
     */
    public function hasRoleIn(?Authenticatable $user, string $role, Model $context, ?string $guard = null): bool
    {
        if ($user === null) {
            return false;
        }

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
}
