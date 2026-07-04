<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Livewire\Settings;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Livewire\Component;
use Rivalex\Clearance\Clearance;
use Rivalex\Clearance\Models\ClearanceSettings;
use Spatie\Permission\Exceptions\RoleDoesNotExist;
use Spatie\Permission\Models\Role;

/**
 * Bulk-assign the saved default role to users who don't already have it.
 */
class BulkAssignDefaultRole extends Component
{
    public ?string $bulkMessage = null;

    public bool $bulkSuccess = false;

    public function bulkAssignDefaultRole(): void
    {
        abort_unless(app(Clearance::class)->canPerform('settings'), 403);

        $this->bulkMessage = null;
        $defaultRole = ClearanceSettings::get('default_role', '');

        if (! $defaultRole) {
            $this->bulkMessage = __('clearance::ui.settings.bulk_no_role');
            $this->bulkSuccess = false;

            return;
        }

        try {
            $role = Role::findByName($defaultRole);
        } catch (RoleDoesNotExist) {
            $this->bulkMessage = __('clearance::ui.settings.bulk_role_not_found');
            $this->bulkSuccess = false;

            return;
        }

        $userModelClass = config('clearance.user_model')
            ?? config('auth.providers.'.config('auth.defaults.guard').'.model')
            ?? User::class;

        if (! $userModelClass || ! class_exists($userModelClass)) {
            $this->bulkMessage = __('clearance::ui.settings.bulk_no_user_model');
            $this->bulkSuccess = false;

            return;
        }

        $modelHasRolesTable = config('permission.table_names.model_has_roles', 'model_has_roles');
        $usersWithRole = DB::table($modelHasRolesTable)
            ->where('role_id', $role->id)
            ->pluck('model_id')
            ->all();

        $assigned = 0;
        $userModelClass::query()
            ->whereNotIn('id', $usersWithRole)
            ->each(function ($user) use ($role, &$assigned): void {
                if (method_exists($user, 'assignRole')) {
                    $user->assignRole($role);
                    $assigned++;
                }
            });

        $this->bulkMessage = __('clearance::ui.settings.bulk_done', ['count' => $assigned]);
        $this->bulkSuccess = true;
    }

    public function render(): View
    {
        return view('clearance::livewire.settings.bulk-assign-default-role');
    }
}
