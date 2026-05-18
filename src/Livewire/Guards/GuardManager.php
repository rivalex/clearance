<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Livewire\Guards;

use Illuminate\View\View;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\On;
use Livewire\Component;
use Rivalex\Clearance\Clearance;
use Rivalex\Clearance\Models\ClearanceMeta;
use Rivalex\Clearance\Models\ClearanceSettings;
use Rivalex\Clearance\Services\GuardService;

/**
 * Screen listing and managing authentication guards.
 */
#[Lazy]
class GuardManager extends Component
{
    public string $search = '';

    public function mount(): void {}

    public function updatingSearch(): void {}

    #[On('guard-saved')]
    #[On('guard-deleted')]
    public function refresh(): void {}

    public function placeholder(): View
    {
        return view('clearance::livewire.guards.placeholder');
    }

    public function render(): View
    {
        abort_unless(app(Clearance::class)->canAccess(), 403);

        $guards = app(GuardService::class)->all();

        if ($this->search !== '') {
            $q      = strtolower($this->search);
            $guards = array_filter(
                $guards,
                static fn (array $config, string $name): bool =>
                    str_contains(strtolower($name), $q)
                    || str_contains(strtolower($config['driver'] ?? ''), $q)
                    || str_contains(strtolower($config['provider'] ?? ''), $q),
                ARRAY_FILTER_USE_BOTH,
            );
        }

        $guardNames = array_keys($guards);

        $guardMetas = ClearanceMeta::where('subject_type', 'guard')
            ->whereIn('subject_key', $guardNames)
            ->get()
            ->keyBy('subject_key');

        $showIcons = filter_var(ClearanceSettings::get('show_icons', '1'), FILTER_VALIDATE_BOOLEAN);

        return view('clearance::livewire.guards.guard-manager', compact('guards', 'guardMetas', 'showIcons'));
    }
}
