<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Rivalex\Clearance\Exceptions\ClearanceProtectedResourceException;
use Rivalex\Clearance\Livewire\Settings\BulkAssignDefaultRole;
use Rivalex\Clearance\Models\ClearanceSettings;
use Rivalex\Clearance\Models\Permission as ClearancePermission;
use Rivalex\Clearance\Models\UserContextPermissionOverride;
use Rivalex\Clearance\Models\UserRoleContext;
use Rivalex\Clearance\Services\PermissionService;
use Rivalex\Clearance\Services\RoleService;
use Rivalex\Clearance\Services\UserClearanceService;
use Rivalex\Clearance\Tests\Support\FakeContext;
use Rivalex\Clearance\Tests\Support\FakeEloquentUser;
use Rivalex\Clearance\Tests\Support\T97ClearanceUser;
use Rivalex\Clearance\Tests\Support\T97FlushSpyContextService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Prepares the tables. Custom pivot column names must be set before the Spatie migration runs.
 *
 * @param  array<string, string>  $columnNames
 */
function t97Setup(array $columnNames = []): void
{
    foreach ($columnNames as $key => $value) {
        config()->set('permission.column_names.'.$key, $value);
    }

    app()->forgetInstance(PermissionRegistrar::class);

    test()->runMigrations();

    Schema::create('fake_users', static function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->string('password');
        $table->timestamps();
        $table->softDeletes();
    });

    config()->set('clearance.user_model', T97ClearanceUser::class);
}

function t97User(string $email, string $class = T97ClearanceUser::class): FakeEloquentUser
{
    return $class::create(['name' => $email, 'email' => $email, 'password' => 'x']);
}

function t97Context(): FakeContext
{
    return tap(new FakeContext, fn (FakeContext $context) => $context->setAttribute('id', 5));
}

// T97.a
it('flushes the memo when a contextual write throws midway', function (): void {
    t97Setup();
    $spy = new T97FlushSpyContextService(true);
    $service = new UserClearanceService($spy);
    $role = Role::create(['name' => 'staff', 'guard_name' => 'web']);
    $user = t97User('user@example.com');

    UserRoleContext::creating(static function (): void {
        throw new RuntimeException('boom');
    });

    expect(fn () => $service->assignContextual($user, $role, t97Context()))->toThrow(RuntimeException::class, 'boom');

    expect($spy->flushes)->toBe(1);
    expect(UserRoleContext::count())->toBe(0);
});

// T97.b
it('flushes the memo when a role or permission write throws midway', function (): void {
    t97Setup();
    $spy = new T97FlushSpyContextService(true);
    $permissions = new PermissionService(app('config'), $spy);
    $roles = new RoleService($permissions, $spy);

    Role::creating(static function (): void {
        throw new RuntimeException('role boom');
    });
    ClearancePermission::creating(static function (): void {
        throw new RuntimeException('permission boom');
    });

    expect(fn () => $roles->create('staff', 'web'))->toThrow(RuntimeException::class, 'role boom');
    expect($spy->flushes)->toBe(1);

    expect(fn () => $permissions->create('orders-read', 'web'))->toThrow(RuntimeException::class, 'permission boom');
    expect($spy->flushes)->toBe(2);
});

// T97.c
it('removes the bindings and overrides of a user deleted for good', function (): void {
    t97Setup();
    $user = t97User('gone@example.com');
    $other = t97User('stays@example.com');
    $role = Role::create(['name' => 'staff', 'guard_name' => 'web']);
    $permission = Permission::findOrCreate('orders-read', 'web');

    foreach ([$user, $other] as $holder) {
        UserRoleContext::create(['user_id' => $holder->id, 'role_id' => $role->id, 'context_type' => FakeContext::class, 'context_id' => 5]);
        UserContextPermissionOverride::create([
            'user_id' => $holder->id, 'role_id' => $role->id, 'permission_id' => $permission->id,
            'context_type' => FakeContext::class, 'context_id' => 5, 'type' => UserContextPermissionOverride::TYPE_FORCED_ON,
        ]);
    }

    $user->forceDelete();

    expect(UserRoleContext::where('user_id', $user->id)->count())->toBe(0);
    expect(UserContextPermissionOverride::where('user_id', $user->id)->count())->toBe(0);
    expect(UserRoleContext::where('user_id', $other->id)->count())->toBe(1);
    expect(UserContextPermissionOverride::where('user_id', $other->id)->count())->toBe(1);
});

