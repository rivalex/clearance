<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Livewire\Users;

use Illuminate\Database\Eloquent\Model;
use Illuminate\View\View;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Rivalex\Clearance\Clearance;
use Rivalex\Clearance\Models\RoleHierarchy;
use Rivalex\Clearance\Services\UserClearanceService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Panel showing all globally-assigned roles for a user, with per-role extra-permission editing.
 */
#[Lazy]
class GlobalRolesPanel extends Component
{
    #[Locked]
    public int|string $userId;

    /**
     * Editable non-role permission state: [roleId => [permId => bool]].
     *
     * @var array<int|string, array<int|string, bool>>
     */
    public array $manualPermissions = [];

    public function mount(): void
    {
        $this->initManualPermissions();
    }

    public function placeholder(): View
    {
        return view('clearance::livewire.users.placeholder');
    }

    #[On('clearance:assignment-saved')]
    #[On('clearance:assignment-removed')]
    public function refresh(): void
    {
        $this->initManualPermissions();
    }

    /**
     * Sync direct (non-role) permissions for a given role's guard via UserClearanceService.
     */
    public function saveExtraPerms(int $roleId, UserClearanceService $service): void
    {
        abort_unless(app(Clearance::class)->canPerform('users'), 403);

        $role = Role::find($roleId);

        if ($role === null) {
            return;
        }

        $desiredIds = collect($this->manualPermissions[$roleId] ?? [])
            ->filter(fn ($checked) => (bool) $checked)
            ->keys()
            ->map(fn ($id) => (int) $id)
            ->toArray();

        $service->syncGlobalExtraPermissions($this->resolveUser(), $role, $desiredIds);
        $this->initManualPermissions();
    }

    public function render(): View
    {
        $user     = $this->resolveUser();
        $childIds = RoleHierarchy::pluck('child_role_id')->map(fn ($id) => (int) $id)->toArray();

        $assignedRoles   = $user->roles()->with('permissions')->orderBy('name')->get();
        $assignedRoleIds = $assignedRoles->pluck('id')->toArray();

        $roleCards = $assignedRoles->map(fn (Role $role): array => [
            'role'                => $role,
            'guard_permissions'   => Permission::where('guard_name', $role->guard_name)->orderBy('name')->get(),
            'role_permission_ids' => $role->permissions->pluck('id')->map(fn ($id) => (int) $id)->toArray(),
        ])->all();

        $availableMasterRoles = Role::whereNotIn('id', $childIds)
            ->whereNotIn('id', $assignedRoleIds)
            ->orderBy('name')
            ->get()
            ->all();

        return view('clearance::livewire.users.global-roles-panel', [
            'user'                 => $user,
            'roleCards'            => $roleCards,
            'availableMasterRoles' => $availableMasterRoles,
            'childIds'             => $childIds,
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
     * Rebuild $manualPermissions from DB. Called on mount and after every mutation.
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
