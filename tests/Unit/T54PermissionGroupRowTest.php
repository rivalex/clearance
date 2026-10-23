<?php

declare(strict_types=1);

use Livewire\Livewire;
use Rivalex\Clearance\Livewire\Permissions\PermissionGroupRow;
use Rivalex\Clearance\Models\Permission;

beforeEach(function (): void {
    $this->runMigrations();

    Permission::create(['name' => 'orders-read', 'guard_name' => 'web']);
    Permission::create(['name' => 'orders-create', 'guard_name' => 'web']);
});

it('mount derives groupKey, prefix and separator defaults', function (): void {
    $component = Livewire::test(PermissionGroupRow::class, ['group' => 'orders', 'guard' => 'web']);

    $component
        ->assertSet('groupKey', md5('orders|web'))
        ->assertSet('prefix', 'orders')
        ->assertSet('sep', '-');
});

it('mount populates groupData/abilities/labels from Permission::abilities', function (): void {
    $component = Livewire::test(PermissionGroupRow::class, ['group' => 'orders', 'guard' => 'web']);

    $abilities = $component->get('abilities');
    $labels = $component->get('labels');

    expect($abilities)->toBeArray()->not->toBeEmpty()
        ->and(collect($abilities)->pluck('name')->all())->toContain('read', 'create')
        ->and($labels['group'])->toBe('Orders')
        ->and($labels['guard'])->toBe('Web');
});

it('keeps explicit groupKey, prefix and sep when provided instead of deriving them', function (): void {
    Livewire::test(PermissionGroupRow::class, [
        'group' => 'orders',
        'guard' => 'web',
        'groupKey' => 'custom-key',
        'prefix' => 'custom-prefix',
        'sep' => '_',
    ])
        ->assertSet('groupKey', 'custom-key')
        ->assertSet('prefix', 'custom-prefix')
        ->assertSet('sep', '_');
});

it('getListeners registers reloadGroup scoped to this row groupKey', function (): void {
    $component = new PermissionGroupRow;
    $component->group = 'orders';
    $component->guard = 'web';
    app()->call([$component, 'mount']);

    $listeners = $component->getListeners();

    expect($listeners)->toHaveKey("permission-saved.{$component->groupKey}")
        ->and($listeners["permission-saved.{$component->groupKey}"])->toBe('reloadGroup');
});

it('reloadGroup refreshes abilities after a new permission is added to the group', function (): void {
    $component = Livewire::test(PermissionGroupRow::class, ['group' => 'orders', 'guard' => 'web']);

    Permission::create(['name' => 'orders-delete', 'guard_name' => 'web']);

    $component->call('reloadGroup');

    expect(collect($component->get('abilities'))->pluck('name')->all())->toContain('delete');
});

it('renders the permission-group-row view', function (): void {
    Livewire::test(PermissionGroupRow::class, ['group' => 'orders', 'guard' => 'web'])
        ->assertViewIs('clearance::livewire.permissions.permission-group-row');
});
