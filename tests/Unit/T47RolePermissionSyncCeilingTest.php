<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Rivalex\Clearance\Exceptions\ClearanceProtectedResourceException;
use Rivalex\Clearance\Exceptions\ClearanceScopeViolationException;
use Rivalex\Clearance\Services\PermissionService;
use Rivalex\Clearance\Services\RoleService;
use Rivalex\Clearance\Tests\Support\FakeEloquentUser;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

// Adversarial tests for F9 (rivalex/clearance security audit,
// docs/plans/security-audit/plan.md): RoleService::syncPermissions() previously had
// NO actor ceiling - unlike UserClearanceService's per-user direct-permission grant
// (F2, same session), a non-super_admin actor with only `clearance-roles-write` could
// self-escalate by editing the permissions of a role they themselves already hold
// (e.g. adding clearance-users-write to their own role), or add clearance-* to ANY
// role (the old protection only guarded the literal 'super_admin'-named role).

beforeEach(function (): void {
    $this->runMigrations();

    Schema::create('fake_users', static function ($table): void {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->string('password');
        $table->timestamps();
    });

    $this->service = new RoleService(new PermissionService(app('config')));
});

function makeRoleSyncUser(string $email): FakeEloquentUser
{
    return FakeEloquentUser::create(['name' => $email, 'email' => $email, 'password' => 'x']);
}

it('forbids an actor from adding a permission to a role they currently hold themselves', function (): void {
    $actor = makeRoleSyncUser('self-role@example.com');
    $role  = Role::create(['name' => 'delegated-admin', 'guard_name' => 'web']);
    $actor->assignRole($role);

    $newPerm = Permission::create(['name' => 'orders-delete', 'guard_name' => 'web']);

    expect(fn () => $this->service->syncPermissions($actor, $role, [$newPerm]))
        ->toThrow(ClearanceScopeViolationException::class);

    expect($role->fresh()->hasPermissionTo('orders-delete'))->toBeFalse();
});

it('forbids adding clearance-* to a non-super_admin role even by an actor who does not hold that role', function (): void {
    $actor  = makeRoleSyncUser('bystander@example.com');
    $target = Role::create(['name' => 'some-other-role', 'guard_name' => 'web']);
    $clearancePerm = Permission::create(['name' => 'clearance-users-write', 'guard_name' => 'web']);

    expect(fn () => $this->service->syncPermissions($actor, $target, [$clearancePerm]))
        ->toThrow(ClearanceProtectedResourceException::class);

    expect($target->fresh()->hasPermissionTo('clearance-users-write'))->toBeFalse();
});

it('forbids an actor from adding a permission they do not themselves hold to another role', function (): void {
    $actor  = makeRoleSyncUser('other-role-editor@example.com');
    $target = Role::create(['name' => 'target-role', 'guard_name' => 'web']);
    $perm   = Permission::create(['name' => 'orders-delete', 'guard_name' => 'web']);

    expect(fn () => $this->service->syncPermissions($actor, $target, [$perm]))
        ->toThrow(ClearanceScopeViolationException::class);
});

it('allows an actor to add a permission they already hold to another role', function (): void {
    $actor  = makeRoleSyncUser('legit-editor@example.com');
    $target = Role::create(['name' => 'target-role2', 'guard_name' => 'web']);
    $perm   = Permission::create(['name' => 'orders-edit', 'guard_name' => 'web']);
    $actor->givePermissionTo($perm);

    $this->service->syncPermissions($actor, $target, [$perm]);

    expect($target->fresh()->hasPermissionTo('orders-edit'))->toBeTrue();
});

it('allows super_admin to add any permission to any role, including one they hold themselves', function (): void {
    $superAdminRole = Role::create(['name' => 'super_admin', 'guard_name' => 'web']);
    $actor = makeRoleSyncUser('superadmin-role@example.com');
    $actor->assignRole($superAdminRole);

    $otherRole = Role::create(['name' => 'other-role', 'guard_name' => 'web']);
    $perm = Permission::create(['name' => 'orders-delete', 'guard_name' => 'web']);

    $this->service->syncPermissions($actor, $otherRole, [$perm]);

    expect($otherRole->fresh()->hasPermissionTo('orders-delete'))->toBeTrue();
});

it('allows an actor to remove permissions from their own role without holding a ceiling check', function (): void {
    $actor = makeRoleSyncUser('self-downgrade@example.com');
    $role  = Role::create(['name' => 'self-role', 'guard_name' => 'web']);
    $perm  = Permission::create(['name' => 'orders-edit', 'guard_name' => 'web']);
    $role->givePermissionTo($perm);
    $actor->assignRole($role);

    // Removal only reduces privilege - never restricted by the ceiling.
    $this->service->syncPermissions($actor, $role, []);

    expect($role->fresh()->hasPermissionTo('orders-edit'))->toBeFalse();
});
