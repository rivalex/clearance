<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Livewire\Permissions;

use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;
use Rivalex\Clearance\Services\PermissionService;
use Spatie\Permission\Models\Permission;

/**
 * Full CRUD list screen for permissions (V6, V8).
 */
#[Layout('clearance::layouts.app')]
class PermissionManager extends Component
{
    public bool $showForm = false;

    public ?int $editingId = null;

    /** @var array<int, Permission> */
    public array $permissions = [];

    /**
     * Load all permissions on mount.
     */
    public function mount(): void
    {
        $this->loadPermissions();
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
     * Open form in edit mode for the given permission.
     */
    public function edit(int $id): void
    {
        $this->editingId = $id;
        $this->showForm = true;
    }

    /**
     * Delete a permission via PermissionService (V8 — no direct Spatie call).
     */
    public function delete(int $id, PermissionService $permissionService): void
    {
        /** @var Permission|null $permission */
        $permission = Permission::find($id);

        if ($permission !== null) {
            $permissionService->delete($permission);
        }

        $this->loadPermissions();
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
    #[On('permission-saved')]
    public function onPermissionSaved(): void
    {
        $this->showForm = false;
        $this->editingId = null;
        $this->loadPermissions();
    }

    /**
     * Deterministic Tailwind color class for a permission group prefix.
     */
    public function colorForGroup(string $group): string
    {
        $palette = ['red', 'amber', 'emerald', 'sky', 'violet', 'rose', 'orange', 'teal', 'cyan', 'indigo'];

        return $palette[abs(crc32($group)) % count($palette)];
    }

    public function render(): View
    {
        return view('clearance::livewire.permissions.permission-manager');
    }

    private function loadPermissions(): void
    {
        $this->permissions = Permission::orderBy('name')->get()->all();
    }
}
