<?php

declare(strict_types=1);

use Rivalex\Clearance\Clearance;
use Rivalex\Clearance\Facades\Clearance as ClearanceFacade;
use Rivalex\Clearance\Models\Permission;
use Rivalex\Clearance\Models\UserRoleContext;
use Rivalex\Clearance\Tests\Support\FakeContext;
use Rivalex\Clearance\Tests\Support\FakeUser;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $this->runMigrations();
});

// --- container resolution ---

it('Clearance class resolves from container', function (): void {
    expect(app(Clearance::class))->toBeInstanceOf(Clearance::class);
});

it('Clearance facade resolves to Clearance instance', function (): void {
    expect(ClearanceFacade::getFacadeRoot())->toBeInstanceOf(Clearance::class);
});

// --- guards() ---

it('Clearance::guards() returns array of guard names', function (): void {
    $guards = ClearanceFacade::guards();

    expect($guards)->toBeArray()
        ->and($guards)->toContain('web');
});

// --- canIn() ---

it('Clearance::canIn() returns false when user has no context role', function (): void {
    $user = new FakeUser(id: 1);
    $context = tap(new FakeContext, fn ($c) => $c->setAttribute('id', 1));

    expect(ClearanceFacade::canIn($user, 'orders-read', $context))->toBeFalse();
});

it('Clearance::canIn() returns true when user has contextual permission', function (): void {
    $role = Role::create(['name' => 'staff', 'guard_name' => 'web']);
    $perm = Permission::create(['name' => 'orders-read', 'guard_name' => 'web']);
    $role->givePermissionTo($perm);

    UserRoleContext::create([
        'user_id' => 1, 'role_id' => $role->id,
        'context_type' => FakeContext::class, 'context_id' => 1,
    ]);

    $user = new FakeUser(id: 1);
    $context = tap(new FakeContext, fn ($c) => $c->setAttribute('id', 1));

    expect(ClearanceFacade::canIn($user, 'orders-read', $context))->toBeTrue();
});

it('Clearance::canIn() respects guard filter', function (): void {
    $role = Role::create(['name' => 'staff', 'guard_name' => 'web']);
    $perm = Permission::create(['name' => 'orders-read', 'guard_name' => 'web']);
    $role->givePermissionTo($perm);

    UserRoleContext::create([
        'user_id' => 1, 'role_id' => $role->id,
        'context_type' => FakeContext::class, 'context_id' => 1,
    ]);

    $user = new FakeUser(id: 1);
    $context = tap(new FakeContext, fn ($c) => $c->setAttribute('id', 1));

    expect(ClearanceFacade::canIn($user, 'orders-read', $context, 'web'))->toBeTrue()
        ->and(ClearanceFacade::canIn($user, 'orders-read', $context, 'api'))->toBeFalse();
});

// --- resolveFor() ---

it('Clearance::resolveFor() returns effective permission collection', function (): void {
    $role = Role::create(['name' => 'staff', 'guard_name' => 'web']);
    $perm = Permission::create(['name' => 'store-manage', 'guard_name' => 'web']);
    $role->givePermissionTo($perm);

    UserRoleContext::create([
        'user_id' => 1, 'role_id' => $role->id,
        'context_type' => FakeContext::class, 'context_id' => 1,
    ]);

    $user = new FakeUser(id: 1);
    $context = tap(new FakeContext, fn ($c) => $c->setAttribute('id', 1));

    expect(ClearanceFacade::resolveFor($user, $context))->toContain('store-manage');
});

it('Clearance::resolveFor() returns empty collection when no role in context', function (): void {
    $user = new FakeUser(id: 1);
    $context = tap(new FakeContext, fn ($c) => $c->setAttribute('id', 1));

    expect(ClearanceFacade::resolveFor($user, $context))->toBeEmpty();
});
