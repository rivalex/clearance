<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Rivalex\Clearance\Clearance;
use Rivalex\Clearance\ClearanceServiceProvider;
use Rivalex\Clearance\Exceptions\ClearanceConfigException;
use Rivalex\Clearance\Exceptions\ClearanceScopeViolationException;
use Rivalex\Clearance\Livewire\Settings;
use Rivalex\Clearance\Livewire\Users\RemoveAssignmentModal;
use Rivalex\Clearance\Models\ClearanceMeta;
use Rivalex\Clearance\Models\ClearanceSettings;
use Rivalex\Clearance\Models\RoleMeta;
use Rivalex\Clearance\Models\UserRoleContext;
use Rivalex\Clearance\Services\RoleService;
use Rivalex\Clearance\Services\UserClearanceService;
use Rivalex\Clearance\Support\Identity;
use Rivalex\Clearance\Support\SuperAdmin;
use Rivalex\Clearance\Tests\Support\FakeContext;
use Rivalex\Clearance\Tests\Support\FakeEloquentUser;
use Rivalex\Clearance\Tests\Support\FakeHierarchicalContext;
use Rivalex\Clearance\Tests\Support\T100Admin;
use Rivalex\Clearance\Tests\Support\T119NonIncrementingPermission;
use Rivalex\Clearance\Tests\Support\T119PlainRole;
use Rivalex\Clearance\Tests\Support\T119ScopedPermission;
use Rivalex\Clearance\Tests\Support\T119StringKeyRole;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

// Gaps found by the sensitivity batch: the self rules in isolation, the legacy Settings writes, the global
// branch of the removal modal, the super_admin branch of updateMetaAs(), the remaining boot refusals and the
// positive cases with a configured identity guard.

beforeEach(function (): void {
    $this->runMigrations();

    Schema::create('fake_users', static function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->string('password');
        $table->timestamps();
    });

    config()->set('clearance.user_model', FakeEloquentUser::class);

    Livewire::withoutLazyLoading();
});

afterEach(function (): void {
    array_map('unlink', glob(database_path('migrations/*_create_clr_*_table.php')) ?: []);
    @unlink(storage_path('.clearance-installed'));
});

function t119User(string $email): FakeEloquentUser
{
    return FakeEloquentUser::create(['name' => $email, 'email' => $email, 'password' => 'x']);
}

/**
 * Seeds the access permission and the five section write permissions on a guard.
 */
function t119Seed(string $guard = 'web'): void
{
    foreach (['clearance-access', 'clearance-permissions-write', 'clearance-roles-write', 'clearance-guards-write', 'clearance-settings-write', 'clearance-users-write'] as $name) {
        Permission::findOrCreate($name, $guard);
    }
}

it('refuses adding a permission to a role the actor holds even when the actor holds the permission', function (): void {
    $actor = t119User('t119-self-role@example.com');
    $editor = Role::create(['name' => 'editor', 'guard_name' => 'web']);
    $deleter = Role::create(['name' => 'deleter', 'guard_name' => 'web']);
    $ordersDelete = Permission::create(['name' => 'orders-delete', 'guard_name' => 'web']);
    $deleter->givePermissionTo($ordersDelete);
    $actor->assignRole($editor, $deleter);

    expect(fn () => app(RoleService::class)->syncPermissions($actor, $editor, [$ordersDelete]))
        ->toThrow(ClearanceScopeViolationException::class, 'Only super_admin may add permissions to role [editor] while holding it themselves.')
        ->and($editor->fresh()->permissions)->toBeEmpty();
});

it('refuses a self direct grant of a permission the actor holds through another role', function (): void {
    $actor = t119User('t119-self-grant@example.com');
    $editor = Role::create(['name' => 'editor', 'guard_name' => 'web']);
    $deleter = Role::create(['name' => 'deleter', 'guard_name' => 'web']);
    $postsDelete = Permission::create(['name' => 'posts-delete', 'guard_name' => 'web']);
    $deleter->givePermissionTo($postsDelete);
    $actor->assignRole($editor, $deleter);

    expect(fn () => app(UserClearanceService::class)->syncGlobalExtraPermissions($actor, $actor, $editor, [$postsDelete->id]))
        ->toThrow(ClearanceScopeViolationException::class, 'Only super_admin may modify their own direct permissions.')
        ->and($actor->fresh()->getDirectPermissions()->pluck('name')->all())->not->toContain('posts-delete');
});

