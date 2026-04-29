<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Livewire\Permissions;

use Illuminate\View\View;
use Livewire\Component;
use Rivalex\Clearance\Services\PermissionService;
use Spatie\Permission\Models\Permission;

/**
 * Modal typed-confirmation delete for a permission group (T25, V8).
 */
class DeletePermission extends Component
{
    public string $prefix;

    public bool $showModal = false;

    public string $confirmText = '';

    /**
     * Execute delete after typed confirmation (T25, V8 — writes via PermissionService).
     */
    public function confirmDelete(PermissionService $permissionService): void
    {
        if ($this->confirmText !== 'DELETE '.$this->prefix) {
            return;
        }

        $sep = config('clearance.naming_separator', '-');

        Permission::where('name', 'like', $this->prefix.$sep.'%')->get()
            ->each(fn (Permission $p) => $permissionService->delete($p));

        $this->showModal = false;
        $this->confirmText = '';
        $this->dispatch('permission-deleted');
    }

    public function render(): View
    {
        return view('clearance::livewire.permissions.delete-permission');
    }
}
