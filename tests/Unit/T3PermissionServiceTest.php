<?php

declare(strict_types=1);

use Rivalex\Clearance\Exceptions\ClearanceNamingException;
use Rivalex\Clearance\Services\PermissionService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $this->runMigrations();
    $this->service = new PermissionService(app('config'));
});

// --- naming validation (V6) ---

it('accepts valid gruppo-azione names', function (string $name): void {
    expect(fn () => $this->service->validate($name))->not->toThrow(ClearanceNamingException::class);
})->with([
    'orders-create',
    'orders-read',
    'magazzino-update',
    'clearance-access',
    'store-orders-delete',
]);

it('rejects bare action without group', function (): void {
    expect(fn () => $this->service->validate('create'))->toThrow(ClearanceNamingException::class);
});

it('rejects dot separator', function (): void {
    expect(fn () => $this->service->validate('orders.create'))->toThrow(ClearanceNamingException::class);
});

it('rejects spaces', function (): void {
    expect(fn () => $this->service->validate('orders create'))->toThrow(ClearanceNamingException::class);
});

it('rejects camelCase', function (): void {
    expect(fn () => $this->service->validate('OrdersCreate'))->toThrow(ClearanceNamingException::class);
});

it('rejects mixed case', function (): void {
    expect(fn () => $this->service->validate('orders-Create'))->toThrow(ClearanceNamingException::class);
});

it('bypasses validation when enforce_naming_convention is false', function (): void {
    config()->set('clearance.enforce_naming_convention', false);

    expect(fn () => $this->service->validate('ANY.thing goes'))->not->toThrow(ClearanceNamingException::class);
});

// --- CRUD ---

it('creates a permission with valid name', function (): void {
    $permission = $this->service->create('orders-create', 'web');

    expect($permission)->toBeInstanceOf(Permission::class)
        ->and($permission->name)->toBe('orders-create')
        ->and($permission->guard_name)->toBe('web');
});

it('throws when creating permission with invalid name', function (): void {
    expect(fn () => $this->service->create('OrdersCreate', 'web'))
        ->toThrow(ClearanceNamingException::class);
});

it('renames a permission', function (): void {
    $permission = $this->service->create('orders-create', 'web');
    $renamed = $this->service->rename($permission, 'orders-store');

    expect($renamed->name)->toBe('orders-store');
});

it('deletes a permission', function (): void {
    $permission = $this->service->create('orders-create', 'web');
    $id = $permission->id;

    $this->service->delete($permission);

    expect(Permission::find($id))->toBeNull();
});

it('groupFor extracts group prefix', function (): void {
    expect($this->service->groupFor('orders-create'))->toBe('orders')
        ->and($this->service->groupFor('magazzino-update'))->toBe('magazzino');
});

// --- role assignment (V8 — single write path) ---

it('assigns and revokes permission via service', function (): void {
    $permission = $this->service->create('orders-read', 'web');
    $role = Role::create(['name' => 'manager', 'guard_name' => 'web']);

    $this->service->assignToRole($role, $permission);
    expect($role->fresh()->hasPermissionTo($permission))->toBeTrue();

    $this->service->revokeFromRole($role, $permission);
    expect($role->fresh()->hasPermissionTo($permission))->toBeFalse();
});
