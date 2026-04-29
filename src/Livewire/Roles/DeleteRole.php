<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Livewire\Roles;

use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Livewire\Component;
use Rivalex\Clearance\Services\RoleService;
use Spatie\Permission\Models\Role;

/**
 * Modal typed-confirmation delete for a role — blocked when users assigned (T25, V8).
 */
class DeleteRole extends Component
{
    public int $roleId;

    public bool $showModal = false;

    public string $confirmText = '';

    /**
     * Execute delete after typed confirmation — blocked when users are assigned (T25, V8).
     */
    public function confirmDelete(RoleService $roleService): void
    {
        $role = Role::find($this->roleId);

        if ($role === null) {
            $this->showModal = false;

            return;
        }

        $usersCount = DB::table(
            config('permission.table_names.model_has_roles', 'model_has_roles')
        )->where('role_id', $this->roleId)->count();

        if ($usersCount > 0 || $this->confirmText !== 'DELETE '.$role->name) {
            return;
        }

        $roleService->delete($role);
        $this->showModal = false;
        $this->confirmText = '';
        $this->dispatch('role-deleted');
    }

    public function render(): View
    {
        $role = Role::find($this->roleId);

        $usersCount = $role !== null
            ? DB::table(config('permission.table_names.model_has_roles', 'model_has_roles'))
                ->where('role_id', $this->roleId)->count()
            : 0;

        return view('clearance::livewire.roles.delete-role', compact('role', 'usersCount'));
    }
}
