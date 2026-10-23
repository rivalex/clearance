<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Traits;

use Illuminate\Database\Eloquent\Model;
use Rivalex\Clearance\Models\UserContextPermissionOverride;
use Rivalex\Clearance\Models\UserRoleContext;
use Rivalex\Clearance\Services\ContextService;

/**
 * Add this trait to a model used as a contextual scope (a team, a project, a store).
 *
 * After a successful delete for good (a force delete, or a delete on a model without soft deletes) the
 * contextual role bindings (UserRoleContext) and the contextual permission overrides
 * (UserContextPermissionOverride) that point at the model are removed too, because those rows are keyed by
 * context type and id only and have no foreign key. A soft delete keeps them, so a restored context keeps
 * its assignments, and a delete vetoed by an observer or failed keeps them as well.
 * Rows that already became orphans are removed by `php artisan clearance:prune`.
 */
trait CleansClearanceContext
{
    /**
     * Registers the `deleted` listener that removes the contextual rows of a context deleted for good.
     */
    public static function bootCleansClearanceContext(): void
    {
        static::deleted(static function (Model $model): void {
            if (method_exists($model, 'isForceDeleting') && ! $model->isForceDeleting()) {
                return;
            }

            $connection = (new UserRoleContext)->getConnection();

            $connection->transaction(static function () use ($model): void {
                UserContextPermissionOverride::query()
                    ->where('context_type', $model::class)
                    ->where('context_id', $model->getKey())
                    ->delete();

                UserRoleContext::query()
                    ->where('context_type', $model::class)
                    ->where('context_id', $model->getKey())
                    ->delete();
            });

            // Query deletes fire no model events: drop the resolution memo by hand.
            app(ContextService::class)->flush();
        });
    }
}
