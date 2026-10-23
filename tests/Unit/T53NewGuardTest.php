<?php

declare(strict_types=1);

use Livewire\Livewire;
use Rivalex\Clearance\Livewire\Guards\NewGuard;

beforeEach(function (): void {
    $this->runMigrations();
});

it('defaults showModal to false', function (): void {
    Livewire::test(NewGuard::class)
        ->assertSet('showModal', false);
});

it('renders the new-guard view', function (): void {
    Livewire::test(NewGuard::class)
        ->assertViewIs('clearance::livewire.guards.new-guard');
});

it('closes the modal when a guard-saved event is dispatched', function (): void {
    Livewire::test(NewGuard::class)
        ->set('showModal', true)
        ->dispatch('guard-saved')
        ->assertSet('showModal', false);
});
