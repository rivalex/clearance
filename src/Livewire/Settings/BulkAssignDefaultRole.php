<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Livewire\Settings;

use Illuminate\View\View;
use Livewire\Component;
use Rivalex\Clearance\Clearance;
use Rivalex\Clearance\Exceptions\ClearanceProtectedResourceException;
use Rivalex\Clearance\Exceptions\ClearanceScopeViolationException;
use Rivalex\Clearance\Models\ClearanceSettings;
use Rivalex\Clearance\Services\PermissionService;
use Rivalex\Clearance\Services\UserClearanceService;
use Rivalex\Clearance\Support\ErrorMessage;
use Rivalex\Clearance\Support\Identity;
use Rivalex\Clearance\Support\SpatieSchema;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Bulk-assign the saved default role (stored by id) to users who don't already have it.
 *
 * Users are processed in chunks of 500 with one transaction per chunk, and users the actor may not manage
 * (outside the configured user scope) are skipped.
 */
class BulkAssignDefaultRole extends Component
{
    public ?string $bulkMessage = null;

    public bool $bulkSuccess = false;

    public function bulkAssignDefaultRole(): void
    {
        abort_unless(app(Clearance::class)->canPerform('settings') && app(Clearance::class)->canPerform('users'), 403);

        $this->bulkMessage = null;
        $defaultRoleId = ClearanceSettings::get('default_role_id');

        if (! is_string($defaultRoleId) || ! ctype_digit($defaultRoleId)) {
            $this->bulkMessage = __('clearance::ui.settings.bulk_no_role');
            $this->bulkSuccess = false;

            return;
        }

        /** @var Role|null $role */
        $role = app(PermissionRegistrar::class)->getRoleClass()::query()->whereKey((int) $defaultRoleId)->first();

        if ($role === null || $role->guard_name !== Identity::guard()) {
            $this->bulkMessage = __('clearance::ui.settings.bulk_role_not_found');
            $this->bulkSuccess = false;

            return;
        }

        if (app(PermissionService::class)->isSystemAssignedOnly($role)) {
            $this->bulkMessage = __('clearance::ui.settings.bulk_role_system_only');
            $this->bulkSuccess = false;

            return;
        }

        $userModelClass = Identity::userModel();

        if (! $userModelClass || ! class_exists($userModelClass)) {
            $this->bulkMessage = __('clearance::ui.settings.bulk_no_user_model');
            $this->bulkSuccess = false;

            return;
        }

        $actor = auth()->user();
        abort_if($actor === null, 403);

        $model = new $userModelClass;
        $table = SpatieSchema::table('model_has_roles');
        $query = $userModelClass::query()->whereNotExists(function ($sub) use ($table, $model, $role): void {
            $sub->selectRaw('1')->from($table)
                ->whereColumn($table.'.'.SpatieSchema::modelMorphKey(), $model->getTable().'.'.$model->getKeyName())
                ->where($table.'.'.SpatieSchema::rolePivotKey(), $role->getKey())
                ->where($table.'.model_type', $model->getMorphClass());
        });

        try {
            $assigned = 0;
            $clearance = app(Clearance::class);

            // One transaction per chunk (inside bulkAssignGlobalRoleAs); the first refusal stops the run.
            $query->chunkById(500, function ($users) use (&$assigned, $actor, $role, $clearance): void {
                $assigned += app(UserClearanceService::class)->bulkAssignGlobalRoleAs(
                    $actor,
                    $role,
                    $users->filter(fn ($u) => $clearance->canManageUser($u))->values()->all(),
                );
            }, $model->getQualifiedKeyName(), $model->getKeyName());
        } catch (ClearanceScopeViolationException|ClearanceProtectedResourceException $e) {
            $this->bulkMessage = ErrorMessage::for($e);
            $this->bulkSuccess = false;

            return;
        }

        $this->bulkMessage = __('clearance::ui.settings.bulk_done', ['count' => $assigned]);
        $this->bulkSuccess = true;
    }

    public function render(): View
    {
        $clearance = app(Clearance::class);

        abort_unless($clearance->canAccess(), 403);

        return view('clearance::livewire.settings.bulk-assign-default-role', [
            'canTrigger' => $clearance->canPerform('settings') && $clearance->canPerform('users'),
        ]);
    }
}
