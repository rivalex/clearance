<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;
use Rivalex\Clearance\Models\Permission;
use Rivalex\Clearance\Models\UserRoleContext;
use Rivalex\Clearance\Services\ContextService;
use Rivalex\Clearance\Tests\Support\FakeContext;
use Rivalex\Clearance\Tests\Support\FakeUser;
use Rivalex\Clearance\Traits\HasClearance;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $this->runMigrations();

    $this->user = new class(id: 1) extends FakeUser {
        use HasClearance;
    };

    $this->context = new FakeContext;
    $this->context->setAttribute('id', 5);
});

// --- HasClearance::canIn ---

it('HasClearance::canIn returns false when user has no context role', function (): void {
    expect($this->user->canIn('orders-read', $this->context))->toBeFalse();
});

it('HasClearance::canIn returns true when user has permission in context', function (): void {
    $role = Role::create(['name' => 'staff', 'guard_name' => 'web']);
    $perm = Permission::create(['name' => 'orders-read', 'guard_name' => 'web']);
    $role->givePermissionTo($perm);
    UserRoleContext::create([
        'user_id'      => 1,
        'role_id'      => $role->id,
        'context_type' => FakeContext::class,
        'context_id'   => 5,
    ]);

    expect($this->user->canIn('orders-read', $this->context))->toBeTrue();
});

// --- HasClearance::hasPermissionIn ---

it('HasClearance::hasPermissionIn is alias of canIn', function (): void {
    $role = Role::create(['name' => 'staff', 'guard_name' => 'web']);
    $perm = Permission::create(['name' => 'orders-read', 'guard_name' => 'web']);
    $role->givePermissionTo($perm);
    UserRoleContext::create([
        'user_id'      => 1,
        'role_id'      => $role->id,
        'context_type' => FakeContext::class,
        'context_id'   => 5,
    ]);

    expect($this->user->hasPermissionIn('orders-read', $this->context))->toBeTrue();
    expect($this->user->hasPermissionIn('orders-delete', $this->context))->toBeFalse();
});

// --- ContextService::hasRoleIn ---

it('ContextService::hasRoleIn returns false when user has no role in context', function (): void {
    $service = new ContextService;

    expect($service->hasRoleIn($this->user, 'staff', $this->context))->toBeFalse();
});

it('ContextService::hasRoleIn returns true when user has role in context', function (): void {
    $role = Role::create(['name' => 'staff', 'guard_name' => 'web']);
    UserRoleContext::create([
        'user_id'      => 1,
        'role_id'      => $role->id,
        'context_type' => FakeContext::class,
        'context_id'   => 5,
    ]);

    $service = new ContextService;
    expect($service->hasRoleIn($this->user, 'staff', $this->context))->toBeTrue();
});

it('ContextService::hasRoleIn filters by guard_name when provided', function (): void {
    $role = Role::create(['name' => 'staff', 'guard_name' => 'web']);
    UserRoleContext::create([
        'user_id'      => 1,
        'role_id'      => $role->id,
        'context_type' => FakeContext::class,
        'context_id'   => 5,
    ]);

    $service = new ContextService;
    expect($service->hasRoleIn($this->user, 'staff', $this->context, 'web'))->toBeTrue();
    expect($service->hasRoleIn($this->user, 'staff', $this->context, 'api'))->toBeFalse();
});

it('ContextService::hasRoleIn returns false for wrong role name', function (): void {
    $role = Role::create(['name' => 'staff', 'guard_name' => 'web']);
    UserRoleContext::create([
        'user_id'      => 1,
        'role_id'      => $role->id,
        'context_type' => FakeContext::class,
        'context_id'   => 5,
    ]);

    $service = new ContextService;
    expect($service->hasRoleIn($this->user, 'admin', $this->context))->toBeFalse();
});

// --- HasClearance::hasRoleIn ---

it('HasClearance::hasRoleIn returns true when user has role in context', function (): void {
    $role = Role::create(['name' => 'manager', 'guard_name' => 'web']);
    UserRoleContext::create([
        'user_id'      => 1,
        'role_id'      => $role->id,
        'context_type' => FakeContext::class,
        'context_id'   => 5,
    ]);

    expect($this->user->hasRoleIn('manager', $this->context))->toBeTrue();
});

it('HasClearance::hasRoleIn returns false for wrong role name', function (): void {
    $role = Role::create(['name' => 'manager', 'guard_name' => 'web']);
    UserRoleContext::create([
        'user_id'      => 1,
        'role_id'      => $role->id,
        'context_type' => FakeContext::class,
        'context_id'   => 5,
    ]);

    expect($this->user->hasRoleIn('admin', $this->context))->toBeFalse();
});

// --- @hasrolein directive ---

it('@hasrolein compiles to ContextService::hasRoleIn with auth user', function (): void {
    $compiled = Blade::compileString('@hasrolein($role, $model)');

    expect($compiled)->toContain('hasRoleIn');
    expect($compiled)->toContain('auth()->user()');
    expect($compiled)->toContain('$role, $model');
});

it('@hasrolein compiled output uses app() not global state', function (): void {
    $compiled = Blade::compileString('@hasrolein($role, $model)');

    expect($compiled)->toContain('app(');
    expect($compiled)->toContain('ContextService');
});

it('@endhasrolein compiles to endif', function (): void {
    $compiled = Blade::compileString('@endhasrolein');

    expect($compiled)->toContain('endif');
});
