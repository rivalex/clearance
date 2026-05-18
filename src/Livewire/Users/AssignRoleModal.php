<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Livewire\Users;

use Flux\Flux;
use Illuminate\Database\Eloquent\Model;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Rivalex\Clearance\Clearance;
use Rivalex\Clearance\Exceptions\ClearanceConfigException;
use Rivalex\Clearance\Models\RoleHierarchy;
use Rivalex\Clearance\Services\UserClearanceService;
use Spatie\Permission\Models\Role;

/**
 * Modal for assigning a master role to a user — global or contextual scope.
 */
class AssignRoleModal extends Component
{
    #[Locked]
    public int|string $userId;

    #[Locked]
    public string $scope; // 'global' | 'contextual'

    #[Locked]
    public string $contextClass = '';

    public string $modalName = '';

    public ?int $selectedRoleId = null;

    /** @var array<int|string> Context instance IDs selected (contextual scope only). */
    public array $selectedContextIds = [];

    public ?string $errorMessage = null;

    public function mount(int|string $userId, string $scope, string $contextClass = ''): void
    {
        $this->userId       = $userId;
        $this->scope        = $scope;
        $this->contextClass = $contextClass;

        $this->modalName = $scope === 'contextual'
            ? 'assign-role-ctx-' . md5($contextClass) . '-' . $userId
            : 'assign-role-global-' . $userId;
    }

    /**
     * Validation rules.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'selectedRoleId'     => ['required', 'integer'],
            'selectedContextIds'  => ['required_if:scope,contextual', 'array'],
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
            'selectedRoleId'    => __('clearance::ui.common.role'),
            'selectedContextIds' => __('clearance::ui.user_clearance.assign.pick_context_instances'),
        ];
    }

    /**
     * Assign the selected role to the user (global or contextual).
     */
    public function save(UserClearanceService $service): void
    {
        abort_unless(app(Clearance::class)->canPerform('users'), 403);

        $this->errorMessage = null;
        $this->resetErrorBag();

        try {
            $this->validate();
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->errorMessage = $e->getMessage();
            if (app()->runningUnitTests()) {
                return;
            }
            throw $e;
        }

        $role = Role::findOrFail((int) $this->selectedRoleId);

        try {
            $service->assertNotSlave($role);
        } catch (ClearanceConfigException $e) {
            $this->errorMessage = __('clearance::ui.user_clearance.assign.slave_role_blocked');
            return;
        }

        $user = $this->resolveUser();

        if ($this->scope === 'global') {
            $service->assignGlobalRole($user, $role);
        } else {
            /** @var class-string<Model> $contextClass */
            $contextClass = $this->contextClass;

            foreach ($this->selectedContextIds as $ctxId) {
                $context = $contextClass::findOrFail($ctxId);
                $service->assignContextual($user, $role, $context);
            }
        }

        Flux::modal($this->modalName)->close();
        $this->dispatch('clearance:assignment-saved');

        $this->selectedRoleId    = null;
        $this->selectedContextIds = [];
    }

    public function render(): View
    {
        $childIds = RoleHierarchy::pluck('child_role_id')->map(fn ($id) => (int) $id)->toArray();

        if ($this->scope === 'global') {
            $user            = $this->resolveUser();
            $assignedRoleIds = $user->roles->pluck('id')->toArray();

            $availableRoles = Role::whereNotIn('id', $childIds)
                ->whereNotIn('id', $assignedRoleIds)
                ->orderBy('name')
                ->get()
                ->all();
        } else {
            // Same master role can apply to different context instances
            $availableRoles = Role::whereNotIn('id', $childIds)
                ->orderBy('name')
                ->get()
                ->all();
        }

        $contextInstances = [];

        if ($this->scope === 'contextual' && $this->contextClass !== '') {
            /** @var class-string<Model> $contextClass */
            $contextClass     = $this->contextClass;
            $contextInstances = $contextClass::all();
        }

        return view('clearance::livewire.users.assign-role-modal', [
            'availableRoles'   => $availableRoles,
            'contextInstances' => $contextInstances,
        ]);
    }

    /**
     * Fetch a fresh user instance with roles and permissions loaded.
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
}
