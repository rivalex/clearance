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
use Rivalex\Clearance\Support\ErrorMessage;
use Rivalex\Clearance\Support\Identity;
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
        $clearance = app(Clearance::class);
        abort_unless((bool) config('clearance.modules.users', false), 404);
        abort_unless(ctype_digit((string) $this->userId), 403);
        abort_unless($clearance->canPerform('users'), 403);
        abort_unless($clearance->canManageUser($this->resolveUser()), 403);

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
        $clearance = app(Clearance::class);
        abort_unless($clearance->canPerform('users'), 403);

        $user = $this->resolveUser();
        abort_unless($clearance->canManageUser($user), 403);

        $role = Role::find($roleId);

        if ($role === null) {
            return;
        }

        $desiredIds = collect($this->manualPermissions[$roleId] ?? [])
            ->filter(fn ($checked) => (bool) $checked)
            ->keys()
            ->map(fn ($id) => (int) $id)
            ->toArray();

        // The target must hold the role: direct permissions are shown and edited only under a held role.
        abort_unless($user->getRelation('roles')->contains('id', $roleId), 403);

        try {
            $service->syncGlobalExtraPermissions(auth()->user(), $user, $role, $desiredIds);
        } catch (ClearanceScopeViolationException|ClearanceProtectedResourceException $e) {
            abort(403, ErrorMessage::for($e));
        }

        $this->initManualPermissions();
    }

    public function render(): View
    {
        $clearance = app(Clearance::class);
        abort_unless($clearance->canAccess(), 403);
        abort_unless($clearance->canPerform('users'), 403);

        $user = $this->resolveUser();
        abort_unless($clearance->canManageUser($user), 403);

        $assignedRoles = $user->roles()->with('permissions')->orderBy('name')->get()->whereInstanceOf(Role::class);
        $assignedRoleIds = $assignedRoles->pluck('id')->toArray();

        // One query for every guard of the held roles, not one per role.
        $permissionsByGuard = Permission::whereIn('guard_name', $assignedRoles->pluck('guard_name')->unique()->values()->all())
            ->orderBy('name')
            ->get()
            ->groupBy('guard_name');

        $roleCards = $assignedRoles->map(fn (Role $role): array => [
            'role' => $role,
            'guard_permissions' => $permissionsByGuard->get($role->guard_name, new Collection),
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
     * Fetch a fresh user instance with roles and direct permissions loaded. A missing user aborts with 403.
     *
     * @return Model&ClearanceAuthenticatable
     */
    private function resolveUser(): Model
    {
        /** @var class-string<Model>|null $userModel */
        $userModel = Identity::userModel();

        if ($userModel === null || ! class_exists($userModel)) {
            throw new \LogicException('No user model is configured: set clearance.user_model.');
        }

        // A missing user and an unmanaged one both answer 403, so the panel does not reveal which ids exist.
        $user = $userModel::with(['roles.permissions', 'permissions'])->find($this->userId);

        if ($user === null) {
            abort(403);
        }

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

        $this->manualPermissions = [];

        // The state holds only the checked ids: direct permissions of the user on the guard of the role
        // that the role itself does not grant. Unchecked permissions are absent, so the snapshot stays small.
        foreach ($user->roles()->with('permissions')->get()->whereInstanceOf(Role::class) as $role) {
            $roleId = (int) $role->id;
            $rolePermIds = $role->permissions->pluck('id')->map(fn ($id) => (int) $id)->toArray();

            $this->manualPermissions[$roleId] = [];

            foreach ($userPermissions as $permission) {
                $permId = (int) $permission->getKey();

                if ($permission->guard_name === $role->guard_name && ! in_array($permId, $rolePermIds, true)) {
                    $this->manualPermissions[$roleId][$permId] = true;
                }
            }
        }
    }
}
