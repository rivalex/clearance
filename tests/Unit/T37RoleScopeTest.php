<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Rivalex\Clearance\Exceptions\ClearanceScopeViolationException;
use Rivalex\Clearance\Models\RoleMeta;
use Rivalex\Clearance\Models\UserRoleContext;
use Rivalex\Clearance\Services\ContextService;
use Rivalex\Clearance\Services\UserClearanceService;
use Rivalex\Clearance\Tests\Support\FakeContext;
use Rivalex\Clearance\Tests\Support\FakeEloquentUser;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $this->runMigrations();

    Schema::create('fake_users', static function ($table): void {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->string('password');
        $table->timestamps();
    });

    Schema::create('fake_contexts', static function ($table): void {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });

    $this->service        = new UserClearanceService();
    $this->contextService = new ContextService();
});

// --- RoleMeta helpers ---

it('RoleMeta::isGlobal returns true for global scope', function (): void {
    $meta = new RoleMeta(['scope' => RoleMeta::SCOPE_GLOBAL]);

    expect($meta->isGlobal())->toBeTrue();
    expect($meta->isContextual())->toBeFalse();
});

it('RoleMeta::isContextual returns true for contextual scope', function (): void {
    $meta = new RoleMeta(['scope' => RoleMeta::SCOPE_CONTEXTUAL]);

    expect($meta->isContextual())->toBeTrue();
    expect($meta->isGlobal())->toBeFalse();
});

it('RoleMeta::acceptsContext returns true when FQCN is in context_types', function (): void {
    $meta = new RoleMeta([
        'scope'         => RoleMeta::SCOPE_CONTEXTUAL,
        'context_types' => [FakeContext::class],
    ]);

    expect($meta->acceptsContext(FakeContext::class))->toBeTrue();
});

it('RoleMeta::acceptsContext returns false when FQCN is not in context_types', function (): void {
    $meta = new RoleMeta([
        'scope'         => RoleMeta::SCOPE_CONTEXTUAL,
        'context_types' => ['App\\Models\\Store'],
    ]);

    expect($meta->acceptsContext(FakeContext::class))->toBeFalse();
});

it('RoleMeta::acceptsContext returns false when scope is global', function (): void {
    $meta = new RoleMeta([
        'scope'         => RoleMeta::SCOPE_GLOBAL,
        'context_types' => [FakeContext::class],
    ]);

    expect($meta->acceptsContext(FakeContext::class))->toBeFalse();
});

// --- V12: assignGlobalRole rejects contextual role ---

it('assignGlobalRole throws ClearanceScopeViolationException for contextual role (V12)', function (): void {
    $user = FakeEloquentUser::create(['name' => 'U1', 'email' => 'u1@x.com', 'password' => 'x']);
    $role = Role::create(['name' => 'store-manager', 'guard_name' => 'web']);

    RoleMeta::create([
        'role_id'       => $role->id,
        'scope'         => RoleMeta::SCOPE_CONTEXTUAL,
        'context_types' => [FakeContext::class],
    ]);

    expect(fn () => $this->service->assignGlobalRole($user, $role))
        ->toThrow(ClearanceScopeViolationException::class);
});

// --- V13a: assignContextual rejects global role ---

it('assignContextual throws ClearanceScopeViolationException for global role (V13)', function (): void {
    $user = FakeEloquentUser::create(['name' => 'U2', 'email' => 'u2@x.com', 'password' => 'x']);
    $role = Role::create(['name' => 'admin', 'guard_name' => 'web']);
    $ctx  = FakeContext::create(['name' => 'Ctx A']);

    RoleMeta::create(['role_id' => $role->id, 'scope' => RoleMeta::SCOPE_GLOBAL]);

    expect(fn () => $this->service->assignContextual($user, $role, $ctx))
        ->toThrow(ClearanceScopeViolationException::class);
});

// --- V13b: assignContextual rejects wrong context type ---

it('assignContextual throws ClearanceScopeViolationException for wrong context type (V13)', function (): void {
    $user = FakeEloquentUser::create(['name' => 'U3', 'email' => 'u3@x.com', 'password' => 'x']);
    $role = Role::create(['name' => 'store-manager', 'guard_name' => 'web']);
    $ctx  = FakeContext::create(['name' => 'Ctx B']);

    RoleMeta::create([
        'role_id'       => $role->id,
        'scope'         => RoleMeta::SCOPE_CONTEXTUAL,
        'context_types' => ['App\\Models\\Store'],
    ]);

    expect(fn () => $this->service->assignContextual($user, $role, $ctx))
        ->toThrow(ClearanceScopeViolationException::class);
});

