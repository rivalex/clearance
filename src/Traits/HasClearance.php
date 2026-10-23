<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Traits;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Rivalex\Clearance\Models\UserContextPermissionOverride;
use Rivalex\Clearance\Models\UserRoleContext;
use Rivalex\Clearance\Services\ContextService;
use Spatie\Permission\Traits\HasRoles;

/**
 * Exposes contextual permission and role checks directly on the User model.
 * Includes Spatie's HasRoles - `use HasClearance` is the only line needed in your User model.
 * Safe to combine with an explicit `use HasRoles` - PHP deduplicates the trait automatically.
 *
 * After a successful delete for good of the user model (a force delete, or a delete on a model without soft
 * deletes) its contextual role bindings (UserRoleContext) and its contextual permission overrides
 * (UserContextPermissionOverride) are removed too, because those rows are keyed by user id only and have no
 * foreign key. A soft delete keeps them, so a restored user keeps its access, and a delete vetoed by an
 * observer or failed keeps them as well. The cleanup runs only for the
 * configured user model (ContextService::isContextualSubject()).
 *
 * @mixin Authenticatable
 */
trait HasClearance
{
    use HasRoles;

    /**
     * Registers the `deleted` listener that removes the contextual rows of a user that is deleted for good.
     */
    public static function bootHasClearance(): void
    {
        static::deleted(static function (Model $model): void {
            if (method_exists($model, 'isForceDeleting') && ! $model->isForceDeleting()) {
                return;
            }

            if (! $model instanceof Authenticatable || ! app(ContextService::class)->isContextualSubject($model)) {
                return;
            }

            $userId = $model->getAuthIdentifier();

            UserContextPermissionOverride::where('user_id', $userId)->delete();
            UserRoleContext::where('user_id', $userId)->delete();

            // Query deletes fire no model events: drop the resolution memo by hand.
            app(ContextService::class)->flush();
        });
    }

    /**
     * Check if this user has a permission within a context model.
     */
    public function canIn(string $permission, Model $context, ?string $guard = null): bool
    {
        return app(ContextService::class)->canIn($this, $permission, $context, $guard);
    }

    /**
     * Alias of canIn() - mirrors Spatie's hasPermissionTo() naming convention.
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