// T97.d
it('keeps the bindings and overrides of a soft deleted user', function (): void {
    t97Setup();
    $user = t97User('soft@example.com');
    $role = Role::create(['name' => 'staff', 'guard_name' => 'web']);
    $permission = Permission::findOrCreate('orders-read', 'web');

    UserRoleContext::create(['user_id' => $user->id, 'role_id' => $role->id, 'context_type' => FakeContext::class, 'context_id' => 5]);
    UserContextPermissionOverride::create([
        'user_id' => $user->id, 'role_id' => $role->id, 'permission_id' => $permission->id,
        'context_type' => FakeContext::class, 'context_id' => 5, 'type' => UserContextPermissionOverride::TYPE_FORCED_ON,
    ]);

    $user->delete();

    expect(T97ClearanceUser::withTrashed()->find($user->id)->trashed())->toBeTrue();
    expect(UserRoleContext::where('user_id', $user->id)->count())->toBe(1);
    expect(UserContextPermissionOverride::where('user_id', $user->id)->count())->toBe(1);
});

// T97.e
it('keeps the rows of a deleted user that is not the configured user model', function (): void {
    t97Setup();
    config()->set('clearance.user_model', FakeEloquentUser::class);
    $user = t97User('other-model@example.com');
    $role = Role::create(['name' => 'staff', 'guard_name' => 'web']);

    UserRoleContext::create(['user_id' => $user->id, 'role_id' => $role->id, 'context_type' => FakeContext::class, 'context_id' => 5]);

    $user->forceDelete();

    expect(UserRoleContext::where('user_id', $user->id)->count())->toBe(1);
});

// T97.f
it('honours custom Spatie pivot column names in the holder checks', function (): void {
    t97Setup(['role_pivot_key' => 'access_role_id', 'permission_pivot_key' => 'access_permission_id', 'model_morph_key' => 'subject_id']);
    $service = new UserClearanceService;
    $superAdmin = Role::findOrCreate('super_admin', 'web');
    $root = t97User('root@example.com');
    $root->assignRole($superAdmin);

    expect(fn () => $service->removeGlobalRoleAs($root, $root, $superAdmin))
        ->toThrow(ClearanceProtectedResourceException::class, 'The last super_admin cannot be removed.');
    expect($root->fresh()->hasRole('super_admin'))->toBeTrue();

    $second = t97User('root2@example.com');
    $second->assignRole($superAdmin);

    $service->removeGlobalRoleAs($root, $second, $superAdmin);

    expect($second->fresh()->hasRole('super_admin'))->toBeFalse();
    expect($root->fresh()->hasRole('super_admin'))->toBeTrue();
});

