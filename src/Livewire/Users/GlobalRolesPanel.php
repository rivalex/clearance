<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Livewire\Users;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Rivalex\Clearance\Clearance;
use Rivalex\Clearance\Contracts\ClearanceAuthenticatable;
use Rivalex\Clearance\Exceptions\ClearanceProtectedResourceException;
use Rivalex\Clearance\Exceptions\ClearanceScopeViolationException;
use Rivalex\Clearance\Models\RoleMeta;
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

        try {
            $service->syncGlobalExtraPermissions(auth()->user(), $this->resolveUser(), $role, $desiredIds);
        } catch (ClearanceScopeViolationException|ClearanceProtectedResourceException $e) {
            abort(403, $e->getMessage());
        }

        $this->initManualPermissions();
    }

    public function render(): View
    {
        $user = $this->resolveUser();

        $assignedRoles = $user->roles()->with('permissions')->orderBy('name')->get()->whereInstanceOf(Role::class);
        $assignedRoleIds = $assignedRoles->pluck('id')->toArray();

        $roleCards = $assignedRoles->map(fn (Role $role): array => [
            'role' => $role,
            'guard_permissions' => Permission::where('guard_name', $role->guard_name)->orderBy('name')->get(),
            'role_permission_ids' => $role->permissions->pluck('id')->map(fn ($id) => (int) $id)->toArray(),
        ])->all();

        $candidateRoles = Role::whereNotIn('id', $assignedRoleIds)->orderBy('name')->get();
        $metas = RoleMeta::whereIn('role_id', $candidateRoles->pluck('id')->all())->get()->keyBy('role_id');

        // Only show roles that are global-scoped (or have no RoleMeta → default global, V15).
        $availableMasterRoles = $candidateRoles
            ->filter(fn ($r) => ! isset($metas[$r->id]) || $metas[$r->id]->isGlobal())
            ->values()
            ->all();

        return view('clearance::livewire.users.global-roles-panel', [
            'user' => $user,
            'roleCards' => $roleCards,
            'availableMasterRoles' => $availableMasterRoles,
        ]);
    }

    /**
     * Fetch a fresh user instance with roles and direct permissions loaded.
     *
     * @return Model&ClearanceAuthenticatable
     */
    private function resolveUser(): Model
    {
        /** @var class-string<Model> $userModel */
        $userModel = config('clearance.user_model')
            ?? config('auth.providers.users.model', 'App\\Models\\User');

        $user = $userModel::with(['roles.permissions', 'permissions'])->findOrFail($this->userId);

        if (! $user instanceof Authenticatable || ! method_exists($user, 'roles')) {
            throw new \LogicException(
                'Configured user model ['.$userModel.'] must implement Authenticatable and use '
                .'the Spatie HasRoles trait (via HasClearance).'
            );
        }

        /** @var Model&ClearanceAuthenticatable $user */
        return $user;
    }

    /**
     * Rebuild $manualPermissions from DB. Called on mount and after every mutation.
     */
    private function initManualPermissions(): void
    {
        $user = $this->resolveUser();

        /** @var Collection<int, Permission> $userPermissions */
        $userPermissions = $user->getRelation('permissions');
        $userDirectPermIds = $userPermissions
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->toArray();

        $this->manualPermissions = [];

        foreach ($user->roles()->with('permissions')->get()->whereInstanceOf(Role::class) as $role) {
            $roleId = (int) $role->id;
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
