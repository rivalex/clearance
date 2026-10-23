<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Livewire\Guards;

use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Rivalex\Clearance\Clearance;
use Rivalex\Clearance\Exceptions\ClearanceProtectedResourceException;
use Rivalex\Clearance\Models\Guard;
use Rivalex\Clearance\Services\GuardService;
use Rivalex\Clearance\Support\ErrorMessage;

/**
 * Modal typed-confirmation delete for a DB-managed guard.
 */
class DeleteGuard extends Component
{
    #[Locked]
    public int $guardId;

    #[Locked]
    public string $name;

    #[Locked]
    public string $modalName = '';

    public string $confirmText = '';

    /** Translated reason of the last refused delete, shown in the modal. */
    public ?string $errorMessage = null;

    public function mount(): void
    {
        $this->modalName = 'delete-guard-'.$this->guardId;
    }

    public function confirmDelete(GuardService $service): void
    {
        abort_unless(app(Clearance::class)->canPerform('guards'), 403);

        if ($this->confirmText !== 'DELETE '.$this->name) {
            return;
        }

        $this->errorMessage = null;

        $guard = Guard::find($this->guardId);
        if ($guard) {
            try {
                $service->delete($guard);
            } catch (ClearanceProtectedResourceException $e) {
                $this->errorMessage = ErrorMessage::for($e);

                return;
            }
        }

        $this->confirmText = '';
        $this->dispatch('guard-deleted');
    }

    public function render(): View
    {
        abort_unless(app(Clearance::class)->canAccess(), 403);

        return view('clearance::livewire.guards.delete-guard');
    }
}
