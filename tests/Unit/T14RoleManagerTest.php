<?php

declare(strict_types=1);

use Rivalex\Clearance\Livewire\Roles\RoleForm;
use Rivalex\Clearance\Livewire\Roles\RoleManager;

it('RoleManager class exists with required methods', function (): void {
    expect(class_exists(RoleManager::class))->toBeTrue();
    expect(method_exists(RoleManager::class, 'render'))->toBeTrue();
    expect(method_exists(RoleManager::class, 'create'))->toBeTrue();
    expect(method_exists(RoleManager::class, 'edit'))->toBeTrue();
    expect(method_exists(RoleManager::class, 'delete'))->toBeTrue();
});

it('RoleForm class exists with required methods', function (): void {
    expect(class_exists(RoleForm::class))->toBeTrue();
    expect(method_exists(RoleForm::class, 'save'))->toBeTrue();
    expect(method_exists(RoleForm::class, 'cancel'))->toBeTrue();
});

it('RoleManager view exists', function (): void {
    expect(view()->exists('clearance::livewire.roles.role-manager'))->toBeTrue();
});

it('RoleForm view exists', function (): void {
    expect(view()->exists('clearance::livewire.roles.role-form'))->toBeTrue();
});

it('RoleManager has no direct Spatie write calls (V8)', function (): void {
    $source = file_get_contents(
        realpath(__DIR__.'/../../src/Livewire/Roles/RoleManager.php')
    );

    expect($source)->not->toContain('Role::create(');
    expect($source)->not->toContain('Permission::create(');
    expect($source)->not->toContain('givePermissionTo(');
    expect($source)->not->toContain('syncPermissions(');
});

it('RoleForm routes writes through RoleService (V8)', function (): void {
    $source = file_get_contents(
        realpath(__DIR__.'/../../src/Livewire/Roles/RoleForm.php')
    );

    expect($source)->toContain('RoleService');
    expect($source)->not->toContain('Role::create(');
    expect($source)->not->toContain('givePermissionTo(');
});

it('RoleForm save() handles is_system and is_protected badges via RoleMeta', function (): void {
    $source = file_get_contents(
        realpath(__DIR__.'/../../src/Livewire/Roles/RoleForm.php')
    );

    expect($source)->toContain('RoleMeta');
    expect($source)->toContain('is_system');
    expect($source)->toContain('is_protected');
});