// T97.g
it('honours custom Spatie pivot column names when detaching holders and checking permission holders', function (): void {
    t97Setup(['role_pivot_key' => 'access_role_id', 'permission_pivot_key' => 'access_permission_id', 'model_morph_key' => 'subject_id']);
    config()->set('clearance.non_delegable', ['secrets-*']);
    $service = new UserClearanceService;
    $actor = t97User('root@example.com');
    $actor->assignRole(Role::findOrCreate('super_admin', 'web'));
    $holder = t97User('holder@example.com');
    $clerk = Role::create(['name' => 'clerk', 'guard_name' => 'web']);
    $holder->assignRole($clerk);
    DB::table('model_has_roles')->insert(['access_role_id' => $clerk->id, 'model_type' => (new T97ClearanceUser)->getMorphClass(), 'subject_id' => 9999]);

    $service->detachRoleHoldersAs($actor, $clerk);

    expect($holder->fresh()->hasRole('clerk'))->toBeFalse();
    expect(DB::table('model_has_roles')->where('access_role_id', $clerk->id)->count())->toBe(0);

    $permissions = new PermissionService(app('config'));
    $permission = ClearancePermission::create(['name' => 'orders-read', 'guard_name' => 'web']);
    $clerk->givePermissionTo($permission);

    expect(fn () => $permissions->rename($permission, 'secrets-read'))
        ->toThrow(ClearanceProtectedResourceException::class);

    $holder->givePermissionTo(Permission::findOrCreate('orders-write', 'web'));
    $direct = ClearancePermission::where('name', 'orders-write')->firstOrFail();

    // SQLite reads an unknown double-quoted column as a string literal and the Spatie model hook already
    // detaches the holders, so the raw delete is asserted by its SQL.
    $queries = [];
    DB::listen(static function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $permissions->delete($direct);

    expect(ClearancePermission::where('name', 'orders-write')->exists())->toBeFalse();
    expect(collect($queries)->contains(static fn (string $sql): bool => str_contains($sql, 'delete from "model_has_permissions" where "access_permission_id" = ?')))->toBeTrue();
});

// T97.h
it('honours custom Spatie pivot column names when syncing permissions onto a held role', function (): void {
    t97Setup(['role_pivot_key' => 'access_role_id', 'permission_pivot_key' => 'access_permission_id', 'model_morph_key' => 'subject_id']);
    config()->set('clearance.non_delegable', ['secrets-*']);
    $roles = app(RoleService::class);
    $actor = t97User('root@example.com');
    $actor->assignRole(Role::findOrCreate('super_admin', 'web'));
    $role = Role::create(['name' => 'clerk', 'guard_name' => 'web']);
    t97User('holder@example.com')->assignRole($role);
    $permission = Permission::findOrCreate('secrets-read', 'web');

    expect(fn () => $roles->syncPermissions($actor, $role, [$permission]))
        ->toThrow(ClearanceProtectedResourceException::class, 'Permission [secrets-read] is non-delegable and cannot be added to role [clerk] while users hold it.');

    expect($role->fresh()->hasPermissionTo('secrets-read'))->toBeFalse();
});

// T97.i
it('bulk assigns the default role with custom Spatie pivot column names', function (): void {
    t97Setup(['role_pivot_key' => 'access_role_id', 'permission_pivot_key' => 'access_permission_id', 'model_morph_key' => 'subject_id']);
    $role = Role::create(['name' => 'member', 'guard_name' => 'web']);
    ClearanceSettings::set('default_role_id', (string) $role->id);

    $withRole = t97User('has@example.com');
    $withRole->assignRole($role);
    $withoutA = t97User('a@example.com');
    $withoutB = t97User('b@example.com');

    $actor = t97User('actor@example.com');
    $actor->givePermissionTo(Permission::findOrCreate('clearance-access', 'web'));

    // SQLite reads an unknown double-quoted column as a string literal, so the holder pre-filter query is
    // asserted by its SQL: the service re-checks hasRole(), which hides a wrong column in the outcome.
    $queries = [];
    DB::listen(static function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    Livewire::actingAs($actor)->test(BulkAssignDefaultRole::class)
        ->call('bulkAssignDefaultRole')
        ->assertSet('bulkSuccess', true)
        ->assertSet('bulkMessage', __('clearance::ui.settings.bulk_done', ['count' => 2]));

    expect($withoutA->fresh()->hasRole('member'))->toBeTrue()
        ->and($withoutB->fresh()->hasRole('member'))->toBeTrue()
        ->and($actor->fresh()->hasRole('member'))->toBeFalse();

    expect(collect($queries)->contains(static fn (string $sql): bool => str_contains($sql, 'not exists (select 1 from "model_has_roles" where "model_has_roles"."subject_id" = "fake_users"."id" and "model_has_roles"."access_role_id" = ?')))->toBeTrue();
});
