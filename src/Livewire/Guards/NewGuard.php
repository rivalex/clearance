<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Livewire\Guards;

use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;
use Rivalex\Clearance\Clearance;

class NewGuard extends Component
{
    public bool $showModal = false;

    #[On('guard-saved')]
    public function onGuardSaved(): void
    {
        $this->showModal = false;
    }

    public function render(): View
    {
        abort_unless(app(Clearance::class)->canAccess(), 403);

        return view('clearance::livewire.guards.new-guard');
    }
}
