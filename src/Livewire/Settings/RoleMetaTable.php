<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Livewire\Settings;

use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;
use Rivalex\Clearance\Models\ClearanceMeta;
use Spatie\Permission\Models\Role;

/**
 * Table listing roles with their display meta (name, icon, colour).
 * Refreshes when any EditMeta saves.
 */
class RoleMetaTable extends Component
{
    #[On('meta-saved')]
    public function refresh(): void {}

    public function render(): View
    {
        $roles     = Role::orderBy('name')->get();
        $roleMetas = ClearanceMeta::where('subject_type', 'role')->get()->keyBy('subject_key');

        return view('clearance::livewire.settings.role-meta-table', compact('roles', 'roleMetas'));
    }
}
