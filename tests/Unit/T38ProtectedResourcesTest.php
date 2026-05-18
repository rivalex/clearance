<?php

declare(strict_types=1);

use Rivalex\Clearance\Exceptions\ClearanceProtectedResourceException;
use Rivalex\Clearance\Models\Permission;
use Rivalex\Clearance\Services\PermissionService;
use Rivalex\Clearance\Services\RoleService;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $this->runMigrations();
    $this->roleService = new RoleService(new PermissionService(app('config')));
    $this->permService = new PermissionService(app('config'));

    $this->superAdmin    = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    $this->clearancePerm = Permission::firstOrCreate(['name' => 'clearance-access', 'guard_name' => 'web']);
    $this->superAdmin->syncPermissions([$this->clearancePerm]);
});

// --- super_admin role protection ---

it('blocks renaming the super_admin role', function (): void {
    expect(fn () => $this->roleService->rename($this->superAdmin, 'god'))
        ->toThrow(ClearanceProtectedResourceException::class);

    expect(Role::where('name', 'super_admin')->exists())->toBeTrue();
});

it('blocks deleting the super_admin role', function (): void {
    expect(fn () => $this->roleService->delete($this->superAdmin))
        ->toThrow(ClearanceProtectedResourceException::class);

    expect(Role::where('name', 'super_admin')->exists())->toBeTrue();
});

it('blocks removing a clearance-* permission from super_admin via syncPermissions', function (): void {
    $other = Permission::firstOrCreate(['name' => 'orders-read', 'guard_name' => 'web']);

    expect(fn () => $this->roleService->syncPermissions($this->superAdmin, [$other]))
        ->toThrow(ClearanceProtectedResourceException::class);

    expect($this->superAdmin->fresh()->hasPermissionTo('clearance-access'))->toBeTrue();
});

it('blocks adding a new clearance-* permission to super_admin via syncPermissions', function (): void {
    $extra = Permission::firstOrCreate(['name' => 'clearance-users-write', 'guard_name' => 'web']);

    expect(fn () => $this->roleService->syncPermissions($this->superAdmin, [$this->clearancePerm, $extra]))
        ->toThrow(ClearanceProtectedResourceException::class);
});

it('allows adding non-clearance permissions to super_admin', function (): void {
    $orders = Permission::firstOrCreate(['name' => 'orders-read', 'guard_name' => 'web']);

    $this->roleService->syncPermissions($this->superAdmin, [$this->clearancePerm, $orders]);

    expect($this->superAdmin->fresh()->hasPermissionTo('orders-read'))->toBeTrue();
    expect($this->superAdmin->fresh()->hasPermissionTo('clearance-access'))->toBeTrue();
});

it('allows removing non-clearance permissions from super_admin', function (): void {
    $orders = Permission::firstOrCreate(['name' => 'orders-read', 'guard_name' => 'web']);
    $this->superAdmin->givePermissionTo($orders);

    $this->roleService->syncPermissions($this->superAdmin, [$this->clearancePerm]);

    expect($this->superAdmin->fresh()->hasPermissionTo('orders-read'))->toBeFalse();
    expect($this->superAdmin->fresh()->hasPermissionTo('clearance-access'))->toBeTrue();
});

// --- clearance-* permission protection ---

it('blocks renaming a clearance-* permission', function (): void {
    expect(fn () => $this->permService->rename($this->clearancePerm, 'panel-access'))
        ->toThrow(ClearanceProtectedResourceException::class);

    expect(Permission::where('name', 'clearance-access')->exists())->toBeTrue();
});

it('blocks deleting a clearance-* permission', function (): void {
    expect(fn () => $this->permService->delete($this->clearancePerm))
        ->toThrow(ClearanceProtectedResourceException::class);

    expect(Permission::where('name', 'clearance-access')->exists())->toBeTrue();
});

it('allows renaming a non-clearance permission', function (): void {
    $perm = Permission::firstOrCreate(['name' => 'orders-read', 'guard_name' => 'web']);

    $renamed = $this->permService->rename($perm, 'orders-list');

    expect($renamed->name)->toBe('orders-list');
});

it('allows deleting a non-clearance permission', function (): void {
    $perm = Permission::firstOrCreate(['name' => 'orders-read', 'guard_name' => 'web']);
    $id   = $perm->id;

    $this->permService->delete($perm);

    expect(Permission::find($id))->toBeNull();
});