it('refuses a self revoke of a direct permission the actor holds', function (): void {
    $actor = t119User('t119-self-revoke@example.com');
    $editor = Role::create(['name' => 'editor', 'guard_name' => 'web']);
    $postsEdit = Permission::create(['name' => 'posts-edit', 'guard_name' => 'web']);
    $actor->assignRole($editor);
    $actor->givePermissionTo($postsEdit);

    expect(fn () => app(UserClearanceService::class)->syncGlobalExtraPermissions($actor, $actor, $editor, []))
        ->toThrow(ClearanceScopeViolationException::class, 'Only super_admin may modify their own direct permissions.')
        ->and($actor->fresh()->getDirectPermissions()->pluck('name')->all())->toContain('posts-edit');
});

it('answers 403 to an access-only actor on every legacy Settings write and writes nothing', function (string $method, array $arguments): void {
    t119Seed();

    $member = Role::create(['name' => 'member', 'guard_name' => 'web']);
    Role::create(['name' => 'manager', 'guard_name' => 'web']);
    ClearanceSettings::set('default_role_id', (string) $member->id);
    $other = t119User('t119-other@example.com');

    $actor = t119User('t119-access-only@example.com');
    $actor->givePermissionTo('clearance-access');

    Livewire::withoutLazyLoading()->actingAs($actor)->test(Settings::class)
        ->set('showIcons', false)
        ->set('metaDisplayName', 'Changed')
        ->call($method, ...$arguments)
        ->assertForbidden();

    expect(ClearanceSettings::get('show_icons'))->toBeNull()
        ->and($other->fresh()->roles)->toBeEmpty()
        ->and(ClearanceMeta::count())->toBe(0);
})->with([
    'saveGeneral' => ['saveGeneral', []],
    'bulkAssignDefaultRole' => ['bulkAssignDefaultRole', []],
    'openMetaModal' => ['openMetaModal', ['role', 'manager']],
    'saveMeta' => ['saveMeta', []],
]);

it('answers 403 to a scoped manager removing a global role and keeps it', function (): void {
    t119Seed();

    Schema::create('fake_hierarchical_contexts', static function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('parent_id')->nullable();
    });

    config()->set('clearance.modules.users', true);
    config()->set('clearance.contextual_models', [FakeHierarchicalContext::class => ['label' => 'Store']]);

    $company = FakeHierarchicalContext::create(['parent_id' => null]);

    $manager = t119User('t119-scoped@example.com');
    Auth::guard()->setUser($manager);
    $manager->givePermissionTo('clearance-access');

    $tenantManager = Role::create(['name' => 'tenant-manager', 'guard_name' => 'web']);
    $tenantManager->givePermissionTo('clearance-users-write');
    UserRoleContext::create([
        'user_id' => $manager->id,
        'role_id' => $tenantManager->id,
        'context_type' => FakeHierarchicalContext::class,
        'context_id' => $company->id,
    ]);

    $target = t119User('t119-target@example.com');
    $staff = Role::create(['name' => 'staff', 'guard_name' => 'web']);
    $target->assignRole($staff);

    Livewire::test(RemoveAssignmentModal::class, ['userId' => $target->id, 'roleId' => $staff->id, 'scope' => 'global'])
        ->set('confirmText', 'DELETE staff')
        ->call('confirmDelete')
        ->assertForbidden();

    expect($target->fresh()->hasRole('staff'))->toBeTrue();
});

it('refuses a roles writer changing the super_admin role through updateMetaAs', function (): void {
    Schema::create('fake_contexts', static function (Blueprint $table): void {
        $table->id();
        $table->timestamps();
    });

    config()->set('clearance.contextual_models', [FakeContext::class => ['label' => 'Store']]);

    $superAdminRole = Role::create(['name' => 'super_admin', 'guard_name' => 'web']);
    $writer = t119User('t119-writer@example.com');
    Permission::findOrCreate('clearance-roles-write', 'web');
    $writer->givePermissionTo('clearance-roles-write');

    expect(fn () => app(RoleService::class)->updateMetaAs($writer, $superAdminRole, false, RoleMeta::SCOPE_GLOBAL, [FakeContext::class]))
        ->toThrow(ClearanceScopeViolationException::class, 'Only super_admin may change the super_admin role.')
        ->and(RoleMeta::where('role_id', $superAdminRole->id)->exists())->toBeFalse();

    $root = t119User('t119-root@example.com');
    $root->assignRole($superAdminRole);

    app(RoleService::class)->updateMetaAs($root, $superAdminRole, false, RoleMeta::SCOPE_GLOBAL, [FakeContext::class]);

    expect(RoleMeta::where('role_id', $superAdminRole->id)->exists())->toBeTrue();
});

