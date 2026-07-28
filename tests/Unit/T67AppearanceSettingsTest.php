<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Livewire\Livewire;
use Rivalex\Clearance\Livewire\Settings\AppearanceSettings;
use Rivalex\Clearance\Models\ClearanceSettings;

beforeEach(function (): void {
    $this->runMigrations();
});

it('mount defaults to false when nothing is stored and config default is false', function (): void {
    Livewire::test(AppearanceSettings::class)
        ->assertSet('forceDarkMode', false);
});

it('mount falls back to the config default when nothing is stored', function (): void {
    Config::set('clearance.dark_mode.force', true);

    Livewire::test(AppearanceSettings::class)
        ->assertSet('forceDarkMode', true);
});

it('mount hydrates from stored settings, DB overrides config default', function (): void {
    Config::set('clearance.dark_mode.force', true);
    ClearanceSettings::set('dark_mode.force', '0');

    Livewire::test(AppearanceSettings::class)
        ->assertSet('forceDarkMode', false);
});

it('persists forceDarkMode on successful save', function (): void {
    Livewire::test(AppearanceSettings::class)
        ->set('forceDarkMode', true)
        ->call('saveAppearance')
        ->assertHasNoErrors()
        ->assertSet('saveMessage', __('clearance::ui.settings.appearance.saved'));

    expect(ClearanceSettings::get('dark_mode.force'))->toBe('1');
});

it('persists false when toggled off', function (): void {
    ClearanceSettings::set('dark_mode.force', '1');

    Livewire::test(AppearanceSettings::class)
        ->set('forceDarkMode', false)
        ->call('saveAppearance')
        ->assertHasNoErrors();

    expect(ClearanceSettings::get('dark_mode.force'))->toBe('0');
});
