<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Livewire\Permissions;

use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Rivalex\Clearance\Clearance;
use Rivalex\Clearance\Models\Permission;
use Rivalex\Clearance\Services\PermissionService;

/**
 * Modal typed-confirmation delete for a permission group (T25, V8).
 */
class DeletePermission extends Component
{
    #[Locked]
    public string $prefix;

    #[Locked]
    public string $guard;

    public string $modalName = '';

    #[Locked]
    public string $groupKey;

    public string $confirmText = '';

    public function mount(): void
    {
        $this->modalName = 'delete-permission-'.$this->groupKey;
    }

    /**
     * Execute delete after typed confirmation (T25, V8 - writes via PermissionService).
     */
    public function confirmDelete(PermissionService $permissionService): void
    {
        abort_unless(app(Clearance::class)->canPerform('permissions'), 403);

        if (str_starts_with($this->prefix, 'clearance')) {
            return;
        }

        if ($this->confirmText !== 'DELETE '.$this->prefix) {
            return;
        }

        $sep = config('clearance.naming_separator', '-');

        Permission::where('guard_name', $this->guard)
            ->where('name', 'like', $this->prefix.$sep.'%')
            ->get()
            ->each(fn (Permission $p) => $permissionService->delete($p));

        $this->confirmText = '';
        $this->dispatch('permission-deleted');
    }

    public function render(): View
    {
        return view('clearance::livewire.permissions.delete-permission');
    }
}
