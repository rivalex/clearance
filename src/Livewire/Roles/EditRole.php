<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Livewire\Roles;

use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Rivalex\Clearance\Clearance;

/**
 * Thin modal wrapper - opens edit mode of RoleForm for a given role ID.
 */
class EditRole extends Component
{
    #[Locked]
    public int $roleId;

    #[Locked]
    public string $modalName = '';

    public function mount(): void
    {
        $this->modalName = 'edit-role-'.$this->roleId;
    }

    public function render(): View
    {
        abort_unless(app(Clearance::class)->canAccess(), 403);

        return view('clearance::livewire.roles.edit-role');
    }
}
