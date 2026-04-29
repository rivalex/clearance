<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Livewire\Roles;

use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Thin modal wrapper — opens edit mode of RoleForm for a given role ID.
 */
class EditRole extends Component
{
    public int $roleId;

    public bool $showModal = false;

    #[On('role-saved')]
    public function onRoleSaved(): void
    {
        $this->showModal = false;
    }

    public function render(): View
    {
        return view('clearance::livewire.roles.edit-role');
    }
}
