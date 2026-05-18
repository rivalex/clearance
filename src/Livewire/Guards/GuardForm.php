<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Livewire\Guards;

use Flux\Flux;
use Illuminate\View\View;
use Livewire\Component;
use Rivalex\Clearance\Clearance;
use Rivalex\Clearance\Models\Guard;
use Rivalex\Clearance\Services\GuardService;

/**
 * Shared form for creating and editing DB-managed guards.
 * Opened inside a named Flux modal by NewGuard or EditGuard wrappers.
 */
class GuardForm extends Component
{
    public ?int $guardId = null;

    public string $name = '';

    public string $driver = 'session';

    public string $provider = '';

    public string $modalName = '';

    public ?string $errorMessage = null;

    public function mount(?int $guardId = null): void
    {
        if ($guardId) {
            $guard = Guard::findOrFail($guardId);
            $this->guardId  = $guard->id;
            $this->name     = $guard->name;
            $this->driver   = $guard->driver;
            $this->provider = (string) $guard->provider;
        }
    }

    public function rules(): array
    {
        $unique = $this->guardId
            ? 'unique:clearance_guards,name,' . $this->guardId
            : 'unique:clearance_guards,name';

        return [
            'name'     => ['required', 'string', 'min:2', $unique],
            'driver'   => ['required', 'string'],
            'provider' => ['nullable', 'string'],
        ];
    }

    public function validationAttributes(): array
    {
        return [
            'name'     => __('clearance::ui.guards.form.guard_name'),
            'driver'   => __('clearance::ui.guards.form.driver'),
            'provider' => __('clearance::ui.guards.form.provider'),
        ];
    }

    public function save(GuardService $service): void
    {
        abort_unless(app(Clearance::class)->canPerform('guards'), 403);

        $allowed = config('clearance.allowed_guard_drivers', ['session', 'token', 'jwt', 'passport', 'sanctum']);
        if (! in_array($this->driver, (array) $allowed, true)) {
            $this->errorMessage = __('clearance::ui.guards.form.driver_invalid');
            return;
        }

        $this->errorMessage = null;
        $this->validate();

        if ($this->guardId) {
            $guard = Guard::findOrFail($this->guardId);
            $service->update($guard, $this->driver, $this->provider ?: null);
        } else {
            $service->create($this->name, $this->driver, $this->provider ?: null);
        }

        Flux::modal($this->modalName)->close();
        $this->dispatch('guard-saved');
    }

    public function render(): View
    {
        return view('clearance::livewire.guards.guard-form');
    }
}
