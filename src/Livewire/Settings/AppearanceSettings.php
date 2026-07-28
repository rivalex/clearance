<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Livewire\Settings;

use Illuminate\View\View;
use Livewire\Component;
use Rivalex\Clearance\Clearance;
use Rivalex\Clearance\Models\ClearanceSettings;

/**
 * Appearance settings card: force dark mode override.
 *
 * Clearance's CSS uses a class-based `dark:` variant that follows the host
 * app's <html class="dark"> ancestor. When the host has no class-based dark
 * toggle, this DB-backed override (falling back to config('clearance.dark_mode.force'))
 * lets an operator force Clearance's dark theme on regardless.
 */
class AppearanceSettings extends Component
{
    public bool $forceDarkMode = false;

    public ?string $saveMessage = null;

    public function mount(): void
    {
        $this->forceDarkMode = filter_var(
            ClearanceSettings::get('dark_mode.force', config('clearance.dark_mode.force', false)),
            FILTER_VALIDATE_BOOLEAN
        );
    }

    public function saveAppearance(): void
    {
        abort_unless(app(Clearance::class)->canPerform('settings'), 403);

        ClearanceSettings::set('dark_mode.force', $this->forceDarkMode ? '1' : '0');

        $this->saveMessage = __('clearance::ui.settings.appearance.saved');
    }

    public function render(): View
    {
        return view('clearance::livewire.settings.appearance-settings');
    }
}
