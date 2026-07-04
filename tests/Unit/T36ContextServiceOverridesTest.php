<?php

declare(strict_types=1);

use Rivalex\Clearance\Models\UserContextPermissionOverride;
use Rivalex\Clearance\Models\UserRoleContext;
use Rivalex\Clearance\Services\ContextService;
use Rivalex\Clearance\Tests\Support\FakeContext;
use Rivalex\Clearance\Tests\Support\FakeUser;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $this->runMigrations();
    $this->service = new ContextService;
});

// T36.1: resolveFor returns role permissions
it('resolveFor returns role default permissions', function (): void {
    $user = new FakeUser(id: 1);
    $role = Role::create(['name' => 'viewer', 'guard_name' => 'web']);
    $perm = Permission::create(['name' => 'posts-view', 'guard_name' => 'web']);
    $role->givePermissionTo($perm);

    $context = tap(new FakeContext, fn ($c) => $c->setAttribute('id', 5));
    UserRoleContext::create([
        'user_id' => 1,
        'role_id' => $role->id,
        'context_type' => FakeContext::class,
        'context_id' => 5,
    ]);

    $effective = $this->service->resolveFor($user, $context);

    expect($effective->contains('posts-view'))->toBeTrue();
});

// T36.2: forced_on user-context override adds permission not in role
it('resolveFor merges forced_on UserContextPermissionOverride', function (): void {
    $user = new FakeUser(id: 2);
    $role = Role::create(['name' => 'editor', 'guard_name' => 'web']);
    $extra = Permission::create(['name' => 'posts-delete', 'guard_name' => 'web']);

    $context = tap(new FakeContext, fn ($c) => $c->setAttribute('id', 5));
    UserRoleContext::create([
        'user_id' => 2,
        'role_id' => $role->id,
        'context_type' => FakeContext::class,
        'context_id' => 5,
    ]);
    UserContextPermissionOverride::create([
        'user_id' => 2,
        'role_id' => $role->id,
        'permission_id' => $extra->id,
        'context_type' => FakeContext::class,
        'context_id' => 5,
        'type' => UserContextPermissionOverride::TYPE_FORCED_ON,
    ]);

    $effective = $this->service->resolveFor($user, $context);

    expect($effective->contains('posts-delete'))->toBeTrue();
});

// T36.3: forced_off user-context override removes permission from role
it('resolveFor removes permissions via forced_off UserContextPermissionOverride', function (): void {
    $user = new FakeUser(id: 3);
    $role = Role::create(['name' => 'editor2', 'guard_name' => 'web']);
    $perm = Permission::create(['name' => 'posts-publish', 'guard_name' => 'web']);
    $role->givePermissionTo($perm);

    $context = tap(new FakeContext, fn ($c) => $c->setAttribute('id', 5));
    UserRoleContext::create([
        'user_id' => 3,
        'role_id' => $role->id,
        'context_type' => FakeContext::class,
        'context_id' => 5,
    ]);
    UserContextPermissionOverride::create([
        'user_id' => 3,
        'role_id' => $role->id,
        'permission_id' => $perm->id,
        'context_type' => FakeContext::class,
        'context_id' => 5,
        'type' => UserContextPermissionOverride::TYPE_FORCED_OFF,
    ]);

    $effective = $this->service->resolveFor($user, $context);

    expect($effective->contains('posts-publish'))->toBeFalse();
});

// T36.4: UserContextPermissionOverride for different user does NOT affect other user
it('resolveFor does not leak overrides between users', function (): void {
    $user1 = new FakeUser(id: 4);
    $user2 = new FakeUser(id: 5);
    $role = Role::create(['name' => 'staff', 'guard_name' => 'web']);
    $perm = Permission::create(['name' => 'stores-view', 'guard_name' => 'web']);

    $context = tap(new FakeContext, fn ($c) => $c->setAttribute('id', 5));

    // user1 gets context role (no permissions on the role itself)
    UserRoleContext::create([
        'user_id' => 4,
        'role_id' => $role->id,
        'context_type' => FakeContext::class,
        'context_id' => 5,
    ]);
    // user2 gets a forced_on override for that permission
    UserContextPermissionOverride::create([
        'user_id' => 5,
        'role_id' => $role->id,
        'permission_id' => $perm->id,
        'context_type' => FakeContext::class,
        'context_id' => 5,
        'type' => UserContextPermissionOverride::TYPE_FORCED_ON,
    ]);

    $effective = $this->service->resolveFor($user1, $context);

    expect($effective->contains('stores-view'))->toBeFalse();
});
