<?php

declare(strict_types=1);

use Rivalex\Clearance\Models\Permission;
use Rivalex\Clearance\Models\UserRoleContext;
use Rivalex\Clearance\Services\ContextService;
use Rivalex\Clearance\Tests\Support\FakeContext;
use Rivalex\Clearance\Tests\Support\FakeUser;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $this->runMigrations();
    $this->service = new ContextService;
    $this->user = new FakeUser(id: 1);

    $this->context = new FakeContext;
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
        'user_id' => 1,
        'role_id' => $role->id,
        'context_type' => FakeContext::class,
        'context_id' => 5,
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

// --- guard filtering ---

it('resolveFor filters roles by guard_name when guard provided', function (): void {
    $webRole = Role::create(['name' => 'staff', 'guard_name' => 'web']);
    $apiRole = Role::create(['name' => 'staff-api', 'guard_name' => 'api']);
    $perm = Permission::create(['name' => 'orders-read', 'guard_name' => 'web']);
    $webRole->givePermissionTo($perm);

    UserRoleContext::create([
        'user_id' => 1, 'role_id' => $webRole->id,
        'context_type' => FakeContext::class, 'context_id' => 5,
    ]);
    UserRoleContext::create([
        'user_id' => 1, 'role_id' => $apiRole->id,
        'context_type' => FakeContext::class, 'context_id' => 5,
    ]);

    expect($this->service->resolveFor($this->user, $this->context, 'web'))->toContain('orders-read');
    expect($this->service->resolveFor($this->user, $this->context, 'api'))->toBeEmpty();
});

it('canIn is alias for hasPermissionIn', function (): void {
    $role = Role::create(['name' => 'staff', 'guard_name' => 'web']);
    $perm = Permission::create(['name' => 'store-manage', 'guard_name' => 'web']);
    $role->givePermissionTo($perm);

    UserRoleContext::create([
        'user_id' => 1, 'role_id' => $role->id,
        'context_type' => FakeContext::class, 'context_id' => 5,
    ]);

    expect($this->service->canIn($this->user, 'store-manage', $this->context))->toBeTrue()
        ->and($this->service->hasPermissionIn($this->user, 'store-manage', $this->context))->toBeTrue();
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
