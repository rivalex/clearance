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
use Rivalex\Clearance\Services\UserClearanceService;
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
    }

    /**
     * Execute the removal after typed confirmation (must type "DELETE <roleName>").
     */
    public function confirmDelete(UserClearanceService $service): void
    {
        abort_unless(app(Clearance::class)->canPerform('users'), 403);

        $role = Role::findOrFail($this->roleId);

        if ($this->confirmText !== 'DELETE '.$role->name) {
            return;
        }

        $user = $this->resolveUser();

        if ($this->scope === 'global') {
            $service->removeGlobalRole($user, $role);
        } else {
            $allowed = array_keys(config('clearance.contextual_models', []));
            abort_unless(in_array($this->contextClass, $allowed, true), 422);
            /** @var class-string<Model> $contextClass */
            $contextClass = $this->contextClass;
            $context = $contextClass::findOrFail($this->contextId);
            $service->removeContextual($user, $role, $context);
        }

        $this->confirmText = '';

        Flux::modal($this->modalName)->close();
        $this->dispatch('clearance:assignment-removed');
    }

    public function render(): View
    {
        $role = Role::findOrFail($this->roleId);

        return view('clearance::livewire.users.remove-assignment-modal', [
            'role' => $role,
        ]);
    }

    /**
     * Fetch a fresh user instance with roles and permissions loaded.
     *
     * @return Model&Authenticatable
     */
    private function resolveUser(): Model
    {
        /** @var class-string<Model> $userModel */
        $userModel = config('clearance.user_model')
            ?? config('auth.providers.users.model', 'App\\Models\\User');

        return $userModel::with(['roles.permissions', 'permissions'])->findOrFail($this->userId);
    }
}
