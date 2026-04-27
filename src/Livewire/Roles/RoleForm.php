<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Livewire\Roles;

use Illuminate\View\View;
use Livewire\Component;
use Rivalex\Clearance\Models\RoleMeta;
use Rivalex\Clearance\Services\GuardService;
use Rivalex\Clearance\Services\RoleService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Create / edit form for a single role (V8).
 * All Spatie writes go through RoleService.
 */
class RoleForm extends Component
{
    public string $name = '';

    public string $guardName = '';

    public bool $isSystem = false;

    public bool $isProtected = false;

    /** @var array<int, string> */
    public array $availableGuards = [];

    /** @var array<int, array{id: int, name: string, selected: bool}> */
    public array $permissionOptions = [];

    public ?int $roleId = null;

    public ?string $errorMessage = null;

    /**
     * Load role data or defaults.
     */
    public function mount(GuardService $guardService, ?int $roleId = null): void
    {
        $this->availableGuards = array_keys($guardService->all());
        $this->guardName = config('auth.defaults.guard', 'web');
        $this->roleId = $roleId;

        if ($roleId !== null) {
            /** @var Role|null $role */
            $role = Role::find($roleId);

            if ($role !== null) {
                $this->name = $role->name;
                $this->guardName = $role->guard_name;

                $meta = RoleMeta::where('role_id', $role->id)->first();

                if ($meta !== null) {
                    $this->isSystem = (bool) $meta->is_system;
                    $this->isProtected = (bool) $meta->is_protected;
                }

                $rolePermissions = $role->permissions->pluck('name')->all();
                $this->loadPermissions($role->guard_name, $rolePermissions);

                return;
            }
        }

        $this->loadPermissions($this->guardName, []);
    }

    /**
     * Reload permission checkboxes when guard changes.
     */
    public function updatedGuardName(): void
    {
        $this->loadPermissions($this->guardName, []);
    }

    /**
     * Save role via RoleService; update RoleMeta for badges (V8).
     */
    public function save(RoleService $roleService): void
    {
        $this->errorMessage = null;

        $selectedNames = array_values(array_map(
            fn ($opt) => $opt['name'],
            array_filter($this->permissionOptions, fn ($opt) => $opt['selected']),
        ));

        try {
            if ($this->roleId === null) {
                $role = $roleService->create($this->name, $this->guardName);
            } else {
                /** @var Role|null $role */
                $role = Role::find($this->roleId);

                if ($role === null) {
                    return;
                }

                $roleService->rename($role, $this->name);
                $role = $role->fresh();
            }

            $roleService->syncPermissions($role, $selectedNames);

            // RoleMeta is a Clearance-owned table — not a Spatie call (V8 compliant)
            RoleMeta::updateOrCreate(
                ['role_id' => $role->id],
                ['is_system' => $this->isSystem, 'is_protected' => $this->isProtected],
            );
        } catch (\Throwable $e) {
            $this->errorMessage = $e->getMessage();

            return;
        }

        $this->dispatch('role-saved');
    }

    /**
     * Cancel without saving.
     */
    public function cancel(): void
    {
        $this->dispatch('role-saved');
    }

    public function render(): View
    {
        return view('clearance::livewire.roles.role-form');
    }

    /**
     * Load permission checkboxes scoped to the given guard.
     *
     * @param  array<int, string>  $selected
     */
    private function loadPermissions(string $guard, array $selected): void
    {
        $this->permissionOptions = Permission::where('guard_name', $guard)
            ->orderBy('name')
            ->get()
            ->map(fn (Permission $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'selected' => in_array($p->name, $selected, true),
            ])
            ->all();
    }
}
