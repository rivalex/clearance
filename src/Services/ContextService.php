<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Rivalex\Clearance\Models\RolePermissionOverride;
use Rivalex\Clearance\Models\UserRoleContext;

class ContextService
{
    /**
     * Returns effective permission names for a user in a specific context model.
     * Strictly scoped to (user_id, context_type, context_id) — server-side enforcement (V4).
     * When $guard is provided, only roles with that guard_name are considered.
     *
     * @return Collection<int, string>
     */
    public function resolveFor(Authenticatable $user, Model $context, ?string $guard = null): Collection
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

        $userContexts = $query->get();

        $effective = collect();

        foreach ($userContexts as $userContext) {
            $role = $userContext->role;
            $perms = $role->permissions->pluck('name');

            $overrides = RolePermissionOverride::where('child_role_id', $role->id)
                ->with('permission')
                ->get();

            foreach ($overrides as $override) {
                if ($override->isForcedOn()) {
                    $perms = $perms->push($override->permission->name)->unique()->values();
                } elseif ($override->isForcedOff()) {
                    $perms = $perms->reject(
                        static fn (string $name): bool => $name === $override->permission->name,
                    )->values();
                }
            }

            $effective = $effective->merge($perms)->unique()->values();
        }

        return $effective;
    }

    /**
     * Checks if a user has a specific permission within a context model (V4).
     * Optional $guard filters roles by guard_name, matching Spatie's guard-specific check pattern.
     */
    public function canIn(Authenticatable $user, string $permission, Model $context, ?string $guard = null): bool
    {
        return $this->resolveFor($user, $context, $guard)->contains($permission);
    }

    /**
     * Alias of canIn() for backward compatibility.
     */
    public function hasPermissionIn(Authenticatable $user, string $permission, Model $context, ?string $guard = null): bool
    {
        return $this->canIn($user, $permission, $context, $guard);
    }
}
