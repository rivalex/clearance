<?php

declare(strict_types=1);

use Rivalex\Clearance\Livewire\Permissions\PermissionForm;
use Rivalex\Clearance\Livewire\Permissions\PermissionManager;

it('PermissionManager class exists with required methods', function (): void {
    expect(class_exists(PermissionManager::class))->toBeTrue();
    expect(method_exists(PermissionManager::class, 'render'))->toBeTrue();
    expect(method_exists(PermissionManager::class, 'create'))->toBeTrue();
    expect(method_exists(PermissionManager::class, 'edit'))->toBeTrue();
    expect(method_exists(PermissionManager::class, 'delete'))->toBeTrue();
});

it('PermissionForm class exists with required methods', function (): void {
    expect(class_exists(PermissionForm::class))->toBeTrue();
    expect(method_exists(PermissionForm::class, 'save'))->toBeTrue();
    expect(method_exists(PermissionForm::class, 'cancel'))->toBeTrue();
});

it('PermissionManager view exists', function (): void {
    expect(view()->exists('clearance::livewire.permissions.permission-manager'))->toBeTrue();
});

it('PermissionForm view exists', function (): void {
    expect(view()->exists('clearance::livewire.permissions.permission-form'))->toBeTrue();
});

it('PermissionManager has no direct Spatie write calls (V8)', function (): void {
    $source = file_get_contents(
        realpath(__DIR__.'/../../src/Livewire/Permissions/PermissionManager.php')
    );

    expect($source)->not->toContain('Permission::create(');
    expect($source)->not->toContain('givePermissionTo(');
    expect($source)->not->toContain('assignRole(');
    expect($source)->not->toContain('Role::create(');
});

it('PermissionForm has no direct Spatie write calls (V8)', function (): void {
    $source = file_get_contents(
        realpath(__DIR__.'/../../src/Livewire/Permissions/PermissionForm.php')
    );

    expect($source)->not->toContain('Permission::create(');
    expect($source)->not->toContain('givePermissionTo(');
    expect($source)->not->toContain('assignRole(');
});

it('colorForGroup returns consistent color for same group', function (): void {
    $manager = new PermissionManager;

    expect($manager->colorForGroup('orders'))->toBe($manager->colorForGroup('orders'));
    expect($manager->colorForGroup('orders'))->not->toBeEmpty();
});
