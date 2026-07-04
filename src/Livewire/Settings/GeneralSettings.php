<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Livewire\Settings;

use Illuminate\View\View;
use Livewire\Component;
use Rivalex\Clearance\Clearance;
use Rivalex\Clearance\Models\ClearanceSettings;
use Spatie\Permission\Models\Role;

/**
 * General settings card: default role for new users + show icons toggle.
 */
class GeneralSettings extends Component
{
    public string $defaultRole = '';

    public bool $showIcons = true;

    public ?string $saveMessage = null;

    public function mount(): void
    {
        $this->defaultRole = ClearanceSettings::get('default_role') ?? '';
        $this->showIcons = filter_var(ClearanceSettings::get('show_icons', '1'), FILTER_VALIDATE_BOOLEAN);
    }

    public function saveGeneral(): void
    {
        abort_unless(app(Clearance::class)->canPerform('settings'), 403);

        if ($this->defaultRole && ! Role::where('name', $this->defaultRole)->exists()) {
            $this->addError('defaultRole', __('clearance::ui.settings.bulk_role_not_found'));

            return;
        }

        ClearanceSettings::set('default_role', $this->defaultRole ?: null);
        ClearanceSettings::set('show_icons', $this->showIcons ? '1' : '0');

        $this->saveMessage = __('clearance::ui.settings.general.saved');
    }

    public function render(): View
    {
        $roles = Role::orderBy('name')->get();

        return view('clearance::livewire.settings.general-settings', compact('roles'));
    }
}
