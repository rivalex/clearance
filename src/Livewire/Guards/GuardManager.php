<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Livewire\Guards;

use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Rivalex\Clearance\Services\GuardService;

/**
 * Read-only screen listing all configured authentication guards.
 */
#[Layout('clearance::layouts.app')]
class GuardManager extends Component
{
    /** @var array<string, array<string, mixed>> */
    public array $guards = [];

    /**
     * Load guards on component mount (I.Guards — read-only).
     */
    public function mount(GuardService $guardService): void
    {
        $this->guards = $guardService->all();
    }

    public function render(): View
    {
        return view('clearance::livewire.guards.guard-manager');
    }
}
