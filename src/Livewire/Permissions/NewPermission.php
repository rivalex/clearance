<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Livewire\Permissions;

use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Thin modal wrapper — opens create mode of PermissionForm in a Flux modal.
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
        return view('clearance::livewire.permissions.new-permission');
    }
}
