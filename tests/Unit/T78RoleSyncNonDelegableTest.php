<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Rivalex\Clearance\Exceptions\ClearanceProtectedResourceException;
use Rivalex\Clearance\Models\UserRoleContext;
use Rivalex\Clearance\Services\RoleService;
use Rivalex\Clearance\Tests\Support\FakeEloquentUser;
use Rivalex\Clearance\Tests\Support\FakeHierarchicalContext;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

// RoleService::syncPermissions() refuses to add a non-delegable permission to a
// role that has holders (global or contextual), for every actor, super_admin included.

beforeEach(function (): void {
    $this->runMigrations();

    Schema::create('fake_hierarchical_contexts', static function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('parent_id')->nullable();
    });

    Schema::create('fake_users', static function ($table): void {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->string('password');
        $table->timestamps();
    });

    config()->set('clearance.user_model', FakeEloquentUser::class);
    config()->set('clearance.non_delegable', ['billing-*']);

    $this->roles = app(RoleService::class);
    $this->admin = t78User('admin@example.com');
    $this->admin->assignRole(Role::create(['name' => 'super_admin', 'guard_name' => 'web']));
});

function t78User(string $email): FakeEloquentUser
{
    return FakeEloquentUser::create(['name' => $email, 'email' => $email, 'password' => 'x']);
}

it('refuses a non-delegable permission on a role with a global holder, super_admin included', function (): void {
    $role = Role::create(['name' => 'owner', 'guard_name' => 'web']);
    $holder = t78User('holder@example.com');
    $holder->assignRole($role);
    $perm = Permission::findOrCreate('billing-update', 'web');

    expect(fn () => $this->roles->syncPermissions($this->admin, $role, [$perm]))
        ->toThrow(ClearanceProtectedResourceException::class, 'Permission [billing-update] is non-delegable and cannot be added to role [owner] while users hold it.');

    expect($role->fresh()->hasPermissionTo('billing-update'))->toBeFalse()
        ->and($holder->fresh()->hasPermissionTo('billing-update'))->toBeFalse();
});

it('refuses a non-delegable permission on a role held only through a contextual binding', function (): void {
    $role = Role::create(['name' => 'owner', 'guard_name' => 'web']);
    $holder = t78User('holder@example.com');
    $company = FakeHierarchicalContext::create(['parent_id' => null]);
    UserRoleContext::create([
        'user_id' => $holder->id,
        'role_id' => $role->id,
        'context_type' => FakeHierarchicalContext::class,
        'context_id' => $company->id,
    ]);
    $perm = Permission::findOrCreate('billing-update', 'web');

    expect(fn () => $this->roles->syncPermissions($this->admin, $role, [$perm]))
        ->toThrow(ClearanceProtectedResourceException::class);

    expect($role->fresh()->hasPermissionTo('billing-update'))->toBeFalse();
});

it('lets super_admin add a non-delegable permission to a role with no holders', function (): void {
    $role = Role::create(['name' => 'owner', 'guard_name' => 'web']);
    $perm = Permission::findOrCreate('billing-update', 'web');

    $this->roles->syncPermissions($this->admin, $role, [$perm]);

    expect($role->fresh()->hasPermissionTo('billing-update'))->toBeTrue();
});

it('lets a non-delegable permission be removed from a role with holders', function (): void {
    $role = Role::create(['name' => 'owner', 'guard_name' => 'web']);
    $perm = Permission::findOrCreate('billing-update', 'web');
    $role->givePermissionTo($perm);
    t78User('holder@example.com')->assignRole($role);

    $this->roles->syncPermissions($this->admin, $role, []);

    expect($role->fresh()->hasPermissionTo('billing-update'))->toBeFalse();
});

it('keeps adding a delegable permission to a role with holders under the ceiling rules only', function (): void {
    $role = Role::create(['name' => 'staff', 'guard_name' => 'web']);
    $holder = t78User('holder@example.com');
    $holder->assignRole($role);
    $perm = Permission::findOrCreate('orders-read', 'web');

    $this->roles->syncPermissions($this->admin, $role, [$perm]);

    expect($role->fresh()->hasPermissionTo('orders-read'))->toBeTrue()
        ->and($holder->fresh()->hasPermissionTo('orders-read'))->toBeTrue();
});
