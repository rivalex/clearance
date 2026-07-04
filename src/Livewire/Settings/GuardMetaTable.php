<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Livewire\Settings;

use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;
use Rivalex\Clearance\Clearance;
use Rivalex\Clearance\Models\ClearanceMeta;
use Rivalex\Clearance\Services\GuardService;

/**
 * Table listing guards with their display meta (name, icon, colour).
 * Refreshes when any EditMeta saves.
 */
class GuardMetaTable extends Component
{
    #[On('meta-saved')]
    public function refresh(): void {}

    public function render(): View
    {
        abort_unless(app(Clearance::class)->canPerform('settings'), 403);

        $guards = app(GuardService::class)->all();
        $guardMetas = ClearanceMeta::where('subject_type', 'guard')->get()->keyBy('subject_key');

        return view('clearance::livewire.settings.guard-meta-table', compact('guards', 'guardMetas'));
    }
}
