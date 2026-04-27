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
     *
     * @return Collection<int, string>
     */
    public function resolveFor(Authenticatable $user, Model $context): Collection
    {
        $userId = $user->getAuthIdentifier();
        $contextType = get_class($context);
        $contextId = $context->getKey();

        $userContexts = UserRoleContext::where('user_id', $userId)
            ->where('context_type', $contextType)
            ->where('context_id', $contextId)
            ->with('role.permissions')
            ->get();

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
     */
    public function hasPermissionIn(Authenticatable $user, string $permission, Model $context): bool
    {
        return $this->resolveFor($user, $context)->contains($permission);
    }
}
