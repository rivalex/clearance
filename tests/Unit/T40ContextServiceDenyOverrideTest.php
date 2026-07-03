<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Rivalex\Clearance\Models\UserContextPermissionOverride;
use Rivalex\Clearance\Models\UserRoleContext;
use Rivalex\Clearance\Services\ContextService;
use Rivalex\Clearance\Tests\Support\FakeContext;
use Rivalex\Clearance\Tests\Support\FakeEloquentUser;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

// Adversarial tests for the F1 security fix (rivalex/clearance security audit,
// docs/plans/security-audit/plan.md): a per-context `forced_off` override MUST
// deny access even when the user also holds the permission via a global role.
// Before the fix, resolveFor() computed (contextual ∪ global) and only stripped
// the permission from the contextual set, so a global-role grant silently
// resurrected it - forced_off never actually denied anything.

beforeEach(function (): void {
    $this->runMigrations();

    Schema::create('fake_users', static function ($table): void {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->string('password');
        $table->timestamps();
    });

    $this->service = new ContextService();
});

it('forced_off denies a permission even when granted globally (V16 deny-override)', function (): void {
    $user = FakeEloquentUser::create([
        'name' => 'Global Editor', 'email' => 'global-editor@example.com', 'password' => 'x',
    ]);

    // Global role grants posts-delete everywhere.
    $globalRole = Role::create(['name' => 'editor', 'guard_name' => 'web']);
    $perm       = Permission::create(['name' => 'posts-delete', 'guard_name' => 'web']);
    $globalRole->givePermissionTo($perm);
    $user->assignRole($globalRole);

    // Contextual role in Project X, with an explicit forced_off for posts-delete.
    $contextualRole = Role::create(['name' => 'restricted-in-x', 'guard_name' => 'web']);
    $context        = tap(new FakeContext, fn ($c) => $c->setAttribute('id', 5));

    UserRoleContext::create([
        'user_id'      => $user->id,
        'role_id'      => $contextualRole->id,
        'context_type' => FakeContext::class,
        'context_id'   => 5,
    ]);
    UserContextPermissionOverride::create([
        'user_id'       => $user->id,
        'role_id'       => $contextualRole->id,
        'permission_id' => $perm->id,
        'context_type'  => FakeContext::class,
        'context_id'    => 5,
        'type'          => UserContextPermissionOverride::TYPE_FORCED_OFF,
    ]);

    expect($this->service->canIn($user, 'posts-delete', $context))->toBeFalse();
    expect($this->service->resolveFor($user, $context)->contains('posts-delete'))->toBeFalse();
});

it('a permission granted only globally (no override) is still visible in context', function (): void {
    $user = FakeEloquentUser::create([
        'name' => 'Global Only', 'email' => 'global-only@example.com', 'password' => 'x',
    ]);

    $globalRole = Role::create(['name' => 'auditor', 'guard_name' => 'web']);
    $perm       = Permission::create(['name' => 'reports-view', 'guard_name' => 'web']);
    $globalRole->givePermissionTo($perm);
    $user->assignRole($globalRole);

    $context = tap(new FakeContext, fn ($c) => $c->setAttribute('id', 7));

    expect($this->service->canIn($user, 'reports-view', $context))->toBeTrue();
});

it('forced_off wins over a forced_on for the same permission in the same context', function (): void {
    $user = FakeEloquentUser::create([
        'name' => 'Conflicted User', 'email' => 'conflicted@example.com', 'password' => 'x',
    ]);

    $roleA = Role::create(['name' => 'grants-role', 'guard_name' => 'web']);
    $roleB = Role::create(['name' => 'denies-role', 'guard_name' => 'web']);
    $perm  = Permission::create(['name' => 'billing-refund', 'guard_name' => 'web']);
    $context = tap(new FakeContext, fn ($c) => $c->setAttribute('id', 9));

    UserRoleContext::create([
        'user_id' => $user->id, 'role_id' => $roleA->id,
        'context_type' => FakeContext::class, 'context_id' => 9,
    ]);
    UserRoleContext::create([
        'user_id' => $user->id, 'role_id' => $roleB->id,
        'context_type' => FakeContext::class, 'context_id' => 9,
    ]);
    UserContextPermissionOverride::create([
        'user_id' => $user->id, 'role_id' => $roleA->id, 'permission_id' => $perm->id,
        'context_type' => FakeContext::class, 'context_id' => 9,
        'type' => UserContextPermissionOverride::TYPE_FORCED_ON,
    ]);
    UserContextPermissionOverride::create([
        'user_id' => $user->id, 'role_id' => $roleB->id, 'permission_id' => $perm->id,
        'context_type' => FakeContext::class, 'context_id' => 9,
        'type' => UserContextPermissionOverride::TYPE_FORCED_OFF,
    ]);

    expect($this->service->canIn($user, 'billing-refund', $context))->toBeFalse();
});
