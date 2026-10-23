<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Rivalex\Clearance\Clearance;
use Rivalex\Clearance\ClearanceServiceProvider;
use Rivalex\Clearance\Exceptions\ClearanceProtectedResourceException;
use Rivalex\Clearance\Exceptions\ClearanceScopeViolationException;
use Rivalex\Clearance\Models\RoleMeta;
use Rivalex\Clearance\Services\PermissionService;
use Rivalex\Clearance\Services\RoleService;
use Rivalex\Clearance\Services\UserClearanceService;
use Rivalex\Clearance\Support\SuperAdmin;
use Rivalex\Clearance\Tests\Support\FakeHierarchicalContext;
use Rivalex\Clearance\Tests\Support\T88AuthorizableUser;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    array_map('unlink', glob(database_path('migrations/*_create_clr_*_table.php')) ?: []);
    $this->runMigrations();
    @unlink(storage_path('.clearance-installed'));

    Schema::create('fake_users', static function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->string('password');
        $table->timestamps();
    });

    Schema::create('fake_hierarchical_contexts', static function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('parent_id')->nullable();
    });

    Permission::findOrCreate('clearance-users-write', 'web');

    // The bypass is registered at boot from config: enable it and boot the registration again.
    config()->set('clearance.super_admin_gate_bypass', true);
    app()->getProvider(ClearanceServiceProvider::class)->bootingPackage();
});

afterEach(function (): void {
    array_map('unlink', glob(database_path('migrations/*_create_clr_*_table.php')) ?: []);
    @unlink(storage_path('.clearance-installed'));
});

function t88User(string $email): T88AuthorizableUser
{
    return T88AuthorizableUser::create(['name' => $email, 'email' => $email, 'password' => 'x']);
}

/**
 * Attaches a role to a user with a raw insert, so a role of another guard can be held (host-code arrangement).
 */
function t88Attach(T88AuthorizableUser $user, Role $role): void
{
    DB::table(config('permission.table_names.model_has_roles', 'model_has_roles'))->insert([
        'role_id' => $role->id,
        'model_type' => $user->getMorphClass(),
        'model_id' => $user->id,
    ]);
    $user->unsetRelation('roles');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

it('does not treat a super_admin role of another guard as super_admin', function (): void {
    $apiRole = Role::create(['name' => 'super_admin', 'guard_name' => 'api']);
    $impostor = t88User('impostor@example.com');
    t88Attach($impostor, $apiRole);

    expect($impostor->hasRole('super_admin'))->toBeTrue('precondition: Spatie matches the name on any guard')
        ->and(SuperAdmin::is($impostor))->toBeFalse();

    // Ceilings: an assignment needing permissions the actor does not hold is refused.
    $target = t88User('target@example.com');
    $role = Role::create(['name' => 'editor', 'guard_name' => 'web']);
    $role->givePermissionTo(Permission::findOrCreate('posts-edit', 'web'));
    expect(fn () => (new UserClearanceService)->assignGlobalRoleAs($impostor, $target, $role))
        ->toThrow(ClearanceScopeViolationException::class);

    // The super_admin role itself cannot be assigned or removed by the impostor.
    $webSuper = Role::create(['name' => 'super_admin', 'guard_name' => 'web']);
    expect(fn () => (new UserClearanceService)->assignGlobalRoleAs($impostor, $target, $webSuper))
        ->toThrow(ClearanceScopeViolationException::class);

    // managesContext(): no bypass for the impostor.
    Auth::guard()->setUser($impostor);
    $context = FakeHierarchicalContext::create(['parent_id' => null]);
    expect(app(Clearance::class)->managesContext($context))->toBeFalse();

    // Gate::before: no application-wide bypass.
    expect(Gate::forUser($impostor)->allows('anything'))->toBeFalse();
});

it('recognises the super_admin role of the default guard', function (): void {
    $admin = t88User('admin@example.com');
    $admin->assignRole(Role::create(['name' => 'super_admin', 'guard_name' => 'web']));

    expect(SuperAdmin::is($admin))->toBeTrue()
        ->and(SuperAdmin::is(null))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('anything'))->toBeTrue();
});

it('refuses the reserved super_admin name on create and rename for every guard', function (): void {
    $service = new RoleService(new PermissionService(app('config')));
    $editor = Role::create(['name' => 'editor', 'guard_name' => 'api']);

    expect(fn () => $service->create('super_admin', 'api'))->toThrow(ClearanceProtectedResourceException::class)
        ->and(fn () => $service->create('super_admin', 'web'))->toThrow(ClearanceProtectedResourceException::class)
        ->and(fn () => $service->rename($editor, 'super_admin'))->toThrow(ClearanceProtectedResourceException::class);

    expect(Role::where('name', 'super_admin')->count())->toBe(0)
        ->and($editor->fresh()->name)->toBe('editor');
});

it('does not throw on Gate::before for a guest and denies the ability', function (): void {
    expect(Gate::forUser(null)->allows('x'))->toBeFalse();
});

it('locks an existing super_admin role and resets its meta on install', function (): void {
    $role = Role::create(['name' => 'super_admin', 'guard_name' => config('auth.defaults.guard', 'web')]);
    $parent = Role::create(['name' => 'parent-role', 'guard_name' => 'web']);
    RoleMeta::create([
        'role_id' => $role->id,
        'parent_role_id' => $parent->id,
        'is_locked' => false,
        'scope' => 'contextual',
        'context_types' => ['App\\Models\\Team'],
    ]);

    $this->artisan('clearance:install')->assertSuccessful();

    $meta = RoleMeta::where('role_id', $role->id)->first();
    expect($meta->is_locked)->toBeTrue()
        ->and($meta->scope)->toBe('global')
        ->and($meta->parent_role_id)->toBeNull()
        ->and($meta->context_types)->toBeNull();
});
