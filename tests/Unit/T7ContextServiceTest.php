<?php

declare(strict_types=1);

use Rivalex\Clearance\Models\RolePermissionOverride;
use Rivalex\Clearance\Models\UserRoleContext;
use Rivalex\Clearance\Services\ContextService;
use Rivalex\Clearance\Tests\Support\FakeContext;
use Rivalex\Clearance\Tests\Support\FakeUser;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $this->runMigrations();
    $this->service = new ContextService();
    $this->user    = new FakeUser(id: 1);

    $this->context = new FakeContext();
    $this->context->setAttribute('id', 5);
});

it('returns empty collection when user has no context role', function (): void {
    expect($this->service->resolveFor($this->user, $this->context))->toBeEmpty();
});

it('returns permissions for user role in context', function (): void {
    $role = Role::create(['name' => 'staff', 'guard_name' => 'web']);
    $perm = Permission::create(['name' => 'orders-read', 'guard_name' => 'web']);
    $role->givePermissionTo($perm);

    UserRoleContext::create([
        'user_id'      => 1,
        'role_id'      => $role->id,
        'context_type' => FakeContext::class,
        'context_id'   => 5,
    ]);

    expect($this->service->resolveFor($this->user, $this->context)->contains('orders-read'))->toBeTrue();
});

it('does not return permissions from different context_id (V4)', function (): void {
    $role = Role::create(['name' => 'staff', 'guard_name' => 'web']);
    $perm = Permission::create(['name' => 'orders-read', 'guard_name' => 'web']);
    $role->givePermissionTo($perm);

    UserRoleContext::create([
        'user_id' => 1, 'role_id' => $role->id,
        'context_type' => FakeContext::class, 'context_id' => 99,
    ]);

    expect($this->service->resolveFor($this->user, $this->context))->toBeEmpty();
});

it('does not return permissions from different user_id (V4)', function (): void {
    $role = Role::create(['name' => 'staff', 'guard_name' => 'web']);
    $perm = Permission::create(['name' => 'orders-read', 'guard_name' => 'web']);
    $role->givePermissionTo($perm);

    UserRoleContext::create([
        'user_id' => 999, 'role_id' => $role->id,
        'context_type' => FakeContext::class, 'context_id' => 5,
    ]);

    expect($this->service->resolveFor($this->user, $this->context))->toBeEmpty();
});

it('applies forced_on override to effective permissions', function (): void {
    $parent = Role::create(['name' => 'manager', 'guard_name' => 'web']);
    $child  = Role::create(['name' => 'staff',   'guard_name' => 'web']);
    $perm   = Permission::create(['name' => 'orders-update', 'guard_name' => 'web']);
    $parent->givePermissionTo($perm);

    RolePermissionOverride::create([
        'parent_role_id' => $parent->id,
        'child_role_id'  => $child->id,
        'permission_id'  => $perm->id,
        'type'           => RolePermissionOverride::TYPE_FORCED_ON,
    ]);

    UserRoleContext::create([
        'user_id' => 1, 'role_id' => $child->id,
        'context_type' => FakeContext::class, 'context_id' => 5,
    ]);

    expect($this->service->resolveFor($this->user, $this->context)->contains('orders-update'))->toBeTrue();
});

it('applies forced_off override to remove permission', function (): void {
    $parent = Role::create(['name' => 'manager', 'guard_name' => 'web']);
    $child  = Role::create(['name' => 'staff',   'guard_name' => 'web']);
    $perm   = Permission::create(['name' => 'orders-delete', 'guard_name' => 'web']);
    $child->givePermissionTo($perm);

    RolePermissionOverride::create([
        'parent_role_id' => $parent->id,
        'child_role_id'  => $child->id,
        'permission_id'  => $perm->id,
        'type'           => RolePermissionOverride::TYPE_FORCED_OFF,
    ]);

    UserRoleContext::create([
        'user_id' => 1, 'role_id' => $child->id,
        'context_type' => FakeContext::class, 'context_id' => 5,
    ]);

    expect($this->service->resolveFor($this->user, $this->context)->contains('orders-delete'))->toBeFalse();
});

it('hasPermissionIn returns true when user has permission in context', function (): void {
    $role = Role::create(['name' => 'staff', 'guard_name' => 'web']);
    $perm = Permission::create(['name' => 'store-manage', 'guard_name' => 'web']);
    $role->givePermissionTo($perm);

    UserRoleContext::create([
        'user_id' => 1, 'role_id' => $role->id,
        'context_type' => FakeContext::class, 'context_id' => 5,
    ]);

    expect($this->service->hasPermissionIn($this->user, 'store-manage', $this->context))->toBeTrue();
});

it('hasPermissionIn returns false when permission not in context', function (): void {
    expect($this->service->hasPermissionIn($this->user, 'store-manage', $this->context))->toBeFalse();
});
