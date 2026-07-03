<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Livewire\Roles;

use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Rivalex\Clearance\Models\RoleMeta;
use Rivalex\Clearance\Models\UserRoleContext;
use Rivalex\Clearance\Services\RoleService;
use Rivalex\Clearance\Services\UserClearanceService;
use Spatie\Permission\Models\Role;

/**
 * Modal typed-confirmation delete for a role.
 * When users are assigned (global or contextual), offers reassign or detach before deletion.
 */
class DeleteRole extends Component
{
    #[Locked]
    public int $roleId;

    public string $modalName = '';

    public string $confirmText = '';

    /** @var 'detach'|'reassign' */
    public string $action = 'detach';

    public ?int $targetRoleId = null;

    public ?string $errorMessage = null;

    public function mount(): void
    {
        $this->modalName = 'delete-role-' . $this->roleId;
    }

    public function confirmDelete(RoleService $roleService, UserClearanceService $userService): void
    {
        abort_unless(app(\Rivalex\Clearance\Clearance::class)->canPerform('roles'), 403);

        $role = Role::find($this->roleId);

        if ($role === null) {
            return;
        }

        // Locked roles cannot be deleted
        if (RoleMeta::where('role_id', $role->id)->value('is_locked')) {
            $this->errorMessage = __('clearance::ui.roles.delete.locked_cannot_delete');
            return;
        }

        // Typed confirmation must pass before any destructive DB mutation
        if ($this->confirmText !== 'DELETE ' . $role->name) {
            return;
        }

        $modelHasRolesTable = config('permission.table_names.model_has_roles', 'model_has_roles');

        $globalRows     = DB::table($modelHasRolesTable)->where('role_id', $this->roleId)->get(['model_id', 'model_type']);
        $contextualRows = UserRoleContext::where('role_id', $this->roleId)->get();
        $hasAffected    = $globalRows->isNotEmpty() || $contextualRows->isNotEmpty();

        if ($hasAffected) {
            if ($this->action === 'reassign') {
                if ($this->targetRoleId === null || $this->targetRoleId === $this->roleId) {
                    $this->errorMessage = __('clearance::ui.roles.delete.no_other_roles');
                    return;
                }

                $targetRole = Role::find($this->targetRoleId);

                if ($targetRole === null) {
                    return;
                }

                $userModelClass = config('clearance.user_model')
                    ?? config('auth.providers.users.model', 'App\\Models\\User');

                // Global: raw Spatie primitives to preserve direct guard permissions
                foreach ($globalRows as $row) {
                    try {
                        $user = $userModelClass::find($row->model_id);
                    } catch (\Throwable) {
                        continue;
                    }
                    if ($user !== null) {
                        $user->removeRole($role);
                        $user->assignRole($targetRole);
                    }
                }

                // Contextual: reassign role_id in place
                UserRoleContext::where('role_id', $this->roleId)
                    ->update(['role_id' => $this->targetRoleId]);

            } else {
                $userModelClass = config('clearance.user_model')
                    ?? config('auth.providers.users.model', 'App\\Models\\User');

                // Detach global: also revokes direct guard permissions
                foreach ($globalRows as $row) {
                    try {
                        $user = $userModelClass::find($row->model_id);
                    } catch (\Throwable) {
                        continue;
                    }
                    if ($user !== null) {
                        $userService->removeGlobalRole($user, $role);
                    }
                }

                // Ensure all global assignments are removed even if user model was unavailable
                DB::table($modelHasRolesTable)->where('role_id', $this->roleId)->delete();

                // Detach contextual: delete all bindings for this role
                UserRoleContext::where('role_id', $this->roleId)->delete();
            }
        }

        $roleService->delete($role);
        $this->confirmText  = '';
        $this->errorMessage = null;
        $this->dispatch('role-deleted');
    }

    public function render(): View
    {
        $role = Role::find($this->roleId);

        $isLocked = $role !== null
            ? (bool) RoleMeta::where('role_id', $this->roleId)->value('is_locked')
            : false;

        $modelHasRolesTable = config('permission.table_names.model_has_roles', 'model_has_roles');

        $affectedGlobalCount = $role !== null
            ? DB::table($modelHasRolesTable)->where('role_id', $this->roleId)->count()
            : 0;

        $affectedContextualCount = $role !== null
            ? UserRoleContext::where('role_id', $this->roleId)->count()
            : 0;

        $otherRoles = $role !== null
            ? Role::where('guard_name', $role->guard_name)
                ->where('id', '!=', $this->roleId)
                ->orderBy('name')
                ->get()
            : collect();

        return view('clearance::livewire.roles.delete-role', compact(
            'role',
            'isLocked',
            'affectedGlobalCount',
            'affectedContextualCount',
            'otherRoles',
        ));
    }
}
