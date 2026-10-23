<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Livewire\Roles;

use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Rivalex\Clearance\Clearance;
use Rivalex\Clearance\Exceptions\ClearanceProtectedResourceException;
use Rivalex\Clearance\Exceptions\ClearanceScopeViolationException;
use Rivalex\Clearance\Models\RoleMeta;
use Rivalex\Clearance\Models\UserRoleContext;
use Rivalex\Clearance\Services\PermissionService;
use Rivalex\Clearance\Services\RoleService;
use Rivalex\Clearance\Services\UserClearanceService;
use Rivalex\Clearance\Support\ErrorMessage;
use Rivalex\Clearance\Support\SpatieSchema;
use Spatie\Permission\Models\Role;

/**
 * Modal typed-confirmation delete for a role.
 * When users are assigned (global or contextual), offers reassign or detach before deletion.
 */
class DeleteRole extends Component
{
    #[Locked]
    public int $roleId;

    #[Locked]
    public string $modalName = '';

    public string $confirmText = '';

    /** @var 'detach'|'reassign' */
    public string $action = 'detach';

    public ?int $targetRoleId = null;

    public ?string $errorMessage = null;

    public function mount(): void
    {
        $this->modalName = 'delete-role-'.$this->roleId;
    }

    public function confirmDelete(RoleService $roleService, UserClearanceService $userService): void
    {
        abort_unless(app(Clearance::class)->canPerform('roles'), 403);

        $role = Role::find($this->roleId);

        if ($role === null) {
            return;
        }

        // Locked roles cannot be deleted
        if (RoleMeta::where('role_id', $role->id)->value('is_locked')) {
            $this->errorMessage = __('clearance::ui.roles.delete.locked_cannot_delete');

            return;
        }

        // Typed confirmation must pass before any destructive DB mutation
        if ($this->confirmText !== 'DELETE '.$role->name) {
            return;
        }

        $actor = auth()->user();
        abort_if($actor === null, 403);

        try {
            $roleService->assertCanDeleteAs($actor, $role);
        } catch (ClearanceScopeViolationException|ClearanceProtectedResourceException $e) {
            $this->errorMessage = ErrorMessage::for($e);

            return;
        }

        // The holder decision (detach, reassign or plain delete) is taken inside the transaction, after the role
        // row (and the requested target) is locked, in ascending key order and before any holder read.
        $lockIds = [$role->getKey()];

        if ($this->action === 'reassign' && $this->targetRoleId !== null && $this->targetRoleId !== $this->roleId) {
            $lockIds[] = $this->targetRoleId;
        }

        try {
            $outcome = $role->getConnection()->transaction(function () use ($lockIds, $actor, $role, $roleService, $userService): ?string {
                get_class($role)::query()->whereKey($lockIds)->orderBy($role->getKeyName())->lockForUpdate()->get();

                $globalHolders = $role->getConnection()->table(SpatieSchema::table('model_has_roles'))->where(SpatieSchema::rolePivotKey(), $role->getKey())->count();
                $bindings = UserRoleContext::where('role_id', $role->getKey())->count();
                $hasAffected = $globalHolders + $bindings > 0;

                $limit = (int) config('clearance.panel_role_holder_limit', 1000);
                $overLimit = $globalHolders + $bindings > $limit;
                $targetRole = null;

                if ($hasAffected && $this->action === 'reassign') {
                    if ($this->targetRoleId === null || $this->targetRoleId === $this->roleId) {
                        return __('clearance::ui.roles.delete.no_other_roles');
                    }

                    $targetRole = Role::find($this->targetRoleId);

                    if ($targetRole === null) {
                        return '';
                    }

                    if ($targetRole->guard_name !== $role->guard_name) {
                        return __('clearance::ui.roles.delete.no_other_roles');
                    }
                }

                if ($overLimit) {
                    return __('clearance::ui.roles.delete.holder_limit', ['count' => $globalHolders + $bindings, 'limit' => $limit]);
                }

                if ($targetRole !== null) {
                    $userService->reassignRoleHoldersAs($actor, $role, $targetRole);
                } elseif ($hasAffected) {
                    $userService->detachRoleHoldersAs($actor, $role);
                }

                $roleService->deleteAs($actor, $role);

                return null;
            });
        } catch (ClearanceScopeViolationException|ClearanceProtectedResourceException $e) {
            $this->errorMessage = ErrorMessage::for($e);

            return;
        }

        if ($outcome !== null) {
            if ($outcome !== '') {
                $this->errorMessage = $outcome;
            }

            return;
        }

        $this->confirmText = '';
        $this->errorMessage = null;
        $this->dispatch('role-deleted');
    }

    public function render(PermissionService $permissionService): View
    {
        abort_unless(app(Clearance::class)->canAccess(), 403);

        $role = Role::find($this->roleId);

        $isLocked = $role !== null
            ? (bool) RoleMeta::where('role_id', $this->roleId)->value('is_locked')
            : false;

        $affectedGlobalCount = $role !== null
            ? $role->getConnection()->table(SpatieSchema::table('model_has_roles'))->where(SpatieSchema::rolePivotKey(), $this->roleId)->count()
            : 0;

        $affectedContextualCount = $role !== null
            ? UserRoleContext::where('role_id', $this->roleId)->count()
            : 0;

        $otherRoles = $role !== null
            ? Role::with('permissions')->where('guard_name', $role->guard_name)
                ->where('id', '!=', $this->roleId)
                ->orderBy('name')
                ->get()
                ->reject(static fn (Role $other): bool => $permissionService->isSystemAssignedOnly($other))
                ->values()
            : collect();

        return view('clearance::livewire.roles.delete-role', compact(
            'role',
            'isLocked',
            'affectedGlobalCount',
            'affectedContextualCount',
            'otherRoles',
        ));
    }
}
