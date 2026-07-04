<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Rivalex\Clearance\Models\Permission;
use Rivalex\Clearance\Services\PermissionService;
use Rivalex\Clearance\Services\RoleService;
use Rivalex\Clearance\Tests\Support\FakeEloquentUser;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $this->runMigrations();
    $this->service = new RoleService(new PermissionService(app('config')));

    // super_admin actor bypasses the F9 role-sync ceiling, so these tests exercise
    // syncPermissions()'s own behavior (guard checks, add/remove, cascade) in isolation.
    Schema::create('fake_users', static function ($table): void {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->string('password');
        $table->timestamps();
    });
    $this->actor = FakeEloquentUser::create(['name' => 'Actor', 'email' => 't4-actor@example.com', 'password' => 'x']);
    $this->actor->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));
});

it('creates a role', function (): void {
    $role = $this->service->create('manager', 'web');

    expect($role)->toBeInstanceOf(Role::class)
        ->and($role->name)->toBe('manager')
        ->and($role->guard_name)->toBe('web');
});

it('renames a role', function (): void {
    $role = $this->service->create('manager', 'web');
    $renamed = $this->service->rename($role, 'admin');

    expect($renamed->name)->toBe('admin');
});

it('deletes a role', function (): void {
    $role = $this->service->create('manager', 'web');
    $id = $role->id;

    $this->service->delete($role);

    expect(Role::find($id))->toBeNull();
});

it('syncs permissions to a role via PermissionService (V8)', function (): void {
    $role = $this->service->create('manager', 'web');
    $read = Permission::create(['name' => 'orders-read',   'guard_name' => 'web']);
    $write = Permission::create(['name' => 'orders-create', 'guard_name' => 'web']);

    $this->service->syncPermissions($this->actor, $role, [$read, $write]);

    $fresh = $role->fresh();
    expect($fresh->hasPermissionTo($read))->toBeTrue()
        ->and($fresh->hasPermissionTo($write))->toBeTrue();
});

it('sync removes revoked permissions (V8)', function (): void {
    $role = $this->service->create('manager', 'web');
    $read = Permission::create(['name' => 'orders-read',   'guard_name' => 'web']);
    $write = Permission::create(['name' => 'orders-create', 'guard_name' => 'web']);

    $this->service->syncPermissions($this->actor, $role, [$read, $write]);
    $this->service->syncPermissions($this->actor, $role, [$read]); // remove write

    $fresh = $role->fresh();
    expect($fresh->hasPermissionTo($read))->toBeTrue()
        ->and($fresh->hasPermissionTo($write))->toBeFalse();
});

it('rejects permission from different guard (guard-scoped enforcement)', function (): void {
    $role = $this->service->create('manager', 'web');
    $apiPerm = Permission::create(['name' => 'orders-read', 'guard_name' => 'api']);

    expect(fn () => $this->service->syncPermissions($this->actor, $role, [$apiPerm]))
        ->toThrow(InvalidArgumentException::class);
});
