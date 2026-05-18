<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Livewire\Users;

use Illuminate\Database\Eloquent\Model;
use Illuminate\View\View;
use Livewire\Component;
use Rivalex\Clearance\Models\RoleHierarchy;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Admin panel to assign master roles and per-role direct permissions to a specific user.
 * Route: /clearance/user/{userId}
 *
 * Role permissions shown checked+disabled. Non-role permissions for the same guard
 * are editable checkboxes saved per card. Role removal also revokes all direct
 * permissions for the same guard (option A).
 */
class UserPermissionManager extends Component
{
    /** User primary key, resolved via clearance.user_model config. */
    public int|string $userId;

    /**
     * Editable non-role permission state.
     *
     * @var array<int, array<int, bool>>  [roleId => [permId => isChecked]]
     */
    public array $manualPermissions = [];

    public ?int $selectedRoleId = null;

    public bool $showRemoveModal = false;

    public ?int $removingRoleId = null;

    public function mount(int|string $userId): void
    {
        $this->userId = $userId;
        $this->initManualPermissions();
    }

    /**
     * Assign the selected master role to the user.
     */
    public function assignRole(): void
    {
        if (! $this->selectedRoleId) {
            return;
        }

        $role = Role::find($this->selectedRoleId);

        if ($role === null) {
            return;
        }

        // Refuse slave roles (child in any hierarchy)
        $childIds = RoleHierarchy::pluck('child_role_id')->map(fn ($id) => (int) $id)->toArray();

        if (in_array((int) $role->id, $childIds, true)) {
            return;
        }

        $this->resolveUser()->assignRole($role);
        $this->selectedRoleId = null;
        $this->initManualPermissions();
    }

    public function openRemoveModal(int $roleId): void
    {
        $this->removingRoleId = $roleId;
        $this->showRemoveModal = true;
    }

    public function closeRemoveModal(): void
    {
        $this->showRemoveModal = false;
        $this->removingRoleId = null;
    }

    /**
     * Remove role from user and revoke all direct permissions for its guard (option A).
     */
    public function confirmRemoveRole(): void
    {
        $role = $this->removingRoleId ? Role::find($this->removingRoleId) : null;

        if ($role === null) {
            $this->closeRemoveModal();

            return;
        }

        $user = $this->resolveUser();

        if (! $user->hasRole($role)) {
            $this->closeRemoveModal();

            return;
        }

        $directPermNames = $user->permissions
            ->where('guard_name', $role->guard_name)
            ->pluck('name')
            ->toArray();

        $user->removeRole($role);

        if (! empty($directPermNames)) {
            $user->revokePermissionTo($directPermNames);
        }

        $this->closeRemoveModal();
        $this->initManualPermissions();
    }

    /**
     * Sync the user's direct (non-role) permissions for a given role's guard.
     */
    public function savePermissions(int $roleId): void
    {
        $role = Role::find($roleId);

        if ($role === null) {
            return;
        }

        $user = $this->resolveUser();

        if (! $user->hasRole($role)) {
            return;
        }

        $rolePermIds = $role->permissions->pluck('id')->map(fn ($id) => (int) $id)->toArray();
        $manual      = $this->manualPermissions[$roleId] ?? [];

        // Current direct non-role perms for this guard
        $currentDirectIds = $user->permissions
            ->where('guard_name', $role->guard_name)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->diff($rolePermIds)
            ->values()
            ->toArray();

        // Desired direct perms from checkboxes
        $desiredIds = collect($manual)
            ->filter(fn ($checked) => (bool) $checked)
            ->keys()
            ->map(fn ($id) => (int) $id)
            ->toArray();

        foreach (array_diff($desiredIds, $currentDirectIds) as $permId) {
            if ($perm = Permission::find($permId)) {
                $user->givePermissionTo($perm);
            }
        }

        foreach (array_diff($currentDirectIds, $desiredIds) as $permId) {
            if ($perm = Permission::find($permId)) {
                $user->revokePermissionTo($perm);
            }
        }

        $this->initManualPermissions();
    }

    public function render(): View
    {
        $user     = $this->resolveUser();
        $childIds = RoleHierarchy::pluck('child_role_id')->unique()->map(fn ($id) => (int) $id)->toArray();

        $assignedRoles   = $user->roles()->with('permissions')->orderBy('name')->get();
        $assignedRoleIds = $assignedRoles->pluck('id')->toArray();

        $roleCards = $assignedRoles->map(fn (Role $role): array => [
            'role'                => $role,
            'guard_permissions'   => Permission::where('guard_name', $role->guard_name)->orderBy('name')->get(),
            'role_permission_ids' => $role->permissions->pluck('id')->map(fn ($id) => (int) $id)->toArray(),
            'is_master'           => ! in_array((int) $role->id, $childIds, true),
        ])->all();

        $availableMasterRoles = Role::whereNotIn('id', $childIds)
            ->whereNotIn('id', $assignedRoleIds)
            ->orderBy('name')
            ->get()
            ->all();

        return view('clearance::livewire.users.user-permission-manager', [
            'user'                 => $user,
            'roleCards'            => $roleCards,
            'availableMasterRoles' => $availableMasterRoles,
        ]);
    }

    /**
     * Fetch a fresh user instance with roles and direct permissions loaded.
     *
     * @return Model&\Illuminate\Contracts\Auth\Authenticatable
     */
    private function resolveUser(): Model
    {
        /** @var class-string<Model> $userModel */
        $userModel = config('clearance.user_model')
            ?? config('auth.providers.users.model', 'App\\Models\\User');

        return $userModel::with(['roles.permissions', 'permissions'])->findOrFail($this->userId);
    }

    /**
     * Rebuild $manualPermissions from DB. Call on mount and after every mutation.
     */
    private function initManualPermissions(): void
    {
        $user = $this->resolveUser();

        $userDirectPermIds = $user->permissions
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->toArray();

        $this->manualPermissions = [];

        foreach ($user->roles()->with('permissions')->get() as $role) {
            $roleId      = (int) $role->id;
            $rolePermIds = $role->permissions->pluck('id')->map(fn ($id) => (int) $id)->toArray();

            $this->manualPermissions[$roleId] = [];

            Permission::where('guard_name', $role->guard_name)
                ->orderBy('name')
                ->pluck('id')
                ->each(function ($permId) use ($roleId, $rolePermIds, $userDirectPermIds): void {
                    $permId = (int) $permId;

                    if (! in_array($permId, $rolePermIds, true)) {
                        $this->manualPermissions[$roleId][$permId] = in_array($permId, $userDirectPermIds, true);
                    }
                });
        }
    }
}
