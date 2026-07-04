<?php

declare(strict_types=1);

use Livewire\Livewire;
use Rivalex\Clearance\Livewire\Settings\RoleMetaTable;
use Rivalex\Clearance\Models\ClearanceMeta;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $this->runMigrations();
});

it('renders roles ordered by name with their keyed meta', function (): void {
    Role::create(['name' => 'zebra', 'guard_name' => 'web']);
    Role::create(['name' => 'alpha', 'guard_name' => 'web']);
    ClearanceMeta::create(['subject_type' => 'role', 'subject_key' => 'alpha', 'display_name' => 'Alpha']);

    Livewire::test(RoleMetaTable::class)
        ->assertViewHas('roles', fn ($roles) => $roles->pluck('name')->all() === ['alpha', 'zebra'])
        ->assertViewHas('roleMetas', fn ($metas) => $metas->has('alpha') && ! $metas->has('zebra'));
});

it('does not error when dispatching meta-saved', function (): void {
    Livewire::test(RoleMetaTable::class)
        ->dispatch('meta-saved')
        ->assertOk();
});

it('renders the role-meta-table view', function (): void {
    Livewire::test(RoleMetaTable::class)
        ->assertViewIs('clearance::livewire.settings.role-meta-table');
});
