<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Rivalex\Clearance\Models\ClearanceSettings;
use Rivalex\Clearance\Models\UserContextPermissionOverride;
use Rivalex\Clearance\Models\UserRoleContext;
use Rivalex\Clearance\Services\ContextService;
use Rivalex\Clearance\Tests\Support\FakeEloquentUser;
use Rivalex\Clearance\Tests\Support\T109CleanedContext;
use Rivalex\Clearance\Tests\Support\T97ClearanceUser;
use Rivalex\Clearance\Tests\Support\T97FlushSpyContextService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

// Data lifecycle: cleanup runs only after a delete succeeded, the default role id follows its role, an ineligible
// legacy default role is not converted, and clearance:prune names the user model and asks before deleting.

beforeEach(function (): void {
    $this->runMigrations();

    Schema::create('fake_users', static function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->string('password');
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('t109_contexts', static function (Blueprint $table): void {
        $table->id();
        $table->string('name')->nullable();
        $table->timestamps();
    });

    @unlink(storage_path('.clearance-installed'));
});

afterEach(function (): void {
    @unlink(storage_path('.clearance-installed'));
});

/**
 * Creates a binding and a forced_off override for the user, the role and the permission in the context.
 */
function t114Bind(int $userId, Role $role, Permission $permission, string $contextType, int $contextId): void
{
    UserRoleContext::create(['user_id' => $userId, 'role_id' => $role->id, 'context_type' => $contextType, 'context_id' => $contextId]);
    UserContextPermissionOverride::create([
        'user_id' => $userId,
        'role_id' => $role->id,
        'permission_id' => $permission->id,
        'context_type' => $contextType,
        'context_id' => $contextId,
        'type' => UserContextPermissionOverride::TYPE_FORCED_OFF,
    ]);
}

it('keeps the bindings and denies of a context whose delete is vetoed by an observer', function (): void {
    config([
        'clearance.user_model' => FakeEloquentUser::class,
        'clearance.contextual_models' => [T109CleanedContext::class => ['label' => 'Team']],
    ]);
    $ctx = T109CleanedContext::create(['name' => 'team']);
    $permission = Permission::create(['name' => 'orders-delete', 'guard_name' => 'web']);
    $role = Role::create(['name' => 'staff', 'guard_name' => 'web']);
    $role->givePermissionTo($permission);
    $user = FakeEloquentUser::create(['name' => 'U', 'email' => 't114-a@example.com', 'password' => 'x']);
    $user->assignRole($role);
    t114Bind((int) $user->getKey(), $role, $permission, T109CleanedContext::class, (int) $ctx->getKey());

    // Registered after the trait listener: registered before it, the halting dispatch would stop at the veto.
    T109CleanedContext::deleting(static fn (): bool => false);

    expect($ctx->delete())->toBeFalse()
        ->and(UserRoleContext::query()->count())->toBe(1)
        ->and(UserContextPermissionOverride::query()->count())->toBe(1)
        ->and(app(ContextService::class)->canIn($user, 'orders-delete', $ctx))->toBeFalse();
});

it('keeps the rows of a user whose delete fails', function (): void {
    config(['clearance.user_model' => T97ClearanceUser::class]);
    $permission = Permission::create(['name' => 'orders-delete', 'guard_name' => 'web']);
    $role = Role::create(['name' => 'staff', 'guard_name' => 'web']);
    $user = T97ClearanceUser::create(['name' => 'U', 'email' => 't114-b@example.com', 'password' => 'x']);
    t114Bind((int) $user->getKey(), $role, $permission, T109CleanedContext::class, 1);

    T97ClearanceUser::deleting(static function (): void {
        throw new RuntimeException('t114 delete failed');
    });

    expect(fn () => $user->forceDelete())->toThrow(RuntimeException::class, 't114 delete failed')
        ->and(UserRoleContext::query()->count())->toBe(1)
        ->and(UserContextPermissionOverride::query()->count())->toBe(1);
});

it('removes the rows of a force deleted user and flushes the memo', function (): void {
    config(['clearance.user_model' => T97ClearanceUser::class]);
    $permission = Permission::create(['name' => 'orders-delete', 'guard_name' => 'web']);
    $role = Role::create(['name' => 'staff', 'guard_name' => 'web']);
    $user = T97ClearanceUser::create(['name' => 'U', 'email' => 't114-c@example.com', 'password' => 'x']);
    t114Bind((int) $user->getKey(), $role, $permission, T109CleanedContext::class, 1);

    $spy = new T97FlushSpyContextService;
    app()->instance(ContextService::class, $spy);

    $user->forceDelete();

    expect(UserRoleContext::query()->count())->toBe(0)
        ->and(UserContextPermissionOverride::query()->count())->toBe(0)
        ->and($spy->flushes)->toBeGreaterThanOrEqual(1);
});

it('clears the default role id when an Eloquent delete removes that role', function (): void {
    $role = Role::create(['name' => 'member', 'guard_name' => 'web']);
    ClearanceSettings::set('default_role_id', (string) $role->getKey());

    $role->delete();

    expect(ClearanceSettings::get('default_role_id'))->toBeNull();
});

it('does not convert a legacy default role that cannot be the default role', function (): void {
    config(['clearance.user_model' => FakeEloquentUser::class]);
    $role = Role::create(['name' => 'staff', 'guard_name' => 'web']);
    $role->givePermissionTo(Permission::create(['name' => 'clearance-access', 'guard_name' => 'web']));
    Role::create(['name' => 'super_admin', 'guard_name' => 'web']);
    ClearanceSettings::set('default_role', 'staff');
    file_put_contents(storage_path('.clearance-installed'), '2026-01-01 00:00:00');

    $this->artisan('clearance:install', ['--no-interaction' => true])
        ->expectsOutputToContain('Legacy default role [staff] cannot be the default role on guard [web]: no default role is set.')
        ->assertSuccessful();

    expect(ClearanceSettings::get('default_role_id'))->toBeNull()
        ->and(ClearanceSettings::where('key', 'default_role')->exists())->toBeFalse();
});

it('prints the user model and deletes nothing without --force when not interactive', function (): void {
    config(['clearance.user_model' => FakeEloquentUser::class]);
    $permission = Permission::create(['name' => 'orders-read', 'guard_name' => 'web']);
    $role = Role::create(['name' => 'staff', 'guard_name' => 'web']);
    $ctx = T109CleanedContext::create(['name' => 'team']);
    t114Bind(999, $role, $permission, T109CleanedContext::class, (int) $ctx->getKey());

    $this->artisan('clearance:prune', ['--no-interaction' => true])
        ->expectsOutputToContain('User model: '.FakeEloquentUser::class)
        ->assertFailed();

    expect(UserRoleContext::query()->count())->toBe(1);
});

it('asks before deleting orphans interactively and deletes them with --force', function (): void {
    config(['clearance.user_model' => FakeEloquentUser::class]);
    $permission = Permission::create(['name' => 'orders-read', 'guard_name' => 'web']);
    $role = Role::create(['name' => 'staff', 'guard_name' => 'web']);
    $ctx = T109CleanedContext::create(['name' => 'team']);
    t114Bind(999, $role, $permission, T109CleanedContext::class, (int) $ctx->getKey());

    $this->artisan('clearance:prune')
        ->expectsConfirmation('Delete 1 orphan bindings and 1 orphan overrides?', 'no')
        ->assertSuccessful();

    expect(UserRoleContext::query()->count())->toBe(1);

    $this->artisan('clearance:prune', ['--force' => true])->assertSuccessful();

    expect(UserRoleContext::query()->count())->toBe(0)
        ->and(UserContextPermissionOverride::query()->count())->toBe(0);
});
