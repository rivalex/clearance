<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Livewire\Permissions;

use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Rivalex\Clearance\Clearance;

/**
 * Thin modal wrapper - opens edit mode of PermissionForm for a given prefix.
 */
class EditPermission extends Component
{
    #[Locked]
    public string $prefix;

    #[Locked]
    public string $guard;

    #[Locked]
    public string $modalName = '';

    #[Locked]
    public string $groupKey;

    public function mount(): void
    {
        $this->modalName = 'edit-permission-'.$this->groupKey;
    }

    public function render(): View
    {
        abort_unless(app(Clearance::class)->canAccess(), 403);

        return view('clearance::livewire.permissions.edit-permission');
    }
}
