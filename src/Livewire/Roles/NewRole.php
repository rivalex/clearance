<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Livewire\Roles;

use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Thin modal wrapper — opens create mode of RoleForm in a Flux modal.
 */
class NewRole extends Component
{
    public bool $showModal = false;

    #[On('role-saved')]
    public function onRoleSaved(): void
    {
        $this->showModal = false;
    }

    public function render(): View
    {
        return view('clearance::livewire.roles.new-role');
    }
}