// --- Happy path contextual ---

it('assignContextual creates UserRoleContext for matching context type', function (): void {
    $user = FakeEloquentUser::create(['name' => 'U4', 'email' => 'u4@x.com', 'password' => 'x']);
    $role = Role::create(['name' => 'store-staff', 'guard_name' => 'web']);
    $ctx  = FakeContext::create(['name' => 'Store A']);

    RoleMeta::create([
        'role_id'       => $role->id,
        'scope'         => RoleMeta::SCOPE_CONTEXTUAL,
        'context_types' => [FakeContext::class],
    ]);

    $binding = $this->service->assignContextual($user, $role, $ctx);

    expect(UserRoleContext::count())->toBe(1);
    expect($binding->context_type)->toBe(FakeContext::class);
    expect($binding->context_id)->toBe($ctx->id);
});

// --- V15: no RoleMeta → treated as global (back-compat) ---

it('assignGlobalRole succeeds when role has no RoleMeta (V15 back-compat)', function (): void {
    $user = FakeEloquentUser::create(['name' => 'U5', 'email' => 'u5@x.com', 'password' => 'x']);
    $role = Role::create(['name' => 'legacy-role', 'guard_name' => 'web']);

    $this->service->assignGlobalRole($user, $role);

    expect($user->fresh()->hasRole('legacy-role'))->toBeTrue();
});

// --- V14: canIn merges global role permissions ---

it('canIn returns true for permission from a global role (V14)', function (): void {
    $user = FakeEloquentUser::create(['name' => 'U6', 'email' => 'u6@x.com', 'password' => 'x']);
    $perm = Permission::create(['name' => 'reports-read', 'guard_name' => 'web']);
    $role = Role::create(['name' => 'super-admin', 'guard_name' => 'web']);
    $role->givePermissionTo($perm);
    $ctx  = FakeContext::create(['name' => 'Ctx C']);

    RoleMeta::create(['role_id' => $role->id, 'scope' => RoleMeta::SCOPE_GLOBAL]);
    $user->assignRole($role);

    expect($this->contextService->canIn($user, 'reports-read', $ctx))->toBeTrue();
});

it('canIn returns false when global role lacks the requested permission', function (): void {
    $user = FakeEloquentUser::create(['name' => 'U7', 'email' => 'u7@x.com', 'password' => 'x']);
    $role = Role::create(['name' => 'viewer', 'guard_name' => 'web']);
    $ctx  = FakeContext::create(['name' => 'Ctx D']);

    RoleMeta::create(['role_id' => $role->id, 'scope' => RoleMeta::SCOPE_GLOBAL]);
    $user->assignRole($role);

    expect($this->contextService->canIn($user, 'reports-read', $ctx))->toBeFalse();
});

it('canIn isolates contextual role to its assigned context_id (V4 preserved)', function (): void {
    $user  = FakeEloquentUser::create(['name' => 'U8', 'email' => 'u8@x.com', 'password' => 'x']);
    $perm  = Permission::create(['name' => 'store-edit', 'guard_name' => 'web']);
    $role  = Role::create(['name' => 'store-manager', 'guard_name' => 'web']);
    $role->givePermissionTo($perm);
    $ctxA  = FakeContext::create(['name' => 'Store A']);
    $ctxB  = FakeContext::create(['name' => 'Store B']);

    RoleMeta::create([
        'role_id'       => $role->id,
        'scope'         => RoleMeta::SCOPE_CONTEXTUAL,
        'context_types' => [FakeContext::class],
    ]);

    UserRoleContext::create([
        'user_id'      => $user->id,
        'role_id'      => $role->id,
        'context_type' => FakeContext::class,
        'context_id'   => $ctxA->id,
    ]);

    expect($this->contextService->canIn($user, 'store-edit', $ctxA))->toBeTrue();
    expect($this->contextService->canIn($user, 'store-edit', $ctxB))->toBeFalse();
});
