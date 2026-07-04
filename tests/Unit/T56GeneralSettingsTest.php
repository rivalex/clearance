<?php

declare(strict_types=1);

use Livewire\Livewire;
use Rivalex\Clearance\Livewire\Settings\GeneralSettings;
use Rivalex\Clearance\Models\ClearanceSettings;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $this->runMigrations();
});

it('mount defaults to no default role and icons shown when nothing is stored', function (): void {
    Livewire::test(GeneralSettings::class)
        ->assertSet('defaultRole', '')
        ->assertSet('showIcons', true);
});

it('mount hydrates from stored settings', function (): void {
    ClearanceSettings::set('default_role', 'manager');
    ClearanceSettings::set('show_icons', '0');

    Livewire::test(GeneralSettings::class)
        ->assertSet('defaultRole', 'manager')
        ->assertSet('showIcons', false);
});

it('renders roles ordered by name', function (): void {
    Role::create(['name' => 'zebra', 'guard_name' => 'web']);
    Role::create(['name' => 'alpha', 'guard_name' => 'web']);

    Livewire::test(GeneralSettings::class)
        ->assertViewHas('roles', fn ($roles) => $roles->pluck('name')->all() === ['alpha', 'zebra']);
});

it('rejects saving a default role that does not exist', function (): void {
    Livewire::test(GeneralSettings::class)
        ->set('defaultRole', 'ghost')
        ->call('saveGeneral')
        ->assertHasErrors('defaultRole');

    expect(ClearanceSettings::get('default_role'))->toBeNull();
});

it('persists default role and show_icons on successful save', function (): void {
    Role::create(['name' => 'manager', 'guard_name' => 'web']);

    Livewire::test(GeneralSettings::class)
        ->set('defaultRole', 'manager')
        ->set('showIcons', false)
        ->call('saveGeneral')
        ->assertHasNoErrors()
        ->assertSet('saveMessage', __('clearance::ui.settings.general.saved'));

    expect(ClearanceSettings::get('default_role'))->toBe('manager')
        ->and(ClearanceSettings::get('show_icons'))->toBe('0');
});

it('stores null default_role when the field is cleared', function (): void {
    ClearanceSettings::set('default_role', 'manager');
    Role::create(['name' => 'manager', 'guard_name' => 'web']);

    Livewire::test(GeneralSettings::class)
        ->set('defaultRole', '')
        ->call('saveGeneral')
        ->assertHasNoErrors();

    expect(ClearanceSettings::get('default_role'))->toBeNull();
});
