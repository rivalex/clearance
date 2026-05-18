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
use Rivalex\Clearance\Models\UserContextPermissionOverride;
use Rivalex\Clearance\Models\UserRoleContext;
use Rivalex\Clearance\Services\UserClearanceService;
use Spatie\Permission\Models\Role;

/**
 * Panel showing contextual role assignments for a user + a specific context model class.
 */
#[Lazy]
class ContextualRolesPanel extends Component
{
    #[Locked]
    public int|string $userId;

    #[Locked]
    public string $contextClass;

    /**
     * Per-context permission override state: [contextId => [roleId => [permId => bool]]].
     *
     * @var array<int|string, array<int, array<int, bool>>>
     */
    public array $contextualPermissions = [];

    public function mount(): void
    {
        $this->initContextualPermissions();
    }

    public function placeholder(): View
    {
        return view('clearance::livewire.users.placeholder');
    }

    #[On('clearance:assignment-saved')]
    #[On('clearance:assignment-removed')]
    public function refresh(): void
    {
        $this->initContextualPermissions();
    }

    /**
     * Sync per-context permission overrides for a given role + context instance.
     */
    public function saveContextualExtraPerms(int $roleId, int|string $contextId, UserClearanceService $service): void
    {
        abort_unless(app(Clearance::class)->canPerform('users'), 403);

        $role = Role::find($roleId);

        if ($role === null) {
            return;
        }

        /** @var class-string<Model> $contextClass */
        $contextClass = $this->contextClass;
        $context      = $contextClass::findOrFail($contextId);

        $desiredIds = collect($this->contextualPermissions[$contextId][$roleId] ?? [])
            ->filter(fn ($checked) => (bool) $checked)
            ->keys()
            ->map(fn ($id) => (int) $id)
            ->toArray();

        $service->syncContextualExtraPermissions($this->resolveUser(), $role, $context, $desiredIds);
        $this->initContextualPermissions();
    }

    public function render(): View
    {
        $childIds = RoleHierarchy::pluck('child_role_id')->map(fn ($id) => (int) $id)->toArray();

        $contextAssignments = UserRoleContext::where('user_id', $this->userId)
            ->where('context_type', $this->contextClass)
            ->with('role.permissions')
            ->get();

        /** @var class-string<Model> $contextClass */
        $contextClass     = $this->contextClass;
        $contextInstances = $contextClass::all();

        $availableMasterRoles = Role::whereNotIn('id', $childIds)
            ->orderBy('name')
            ->get()
            ->all();

        $contextModelConfig = config("clearance.contextual_models.{$this->contextClass}", []);

        return view('clearance::livewire.users.contextual-roles-panel', [
            'user'                 => $this->resolveUser(),
            'contextAssignments'   => $contextAssignments,
            'availableMasterRoles' => $availableMasterRoles,
            'contextModelConfig'   => $contextModelConfig,
            'contextInstances'     => $contextInstances,
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
     * Rebuild contextualPermissions from DB overrides.
     */
    private function initContextualPermissions(): void
    {
        $this->contextualPermissions = [];

        $assignments = UserRoleContext::where('user_id', $this->userId)
            ->where('context_type', $this->contextClass)
            ->with('role.permissions')
            ->get();

        foreach ($assignments as $assignment) {
            $roleId    = (int) $assignment->role_id;
            $contextId = $assignment->context_id;
            $role      = $assignment->role;

            if ($role === null) {
                continue;
            }

            $overrides = UserContextPermissionOverride::forSubject(
                $this->userId,
                $roleId,
                $this->contextClass,
                $contextId,
            )->pluck('permission_id')->map(fn ($id) => (int) $id)->toArray();

            if (! isset($this->contextualPermissions[$contextId])) {
                $this->contextualPermissions[$contextId] = [];
            }

            $this->contextualPermissions[$contextId][$roleId] = [];

            foreach ($role->permissions as $perm) {
                $permId = (int) $perm->id;
                $this->contextualPermissions[$contextId][$roleId][$permId] = in_array($permId, $overrides, true);
            }
        }
    }
}
