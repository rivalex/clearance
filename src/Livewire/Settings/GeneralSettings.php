<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Livewire\Settings;

use Illuminate\View\View;
use Livewire\Component;
use Rivalex\Clearance\Clearance;
use Rivalex\Clearance\Exceptions\ClearanceProtectedResourceException;
use Rivalex\Clearance\Exceptions\ClearanceScopeViolationException;
use Rivalex\Clearance\Models\ClearanceSettings;
use Rivalex\Clearance\Services\UserClearanceService;
use Rivalex\Clearance\Support\ErrorMessage;
use Rivalex\Clearance\Support\Identity;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * General settings card: default role for new users + show icons toggle.
 */
class GeneralSettings extends Component
{
    /** The default role primary key as a string, '' for none. */
    public string $defaultRole = '';

    public bool $showIcons = true;

    public ?string $saveMessage = null;

    public function mount(): void
    {
        $stored = ClearanceSettings::get('default_role_id');
        $this->defaultRole = is_string($stored) && ctype_digit($stored) ? $stored : '';
        $this->showIcons = filter_var(ClearanceSettings::get('show_icons', '1'), FILTER_VALIDATE_BOOLEAN);
    }

    public function saveGeneral(): void
    {
        abort_unless(app(Clearance::class)->canPerform('settings'), 403);

        $role = null;

        if ($this->defaultRole !== '') {
            // The value is a role id; a role of another guard would make the bulk assignment throw GuardDoesNotMatch.
            if (! ctype_digit($this->defaultRole)) {
                $this->addError('defaultRole', __('clearance::ui.settings.bulk_role_not_found'));

                return;
            }

            /** @var Role|null $role */
            $role = app(PermissionRegistrar::class)->getRoleClass()::query()->whereKey((int) $this->defaultRole)->first();

            if ($role === null || $role->guard_name !== Identity::guard()) {
                $this->addError('defaultRole', __('clearance::ui.settings.bulk_role_not_found'));

                return;
            }

            $actor = auth()->user();
            abort_if($actor === null, 403);

            try {
                $service = app(UserClearanceService::class);
                $service->assertCanBeDefaultRole($role);
                $service->assertCanAssignGlobalRole($actor, $role);
            } catch (ClearanceScopeViolationException|ClearanceProtectedResourceException $e) {
                $this->addError('defaultRole', ErrorMessage::for($e));

                return;
            }
        }

        ClearanceSettings::set('default_role_id', $role !== null ? (string) $role->getKey() : null);
        ClearanceSettings::set('show_icons', $this->showIcons ? '1' : '0');

        $this->saveMessage = __('clearance::ui.settings.general.saved');
    }

    public function render(): View
    {
        abort_unless(app(Clearance::class)->canAccess(), 403);

        $roles = Role::where('guard_name', Identity::guard())->orderBy('name')->get();

        return view('clearance::livewire.settings.general-settings', compact('roles'));
    }
}
