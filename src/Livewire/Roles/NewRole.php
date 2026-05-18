<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Livewire\Roles;

use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;
use Rivalex\Clearance\Clearance;

/**
 * Thin modal wrapper - opens create mode of RoleForm in a Flux modal.
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
        abort_unless(app(Clearance::class)->canPerform('roles'), 403);

        return view('clearance::livewire.roles.new-role');
    }
}
