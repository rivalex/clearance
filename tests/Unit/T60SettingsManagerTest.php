<?php

declare(strict_types=1);

use Livewire\Livewire;
use Rivalex\Clearance\Livewire\Settings\SettingsManager;

beforeEach(function (): void {
    $this->runMigrations();

    Livewire::withoutLazyLoading();
});

it('renders the settings manager view', function (): void {
    Livewire::test(SettingsManager::class)
        ->assertViewIs('clearance::livewire.settings.manager');
});

it('renders the placeholder view before hydration', function (): void {
    $component = new SettingsManager;

    expect($component->placeholder()->name())->toBe('clearance::livewire.settings.placeholder');
});
