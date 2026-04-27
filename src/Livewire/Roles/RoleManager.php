<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Livewire\Roles;

use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;
use Rivalex\Clearance\Models\RoleMeta;
use Rivalex\Clearance\Services\RoleService;
use Spatie\Permission\Models\Role;

/**
 * Full CRUD list screen for roles with is_system/is_protected badges (V8).
 */
#[Layout('clearance::layouts.app')]
class RoleManager extends Component
{
    public bool $showForm = false;

    public ?int $editingId = null;

    /** @var array<int, array{role: Role, meta: RoleMeta|null}> */
    public array $roleData = [];

    /**
     * Load roles and their metadata on mount.
     */
    public function mount(): void
    {
        $this->loadRoles();
    }

    /**
     * Open form in create mode.
     */
    public function create(): void
    {
        $this->editingId = null;
        $this->showForm = true;
    }

    /**
     * Open form in edit mode.
     */
    public function edit(int $id): void
    {
        $this->editingId = $id;
        $this->showForm = true;
    }

    /**
     * Delete a role via RoleService (V8).
     */
    public function delete(int $id, RoleService $roleService): void
    {
        /** @var Role|null $role */
        $role = Role::find($id);

        if ($role !== null) {
            $roleService->delete($role);
        }

        $this->loadRoles();
    }

    /**
     * Close form without saving.
     */
    public function closeForm(): void
    {
        $this->showForm = false;
        $this->editingId = null;
    }

    /**
     * Refresh list when form reports a save.
     */
    #[On('role-saved')]
    public function onRoleSaved(): void
    {
        $this->showForm = false;
        $this->editingId = null;
        $this->loadRoles();
    }

    public function render(): View
    {
        return view('clearance::livewire.roles.role-manager');
    }

    private function loadRoles(): void
    {
        $roles = Role::orderBy('name')->get();
        $metas = RoleMeta::whereIn('role_id', $roles->pluck('id')->all())
            ->get()
            ->keyBy('role_id');

        $this->roleData = $roles->map(fn (Role $role) => [
            'role' => $role,
            'meta' => $metas->get($role->id),
        ])->all();
    }
}
