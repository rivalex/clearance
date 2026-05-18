<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Livewire\Guards;

use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Rivalex\Clearance\Clearance;
use Rivalex\Clearance\Models\Guard;
use Rivalex\Clearance\Services\GuardService;

/**
 * Modal typed-confirmation delete for a DB-managed guard.
 */
class DeleteGuard extends Component
{
    #[Locked]
    public int $guardId;

    #[Locked]
    public string $name;

    public string $modalName = '';

    public string $confirmText = '';

    public function mount(): void
    {
        $this->modalName = 'delete-guard-' . $this->guardId;
    }

    public function confirmDelete(GuardService $service): void
    {
        abort_unless(app(Clearance::class)->canPerform('guards'), 403);

        if ($this->confirmText !== 'DELETE ' . $this->name) {
            return;
        }

        $guard = Guard::find($this->guardId);
        if ($guard) {
            $service->delete($guard);
        }

        $this->confirmText = '';
        $this->dispatch('guard-deleted');
    }

    public function render(): View
    {
        return view('clearance::livewire.guards.delete-guard');
    }
}
