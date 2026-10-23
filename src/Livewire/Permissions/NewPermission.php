<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Livewire\Permissions;

use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;
use Rivalex\Clearance\Clearance;

/**
 * Thin modal wrapper - opens create mode of PermissionForm in a Flux modal.
 *
 * Renders for anyone with the access permission; the trigger and the modal are shown only
 * to users who may perform the permissions section write actions.
 */
class NewPermission extends Component
{
    public bool $showModal = false;

    #[On('permission-saved')]
    public function onPermissionSaved(): void
    {
        $this->showModal = false;
    }

    public function render(): View
    {
        abort_unless(app(Clearance::class)->canAccess(), 403);

        return view('clearance::livewire.permissions.new-permission', [
            'canCreate' => app(Clearance::class)->canPerform('permissions'),
        ]);
    }
}
