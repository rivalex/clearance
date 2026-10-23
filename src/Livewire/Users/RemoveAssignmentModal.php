<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Livewire\Users;

use Flux\Flux;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Rivalex\Clearance\Clearance;
use Rivalex\Clearance\Exceptions\ClearanceProtectedResourceException;
use Rivalex\Clearance\Exceptions\ClearanceScopeViolationException;
use Rivalex\Clearance\Services\UserClearanceService;
use Rivalex\Clearance\Support\ErrorMessage;
use Rivalex\Clearance\Support\Identity;
use Spatie\Permission\Models\Role;

/**
 * Typed-confirm modal for removing a role assignment - global or contextual scope.
 */
class RemoveAssignmentModal extends Component
{
    #[Locked]
    public int|string $userId;

    #[Locked]
    public int $roleId;

    #[Locked]
    public string $scope; // 'global' | 'contextual'

    #[Locked]
    public string $contextClass = '';

    #[Locked]
    public int|string $contextId = 0;

    #[Locked]
    public string $modalName = '';

    public string $confirmText = '';

    public ?string $errorMessage = null;

    public function mount(
        int|string $userId,
        int $roleId,
        string $scope,
        string $contextClass = '',
        int|string $contextId = 0,
    ): void {
        $this->userId = $userId;
        $this->roleId = $roleId;
        $this->scope = $scope;
        $this->contextClass = $contextClass;
        $this->contextId = $contextId;

        $this->modalName = $scope === 'contextual'
            ? 'remove-role-ctx-'.md5($contextClass).'-'.$roleId.'-'.$contextId.'-'.$userId
            : 'remove-role-global-'.$roleId.'-'.$userId;

        $clearance = app(Clearance::class);
        abort_unless((bool) config('clearance.modules.users', false), 404);
        abort_unless(ctype_digit((string) $this->userId), 403);
        abort_unless($clearance->canPerform('users') || $clearance->managesAnyContext(), 403);
        abort_unless($clearance->canManageUser($this->resolveUser()), 403);
    }

    /**
     * Execute the removal after typed confirmation (must type "DELETE <roleName>").
     */
    public function confirmDelete(UserClearanceService $service): void
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

        $role = Role::findOrFail($this->roleId);

        if ($this->confirmText !== 'DELETE '.$role->name) {
            return;
        }

        $actor = auth()->user();
        abort_if($actor === null, 403);

        $this->errorMessage = null;

        try {
            $this->remove($service, $clearance, $actor, $user, $role);
        } catch (ClearanceScopeViolationException|ClearanceProtectedResourceException $e) {
            $this->errorMessage = ErrorMessage::for($e);

            return;
        }

        $this->confirmText = '';

        Flux::modal($this->modalName)->close();
        $this->dispatch('clearance:assignment-removed');
    }

    /**
     * Run the actor-checked removal for the modal scope.
     */
    private function remove(UserClearanceService $service, Clearance $clearance, Authenticatable $actor, Authenticatable $user, Role $role): void
    {
        if ($this->scope === 'global') {
            $service->removeGlobalRoleAs($actor, $user, $role);
        } else {
            $allowed = array_keys(config('clearance.contextual_models', []));
            abort_unless(in_array($this->contextClass, $allowed, true), 422);
            /** @var class-string<Model> $contextClass */
            $contextClass = $this->contextClass;
            $context = $contextClass::find($this->contextId);
            abort_unless($context !== null && $clearance->managesContext($context), 403);
            $service->removeContextualAs($actor, $user, $role, $context);
        }
    }

    public function render(): View
    {
        abort_unless(app(Clearance::class)->canAccess(), 403);

        $role = Role::findOrFail($this->roleId);

        return view('clearance::livewire.users.remove-assignment-modal', [
            'role' => $role,
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