it('refuses to boot with an unsupported Spatie model class', function (string $kind, string $class, string $message): void {
    $registrar = app(PermissionRegistrar::class);
    $role = $registrar->getRoleClass();
    $permission = $registrar->getPermissionClass();

    try {
        $kind === 'role' ? $registrar->setRoleClass($class) : $registrar->setPermissionClass($class);

        expect(fn () => (new ClearanceServiceProvider(app()))->bootingPackage())
            ->toThrow(ClearanceConfigException::class, $message);
    } finally {
        $registrar->setRoleClass($role);
        $registrar->setPermissionClass($permission);
    }
})->with([
    'permission with a global scope' => ['permission', T119ScopedPermission::class, 'declares a global scope'],
    'role with a string key' => ['role', T119StringKeyRole::class, 'must have an incrementing integer key'],
    'permission that does not increment' => ['permission', T119NonIncrementingPermission::class, 'must have an incrementing integer key'],
    'role that is not a Spatie role' => ['role', T119PlainRole::class, 'must extend Spatie\Permission\Models\Role'],
]);

describe('with a configured identity guard', function (): void {
    beforeEach(function (): void {
        Schema::create('fake_admins', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamps();
        });

        config()->set('auth.guards.admin', ['driver' => 'session', 'provider' => 'admins']);
        config()->set('auth.providers.admins', ['driver' => 'eloquent', 'model' => T100Admin::class]);
        config()->set('clearance.guard', 'admin');
        config()->set('clearance.user_model', null);
    });

    it('resolves the user model from the provider of the configured guard', function (): void {
        expect(Identity::userModel())->toBe(T100Admin::class);
    });

    it('recognises super_admin only on the configured guard', function (): void {
        $admin = T100Admin::create(['name' => 'a', 'email' => 't119-admin@example.com', 'password' => 'x']);
        $adminRole = Role::create(['name' => 'super_admin', 'guard_name' => 'admin']);
        DB::table(config('permission.table_names.model_has_roles', 'model_has_roles'))->insert([
            'role_id' => $adminRole->id,
            'model_type' => $admin->getMorphClass(),
            'model_id' => $admin->id,
        ]);
        $admin->unsetRelation('roles');

        $webUser = t119User('t119-web@example.com');
        $webRole = Role::create(['name' => 'super_admin', 'guard_name' => 'web']);
        $webUser->assignRole($webRole);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        expect(SuperAdmin::is($admin))->toBeTrue()
            ->and(SuperAdmin::is($webUser))->toBeFalse();
    });

    it('grants a section write permission held on the configured guard', function (): void {
        t119Seed('admin');

        $admin = T100Admin::create(['name' => 'a', 'email' => 't119-writer@example.com', 'password' => 'x']);
        DB::table(config('permission.table_names.model_has_permissions', 'model_has_permissions'))->insert([
            'permission_id' => Permission::findByName('clearance-roles-write', 'admin')->id,
            'model_type' => $admin->getMorphClass(),
            'model_id' => $admin->id,
        ]);
        $admin->unsetRelation('permissions');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Auth::guard('admin')->setUser($admin);
        app('auth')->shouldUse('admin');

        expect(app(Clearance::class)->canPerform('roles'))->toBeTrue();
    });

    it('installs the access permission and super_admin on the configured guard', function (): void {
        @unlink(storage_path('.clearance-installed'));

        $this->artisan('clearance:install')->assertSuccessful();

        expect(Permission::where('name', 'clearance-access')->where('guard_name', 'admin')->exists())->toBeTrue()
            ->and(Role::where('name', 'super_admin')->where('guard_name', 'admin')->exists())->toBeTrue()
            ->and(Role::where('name', 'super_admin')->where('guard_name', 'web')->exists())->toBeFalse();
    });
});
