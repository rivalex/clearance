<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Livewire\Permissions;

use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Thin modal wrapper - opens edit mode of PermissionForm for a given prefix.
 */
class EditPermission extends Component
{
    #[Locked]
    public string $prefix;

    #[Locked]
    public string $guard;

    public string $modalName = '';

    #[Locked]
    public string $groupKey;

    public function mount(): void
    {
        $this->modalName = 'edit-permission-' . $this->groupKey;
    }

    public function render(): View
    {
        return view('clearance::livewire.permissions.edit-permission');
    }
}
