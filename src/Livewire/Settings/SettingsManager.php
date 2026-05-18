<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Livewire\Settings;

use Illuminate\View\View;
use Livewire\Attributes\Lazy;
use Livewire\Component;
use Rivalex\Clearance\Clearance;

/**
 * Top-level settings page - lazy wrapper that embeds focused sub-components.
 */
#[Lazy]
class SettingsManager extends Component
{
    public function placeholder(): View
    {
        return view('clearance::livewire.settings.placeholder');
    }

    public function render(): View
    {
        abort_unless(app(Clearance::class)->canAccess(), 403);

        return view('clearance::livewire.settings.manager');
    }
}
