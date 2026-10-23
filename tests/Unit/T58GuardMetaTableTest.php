<?php

declare(strict_types=1);

use Livewire\Livewire;
use Rivalex\Clearance\Livewire\Settings\GuardMetaTable;
use Rivalex\Clearance\Models\ClearanceMeta;
use Rivalex\Clearance\Models\Guard;

beforeEach(function (): void {
    $this->runMigrations();
});

it('renders guards with their keyed meta', function (): void {
    Guard::create(['name' => 'web']);
    Guard::create(['name' => 'api']);
    ClearanceMeta::create(['subject_type' => 'guard', 'subject_key' => 'api', 'display_name' => 'API']);

    Livewire::test(GuardMetaTable::class)
        ->assertViewHas('guards')
        ->assertViewHas('guardMetas', fn ($metas) => $metas->has('api') && ! $metas->has('web'));
});

it('does not error when dispatching meta-saved', function (): void {
    Livewire::test(GuardMetaTable::class)
        ->dispatch('meta-saved')
        ->assertOk();
});

it('renders the guard-meta-table view', function (): void {
    Livewire::test(GuardMetaTable::class)
        ->assertViewIs('clearance::livewire.settings.guard-meta-table');
});
