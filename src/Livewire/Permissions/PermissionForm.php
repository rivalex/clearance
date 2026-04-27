<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Livewire\Permissions;

use Illuminate\View\View;
use Livewire\Component;
use Rivalex\Clearance\Exceptions\ClearanceNamingException;
use Rivalex\Clearance\Services\GuardService;
use Rivalex\Clearance\Services\PermissionService;
use Spatie\Permission\Models\Permission;

/**
 * Create / edit form for a single permission (V6, V8).
 * All writes go through PermissionService.
 */
class PermissionForm extends Component
{
    public string $name      = '';
    public string $guardName = '';

    /** @var array<int, string> */
    public array $availableGuards = [];

    public ?int $permissionId = null;

    public ?string $errorMessage = null;

    /**
     * Load existing permission data or set defaults.
     */
    public function mount(GuardService $guardService, ?int $permissionId = null): void
    {
        $this->availableGuards = array_keys($guardService->all());
        $this->guardName       = config('auth.defaults.guard', 'web');
        $this->permissionId    = $permissionId;

        if ($permissionId !== null) {
            /** @var Permission|null $permission */
            $permission = Permission::find($permissionId);

            if ($permission !== null) {
                $this->name      = $permission->name;
                $this->guardName = $permission->guard_name;
            }
        }
    }

    /**
     * Save (create or rename) permission via PermissionService (V6, V8).
     */
    public function save(PermissionService $permissionService): void
    {
        $this->errorMessage = null;

        try {
            if ($this->permissionId === null) {
                $permissionService->create($this->name, $this->guardName);
            } else {
                /** @var Permission|null $permission */
                $permission = Permission::find($this->permissionId);

                if ($permission !== null) {
                    $permissionService->rename($permission, $this->name);
                }
            }
        } catch (ClearanceNamingException $e) {
            $this->errorMessage = $e->getMessage();

            return;
        }

        $this->dispatch('permission-saved');
    }

    /**
     * Cancel without saving.
     */
    public function cancel(): void
    {
        $this->dispatch('permission-saved');
    }

    public function render(): View
    {
        return view('clearance::livewire.permissions.permission-form');
    }
}
