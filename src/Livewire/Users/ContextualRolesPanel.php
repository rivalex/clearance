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
use Rivalex\Clearance\Exceptions\ClearanceProtectedResourceException;
use Rivalex\Clearance\Exceptions\ClearanceScopeViolationException;
use Rivalex\Clearance\Models\RoleMeta;
use Rivalex\Clearance\Models\UserContextPermissionOverride;
use Rivalex\Clearance\Models\UserRoleContext;
use Rivalex\Clearance\Services\UserClearanceService;
use Rivalex\Clearance\Support\ErrorMessage;
use Rivalex\Clearance\Support\Identity;
use Spatie\Permission\Models\Permission;
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

    /**
     * Request-scoped memo of managedInstances(); never persisted by Livewire.
     *
     * @var Collection<int, Model>|null
     */
    private ?Collection $managedInstancesMemo = null;

    public function mount(): void
    {
        $clearance = app(Clearance::class);
        abort_unless((bool) config('clearance.modules.users', false), 404);
        abort_unless(ctype_digit((string) $this->userId), 403);
        abort_unless($clearance->canPerform('users') || $clearance->managesAnyContext(), 403);
        abort_unless($clearance->canManageUser($this->resolveUser()), 403);

        $allowed = array_keys(config('clearance.contextual_models', []));
        abort_unless(in_array($this->contextClass, $allowed, true), 422);

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
        $clearance = app(Clearance::class);
        abort_unless($clearance->canPerform('users') || $clearance->managesAnyContext(), 403);

        $user = $this->resolveUser();
        abort_unless($clearance->canManageUser($user), 403);

        $role = Role::find($roleId);

        if ($role === null) {
            return;
        }

        /** @var class-string<Model> $contextClass */
        $contextClass = $this->contextClass;
        $context = $contextClass::find($contextId);
        abort_unless($context !== null && $clearance->managesContext($context), 403);

        // Only permissions the bound role holds can be pinned from this panel: other ids are dropped silently.
        $rolePermissionIds = $role->permissions->pluck('id')->map(fn ($id) => (int) $id)->all();

        $desiredIds = array_values(array_intersect(
            collect($this->contextualPermissions[$contextId][$roleId] ?? [])
                ->filter(fn ($checked) => (bool) $checked)
                ->keys()
                ->map(fn ($id) => (int) $id)
                ->toArray(),
            $rolePermissionIds,
        ));

        // Pins outside the bound role are not shown here: keep them untouched.
        $outsideRole = UserContextPermissionOverride::forSubject($user->getAuthIdentifier(), (int) $role->id, $contextClass, $context->getKey())
            ->where('type', UserContextPermissionOverride::TYPE_FORCED_ON)
            ->pluck('permission_id')
            ->map(fn ($id) => (int) $id)
            ->diff($rolePermissionIds)
            ->all();
        $desiredIds = array_values(array_unique(array_merge($desiredIds, $outsideRole)));

        try {
            $service->syncContextualExtraPermissions(auth()->user(), $user, $role, $context, $desiredIds);
        } catch (ClearanceScopeViolationException|ClearanceProtectedResourceException $e) {
            abort(403, ErrorMessage::for($e));
        }

        $this->initContextualPermissions();
    }

    public function render(): View
    {
        $clearance = app(Clearance::class);
        abort_unless($clearance->canAccess(), 403);
        abort_unless($clearance->canPerform('users') || $clearance->managesAnyContext(), 403);

        $user = $this->resolveUser();
        abort_unless($clearance->canManageUser($user), 403);

        // contextClass is #[Locked] and checked against the allowlist in mount().
        /** @var class-string<Model> $contextClass */
        $contextClass = $this->contextClass;
        $contextInstances = $this->managedInstances();
        $managedIds = $this->managedKeys($contextInstances);

        $contextAssignments = UserRoleContext::where('user_id', $this->userId)
            ->where('context_type', $this->contextClass)
            ->with('role.permissions')
            ->get()
            ->filter(fn (UserRoleContext $assignment): bool => in_array((string) $assignment->context_id, $managedIds, true))
            ->values();

        $allRoles = Role::orderBy('name')->get();
        $metas = RoleMeta::whereIn('role_id', $allRoles->pluck('id')->all())->get()->keyBy('role_id');

        // Only show roles that are contextual-scoped and accept this panel's context type (V13).
        $availableMasterRoles = $allRoles
            ->filter(fn ($r) => isset($metas[$r->id]) && $metas[$r->id]->acceptsContext($this->contextClass))
            ->values()
            ->all();

        $contextModelConfig = config("clearance.contextual_models.{$this->contextClass}", []);

        return view('clearance::livewire.users.contextual-roles-panel', [
            'user' => $user,
            'contextAssignments' => $contextAssignments,
            'availableMasterRoles' => $availableMasterRoles,
            'contextModelConfig' => $contextModelConfig,
            'contextInstances' => $contextInstances,
        ]);
    }

    /**
     * Fetch a fresh user instance with roles and direct permissions loaded. A missing user aborts with 403.
     *
     * @return Model&Authenticatable
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

        if (! $user instanceof Authenticatable) {
            throw new \LogicException('Configured user model ['.$userModel.'] must implement Authenticatable.');
        }

        return $user;
    }

    /**
     * The context instances of this panel's class that the authenticated user manages.
     *
     * Memoized for the request (a private property is not persisted by Livewire), so a render and the
     * permission rebuild do not load and check every instance again.
     *
     * @return Collection<int, Model>
     */
    private function managedInstances(): Collection
    {
        /** @var class-string<Model> $contextClass */
        $contextClass = $this->contextClass;

        return $this->managedInstancesMemo ??= app(Clearance::class)->managedInstances($contextClass);
    }

    /**
     * Returns the keys of the given context instances as strings.
     *
     * @param  Collection<int, Model>  $instances
     * @return array<int, string>
     */
    private function managedKeys(Collection $instances): array
    {
        return $instances->map(fn (Model $instance): string => (string) $instance->getKey())->all();
    }

    /**
     * Rebuild contextualPermissions from DB overrides.
     */
    private function initContextualPermissions(): void
    {
        $this->contextualPermissions = [];

        $managedIds = $this->managedKeys($this->managedInstances());

        $assignments = UserRoleContext::where('user_id', $this->userId)
            ->where('context_type', $this->contextClass)
            ->with('role.permissions')
            ->get()
            ->filter(fn (UserRoleContext $assignment): bool => in_array((string) $assignment->context_id, $managedIds, true));

        foreach ($assignments as $assignment) {
            $roleId = (int) $assignment->role_id;
            $contextId = $assignment->context_id;
            $role = $assignment->role;

            if ($role === null) {
                continue;
            }

            $overrides = UserContextPermissionOverride::forSubject(
                $this->userId,
                $roleId,
                $this->contextClass,
                $contextId,
            )->where('type', UserContextPermissionOverride::TYPE_FORCED_ON)->pluck('permission_id')->map(fn ($id) => (int) $id)->toArray();

            if (! isset($this->contextualPermissions[$contextId])) {
                $this->contextualPermissions[$contextId] = [];
            }

            $this->contextualPermissions[$contextId][$roleId] = [];

            foreach ($role->permissions->whereInstanceOf(Permission::class) as $perm) {
                $permId = (int) $perm->id;
                $this->contextualPermissions[$contextId][$roleId][$permId] = in_array($permId, $overrides, true);
            }
        }
    }
}
