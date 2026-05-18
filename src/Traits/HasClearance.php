<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Traits;

use Illuminate\Database\Eloquent\Model;
use Rivalex\Clearance\Services\ContextService;
use Spatie\Permission\Traits\HasRoles;

/**
 * Exposes contextual permission and role checks directly on the User model.
 * Includes Spatie's HasRoles — `use HasClearance` is the only line needed in your User model.
 * Safe to combine with an explicit `use HasRoles` — PHP deduplicates the trait automatically.
 *
 * @mixin \Illuminate\Contracts\Auth\Authenticatable
 */
trait HasClearance
{
    use HasRoles;

    /**
     * Check if this user has a permission within a context model.
     */
    public function canIn(string $permission, Model $context, ?string $guard = null): bool
    {
        return app(ContextService::class)->canIn($this, $permission, $context, $guard);
    }

    /**
     * Alias of canIn() — mirrors Spatie's hasPermissionTo() naming convention.
     */
    public function hasPermissionIn(string $permission, Model $context, ?string $guard = null): bool
    {
        return $this->canIn($permission, $context, $guard);
    }

    /**
     * Check if this user holds a role within a context model.
     */
    public function hasRoleIn(string $role, Model $context, ?string $guard = null): bool
    {
        return app(ContextService::class)->hasRoleIn($this, $role, $context, $guard);
    }
}
