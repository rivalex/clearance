<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Livewire\Permissions;

use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Rivalex\Clearance\Clearance;
use Rivalex\Clearance\Exceptions\ClearanceProtectedResourceException;
use Rivalex\Clearance\Exceptions\ClearanceScopeViolationException;
use Rivalex\Clearance\Services\PermissionService;
use Rivalex\Clearance\Support\ErrorMessage;

/**
 * Modal typed-confirmation delete for a permission group (T25, V8).
 */
class DeletePermission extends Component
{
    #[Locked]
    public string $prefix;

    #[Locked]
    public string $guard;

    #[Locked]
    public string $modalName = '';

    #[Locked]
    public string $groupKey;

    public string $confirmText = '';

    /** Translated reason of the last refused delete, shown in the modal. */
    public ?string $errorMessage = null;

    public function mount(): void
    {
        $this->modalName = 'delete-permission-'.$this->groupKey;
    }

    /**
     * Execute delete after typed confirmation (T25, V8 - writes via PermissionService).
     */
    public function confirmDelete(PermissionService $permissionService): void
    {
        abort_unless(app(Clearance::class)->canPerform('permissions'), 403);

        if (str_starts_with($this->prefix, 'clearance')) {
            return;
        }

        if ($this->confirmText !== 'DELETE '.$this->prefix) {
            return;
        }

        $actor = auth()->user();
        abort_if($actor === null, 403);

        $this->errorMessage = null;

        // The service refuses a protected member and checks the actor rules, then deletes the group in one transaction.
        try {
            $permissionService->deleteGroupAs($actor, $this->prefix, $this->guard);
        } catch (ClearanceScopeViolationException|ClearanceProtectedResourceException $e) {
            $this->errorMessage = ErrorMessage::for($e);

            return;
        }

        $this->confirmText = '';
        $this->dispatch('permission-deleted');
    }

    public function render(): View
    {
        abort_unless(app(Clearance::class)->canAccess(), 403);

        return view('clearance::livewire.permissions.delete-permission');
    }
}
