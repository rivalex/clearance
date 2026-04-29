<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Livewire\Roles;

use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;
use Rivalex\Clearance\Models\RoleMeta;
use Rivalex\Clearance\Services\RoleService;
use Spatie\Permission\Models\Role;

/**
 * Full CRUD list screen for roles with search, counts, pagination, and safe delete (V8).
 */
class RoleManager extends Component
{
    use WithPagination;

    public bool $showForm = false;

    public ?int $editingId = null;

    public string $search = '';

    /** Role ID staged for typed-confirmation deletion (T25). */
    public ?int $deletingRoleId = null;

    /** Users currently assigned to the role being deleted. */
    public int $deletingRoleUsersCount = 0;

    /** Typed confirmation text (T25). */
    public string $deleteConfirmText = '';

    /**
     * No-op mount kept for test/Livewire lifecycle compatibility.
     */
    public function mount(): void {}

    /**
     * Reset pagination when search changes.
     */
    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    /**
     * Open form in create mode.
     */
    public function create(): void
    {
        $this->editingId = null;
        $this->showForm = true;
        $this->deletingRoleId = null;
        $this->dispatch('open-role-form');
    }

    /**
     * Open form in edit mode.
     */
    public function edit(int $id): void
    {
        $this->editingId = $id;
        $this->showForm = true;
        $this->deletingRoleId = null;
        $this->dispatch('open-role-form');
    }

    /**
     * Stage a role for typed-confirmation deletion (T25, V8).
     * Roles with assigned users show a block warning instead of the confirm input.
     */
    public function delete(int $id): void
    {
        $usersCount = DB::table(
            config('permission.table_names.model_has_roles', 'model_has_roles')
        )->where('role_id', $id)->count();

        $this->deletingRoleId = $id;
        $this->deletingRoleUsersCount = $usersCount;
        $this->deleteConfirmText = '';
        $this->showForm = false;
        $this->dispatch('open-delete-role');
    }

    /**
     * Cancel the pending delete.
     */
    public function cancelDelete(): void
    {
        $this->deletingRoleId = null;
        $this->deletingRoleUsersCount = 0;
        $this->deleteConfirmText = '';
        $this->dispatch('close-delete-role');
    }

    /**
     * Execute delete after typed confirmation — blocked when users are assigned (T25, V8).
     */
    public function confirmDelete(RoleService $roleService): void
    {
        if ($this->deletingRoleId === null || $this->deletingRoleUsersCount > 0) {
            return;
        }

        /** @var Role|null $role */
        $role = Role::find($this->deletingRoleId);

        if ($role === null) {
            $this->cancelDelete();

            return;
        }

        if ($this->deleteConfirmText !== 'DELETE '.$role->name) {
            return;
        }

        $roleService->delete($role);
        $this->cancelDelete();
    }

    /**
     * Close form without saving (called when modal dismissed by user via X button).
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
        $this->dispatch('close-role-form');
    }

    public function render(): View
    {
        $query = Role::orderBy('name');

        if ($this->search !== '') {
            $query->where('name', 'like', '%'.$this->search.'%');
        }

        $roles = $query->paginate(15);

        $metas = RoleMeta::whereIn('role_id', $roles->pluck('id')->all())
            ->get()
            ->keyBy('role_id');

        $modelHasRolesTable = config('permission.table_names.model_has_roles', 'model_has_roles');

        $roleData = $roles->through(fn (Role $role) => [
            'role'              => $role,
            'meta'              => $metas->get($role->id),
            'permissions_count' => $role->permissions()->count(),
            'users_count'       => DB::table($modelHasRolesTable)->where('role_id', $role->id)->count(),
        ]);

        $deletingRole = $this->deletingRoleId !== null ? Role::find($this->deletingRoleId) : null;

        return view('clearance::livewire.roles.role-manager', compact('roleData', 'deletingRole'));
    }
}
