<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Livewire\Guards;

use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Rivalex\Clearance\Clearance;

/**
 * Thin modal wrapper - opens edit mode of GuardForm for a given guard.
 */
class EditGuard extends Component
{
    #[Locked]
    public int $guardId;

    #[Locked]
    public string $guardName;

    #[Locked]
    public string $modalName = '';

    public function mount(): void
    {
        $this->modalName = 'edit-guard-'.$this->guardId;
    }

    public function render(): View
    {
        abort_unless(app(Clearance::class)->canAccess(), 403);

        return view('clearance::livewire.guards.edit-guard');
    }
}
