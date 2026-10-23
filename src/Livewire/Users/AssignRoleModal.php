<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Livewire\Users;

use Flux\Flux;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Rivalex\Clearance\Clearance;
use Rivalex\Clearance\Exceptions\ClearanceProtectedResourceException;
use Rivalex\Clearance\Exceptions\ClearanceScopeViolationException;
use Rivalex\Clearance\Services\PermissionService;
use Rivalex\Clearance\Services\UserClearanceService;
use Rivalex\Clearance\Support\ErrorMessage;
use Rivalex\Clearance\Support\Identity;
use Rivalex\Clearance\Support\SuperAdmin;
use Spatie\Permission\Models\Role;

/**
 * Modal for assigning a master role to a user - global or contextual scope.
 */
class AssignRoleModal extends Component
{
    #[Locked]
    public int|string $userId;

    #[Locked]
    public string $scope; // 'global' | 'contextual'

    #[Locked]
    public string $contextClass = '';

    #[Locked]
    public string $modalName = '';

    public ?int $selectedRoleId = null;

    /** @var array<int|string> Context instance IDs selected (contextual scope only). */
    public array $selectedContextIds = [];

    public ?string $errorMessage = null;

    public function mount(int|string $userId, string $scope, string $contextClass = ''): void
    {
        $this->userId = $userId;
        $this->scope = $scope;
        $this->contextClass = $contextClass;

        abort_unless(in_array($scope, ['global', 'contextual'], true), 422);

        $this->modalName = $scope === 'contextual'
            ? 'assign-role-ctx-'.md5($contextClass).'-'.$userId
            : 'assign-role-global-'.$userId;

        $clearance = app(Clearance::class);
        abort_unless((bool) config('clearance.modules.users', false), 404);
        abort_unless(ctype_digit((string) $this->userId), 403);
        abort_unless($clearance->canPerform('users') || $clearance->managesAnyContext(), 403);
        abort_unless($clearance->canManageUser($this->resolveUser()), 403);
    }

    /**
     * Validation rules.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'selectedRoleId' => ['required', 'integer'],
            'selectedContextIds' => ['required_if:scope,contextual', 'array'],
            'selectedContextIds.*' => ['integer'],
        ];
    }

    /**
     * Human-readable attribute names for validation messages.
     *
     * @return array<string, string>
     */
    public function validationAttributes(): array
    {
        return [
            'selectedRoleId' => __('clearance::ui.common.role'),
            'selectedContextIds' => __('clearance::ui.user_clearance.assign.pick_context_instances'),
        ];
    }

    /**
     * Assign the selected role to the user (global or contextual).
     */
    public function save(UserClearanceService $service): void
    {
        $clearance = app(Clearance::class);
        abort_unless(
            $this->scope === 'global'
                ? $clearance->canPerform('users')
                : ($clearance->canPerform('users') || $clearance->managesAnyContext()),
            403,
        );

        $user = $this->resolveUser();
        abort_unless($clearance->canManageUser($user), 403);

        $this->errorMessage = null;
        $this->resetErrorBag();

        try {
            $this->validate();
        } catch (ValidationException $e) {
            $this->errorMessage = $e->getMessage();
            if (app()->runningUnitTests()) {
                return;
            }
            throw $e;
        }

        $role = Role::findOrFail((int) $this->selectedRoleId);
        $actor = auth()->user();

        // super_admin can only be assigned by another super_admin.
        if (SuperAdmin::isRole($role)) {
            if (! SuperAdmin::is($actor)) {
                $this->errorMessage = __('clearance::ui.roles.errors.super_admin_only');

                return;
            }
        }

        // Prevent self-escalation: only super_admin can assign any role to themselves.
        if ($actor !== null && (string) $actor->getAuthIdentifier() === (string) $this->userId) {
            if (! SuperAdmin::is($actor)) {
                $this->errorMessage = __('clearance::ui.roles.errors.super_admin_only');

                return;
            }
        }

        if ($this->scope !== 'global') {
            $allowed = array_keys(config('clearance.contextual_models', []));
            abort_unless(in_array($this->contextClass, $allowed, true), 422);
        }

        // Fail closed: the actor-checked service methods need an authenticated actor.
        abort_if($actor === null, 403);

        try {
            if ($this->scope === 'global') {
                $service->assignGlobalRoleAs($actor, $user, $role);
            } else {
                /** @var class-string<Model> $contextClass */
                $contextClass = $this->contextClass;

                $contexts = [];
                foreach ($this->selectedContextIds as $ctxId) {
                    $context = $contextClass::find($ctxId);
                    abort_unless($context !== null && $clearance->managesContext($context), 403);
                    $contexts[] = $context;
                }

                // All-or-nothing: a rejection on any context rolls back the bindings already written.
                Role::query()->getConnection()->transaction(function () use ($service, $actor, $user, $role, $contexts): void {
                    foreach ($contexts as $context) {
                        $service->assignContextualAs($actor, $user, $role, $context);
                    }
                });
            }
        } catch (ClearanceScopeViolationException|ClearanceProtectedResourceException $e) {
            $this->errorMessage = ErrorMessage::for($e);

            return;
        }

        Flux::modal($this->modalName)->close();
        $this->dispatch('clearance:assignment-saved');

        $this->selectedRoleId = null;
        $this->selectedContextIds = [];
    }

    public function render(): View
    {
        $clearance = app(Clearance::class);
        abort_unless($clearance->canAccess(), 403);
        abort_unless($clearance->canPerform('users') || $clearance->managesAnyContext(), 403);

        $user = $this->resolveUser();
        abort_unless($clearance->canManageUser($user), 403);

        if ($this->scope === 'global') {
            /** @var Collection<int, Role> $roles */
            $roles = $user->getRelation('roles');
            $assignedRoleIds = $roles->pluck('id')->toArray();

            $availableRoles = Role::with('permissions')
                ->whereNotIn('id', $assignedRoleIds)
                ->orderBy('name')
                ->get()
                ->all();
        } else {
            // Same role can apply to different context instances
            $availableRoles = Role::with('permissions')
                ->orderBy('name')
                ->get()
                ->all();
        }

        // Roles with non-delegable permissions are assigned only by trusted code.
        $permissionService = app(PermissionService::class);
        $availableRoles = array_values(array_filter(
            $availableRoles,
            static fn (Role $role): bool => ! $permissionService->isSystemAssignedOnly($role),
        ));

        $contextInstances = [];

        if ($this->scope === 'contextual' && $this->contextClass !== '') {
            $allowed = array_keys(config('clearance.contextual_models', []));
            abort_unless(in_array($this->contextClass, $allowed, true), 422);
            /** @var class-string<Model> $contextClass */
            $contextClass = $this->contextClass;
            $contextInstances = app(Clearance::class)->managedInstances($contextClass);
        }

        return view('clearance::livewire.users.assign-role-modal', [
            'availableRoles' => $availableRoles,
            'contextInstances' => $contextInstances,
        ]);
    }

    /**
     * Fetch a fresh user instance with roles and permissions loaded. A missing user aborts with 403.
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

        // A missing user and an unmanaged one both answer 403, so the modal does not reveal which ids exist.
        $user = $userModel::with(['roles.permissions', 'permissions'])->find($this->userId);

        if ($user === null) {
            abort(403);
        }

        if (! $user instanceof Authenticatable) {
            throw new \LogicException('Configured user model ['.$userModel.'] must implement Authenticatable.');
        }

        return $user;
    }
}
