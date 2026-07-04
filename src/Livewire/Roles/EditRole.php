<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Livewire\Roles;

use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Thin modal wrapper - opens edit mode of RoleForm for a given role ID.
 */
class EditRole extends Component
{
    #[Locked]
    public int $roleId;

    public string $modalName = '';

    public function mount(): void
    {
        $this->modalName = 'edit-role-'.$this->roleId;
    }

    public function render(): View
    {
        return view('clearance::livewire.roles.edit-role');
    }
}
