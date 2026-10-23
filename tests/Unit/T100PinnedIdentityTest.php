<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Rivalex\Clearance\Clearance;
use Rivalex\Clearance\Livewire\Users\UserClearanceManager;
use Rivalex\Clearance\Models\UserContextPermissionOverride;
use Rivalex\Clearance\Models\UserRoleContext;
use Rivalex\Clearance\Services\ContextService;
use Rivalex\Clearance\Support\Identity;
use Rivalex\Clearance\Support\SuperAdmin;
use Rivalex\Clearance\Tests\Support\FakeContext;
use Rivalex\Clearance\Tests\Support\FakeEloquentUser;
use Rivalex\Clearance\Tests\Support\T100Admin;
use Rivalex\Clearance\Tests\Support\T91Admin;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

// The identity guard and the user model are pinned when the package boots: route middleware such as
// `auth:sanctum` rewrites `auth.defaults.guard` for the rest of the request and must not move them.

beforeEach(function (): void {
    $this->runMigrations();

    Schema::create('fake_contexts', static function ($table): void {
        $table->id();
        $table->timestamps();
    });

    Schema::create('fake_users', static function ($table): void {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->string('password');
        $table->timestamps();
    });

    Schema::create('fake_admins', static function ($table): void {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->string('password');
        $table->timestamps();
    });

    config()->set('clearance.user_model', null);
    config()->set('auth.providers.users.model', FakeEloquentUser::class);
});

function t100User(string $email): FakeEloquentUser
{
    return FakeEloquentUser::create(['name' => $email, 'email' => $email, 'password' => 'x']);
}

/**
 * Registers a Sanctum-style guard (request guard, no provider) that returns the given user and makes it the
 * default guard for the rest of the request, as `auth:sanctum` does.
 */
function t100UseSanctum(FakeEloquentUser $user): void
{
    config()->set('auth.guards.sanctum', ['driver' => 't100-request', 'provider' => null]);
    Auth::viaRequest('t100-request', static fn () => $user);
    app('auth')->shouldUse('sanctum');
}

it('pins the default guard captured at boot against a later change of auth.defaults.guard', function (): void {
    expect(Identity::guard())->toBe('web');

    config()->set('auth.defaults.guard', 'sanctum');

    expect(Identity::guard())->toBe('web')
        ->and(SuperAdmin::guard())->toBe('web');
});

it('falls back to the live default guard when nothing was captured', function (): void {
    Identity::flush();
    config()->set('auth.defaults.guard', 'admin');

    expect(Identity::guard())->toBe('admin');
});

it('keeps a contextual forced_off denying a global grant under a guard without a provider', function (): void {
    $user = t100User('sanctum@example.com');
    $context = FakeContext::create();
    $other = FakeContext::create();

    $deletePermission = Permission::findOrCreate('orders-delete', 'web');
    $manager = Role::create(['name' => 'manager', 'guard_name' => 'web']);
    $manager->givePermissionTo($deletePermission);
    $user->assignRole($manager);

    $staff = Role::create(['name' => 'staff', 'guard_name' => 'web']);
    UserRoleContext::create([
        'user_id' => $user->id,
        'role_id' => $staff->id,
        'context_type' => FakeContext::class,
        'context_id' => $context->id,
    ]);
    UserContextPermissionOverride::create([
        'user_id' => $user->id,
        'role_id' => $staff->id,
        'permission_id' => $deletePermission->id,
        'context_type' => FakeContext::class,
        'context_id' => $context->id,
        'type' => UserContextPermissionOverride::TYPE_FORCED_OFF,
    ]);

    $contexts = app(ContextService::class);

    expect($contexts->canIn($user, 'orders-delete', $context))->toBeFalse()
        ->and($contexts->canIn($user, 'orders-delete', $other))->toBeTrue();

    t100UseSanctum($user);

    expect(app('auth')->guard()->user()?->is($user))->toBeTrue()
        ->and($contexts->isContextualSubject($user))->toBeTrue()
        ->and($contexts->canIn($user, 'orders-delete', $context))->toBeFalse()
        ->and($contexts->canIn($user, 'orders-delete', $other))->toBeTrue();
});

it('gives a panel reader with only the access permission no write access under a guard without a provider', function (): void {
    $sections = ['permissions', 'roles', 'guards', 'settings', 'users'];

    Permission::findOrCreate('clearance-access', 'web');
    foreach ($sections as $section) {
        Permission::findOrCreate('clearance-'.$section.'-write', 'web');
    }

    $reader = t100User('reader@example.com');
    $reader->givePermissionTo('clearance-access');

    t100UseSanctum($reader);

    foreach ($sections as $section) {
        expect(app(Clearance::class)->canPerform($section))
            ->toBeFalse('section ['.$section.'] must not be writable');
    }
});

it('fails closed when the identity guard has no seeded permission rows', function (): void {
    Permission::findOrCreate('clearance-access', 'web');
    Permission::findOrCreate('clearance-roles-write', 'web');

    $actor = t100User('actor@example.com');
    $actor->givePermissionTo(['clearance-access', 'clearance-roles-write']);
    $this->actingAs($actor);

    expect(app(Clearance::class)->canPerform('roles'))->toBeTrue();

    config()->set('clearance.guard', 'api');

    expect(Identity::guard())->toBe('api')
        ->and(SuperAdmin::guard())->toBe('api')
        ->and(app(Clearance::class)->canPerform('roles'))->toBeFalse();
});

it('does not let a second user model read or wipe the contextual rows of the user with the same id', function (): void {
    config()->set('auth.guards.admin', ['driver' => 'session', 'provider' => 'admins']);
    config()->set('auth.providers.admins', ['driver' => 'eloquent', 'model' => T100Admin::class]);

    $user = t100User('user@example.com');
    $admin = T100Admin::create(['name' => 'a', 'email' => 'admin@example.com', 'password' => 'x']);
    expect($admin->id)->toBe($user->id);

    $context = FakeContext::create();
    $writePermission = Permission::findOrCreate('clearance-users-write', 'admin');
    $adminRole = Role::create(['name' => 'admin-staff', 'guard_name' => 'admin']);
    $deletePermission = Permission::findOrCreate('orders-delete', 'admin');
    $adminRole->givePermissionTo([$writePermission, $deletePermission]);

    $webWritePermission = Permission::findOrCreate('clearance-users-write', 'web');
    $webRole = Role::create(['name' => 'web-staff', 'guard_name' => 'web']);
    $webRole->givePermissionTo($webWritePermission);

    UserRoleContext::create([
        'user_id' => $user->id,
        'role_id' => $adminRole->id,
        'context_type' => FakeContext::class,
        'context_id' => $context->id,
    ]);
    UserRoleContext::create([
        'user_id' => $user->id,
        'role_id' => $webRole->id,
        'context_type' => FakeContext::class,
        'context_id' => $context->id,
    ]);
    UserContextPermissionOverride::create([
        'user_id' => $user->id,
        'role_id' => $adminRole->id,
        'permission_id' => $deletePermission->id,
        'context_type' => FakeContext::class,
        'context_id' => $context->id,
        'type' => UserContextPermissionOverride::TYPE_FORCED_OFF,
    ]);

    Auth::guard('admin')->setUser($admin);
    app('auth')->shouldUse('admin');

    expect(app(Clearance::class)->managesAnyContext())->toBeFalse()
        ->and(app(ContextService::class)->isContextualSubject($admin))->toBeFalse()
        ->and(app(ContextService::class)->resolveFor($admin, $context)->all())->toBe([]);

    $admin->forceDelete();

    expect(UserRoleContext::where('user_id', $user->id)->count())->toBe(2)
        ->and(UserContextPermissionOverride::where('user_id', $user->id)->count())->toBe(1);
});

it('does not treat a super_admin role created on the request guard as super_admin', function (): void {
    config()->set('auth.guards.admin', ['driver' => 'session', 'provider' => 'admins']);
    config()->set('auth.providers.admins', ['driver' => 'eloquent', 'model' => T100Admin::class]);

    $admin = T100Admin::create(['name' => 'a', 'email' => 'admin@example.com', 'password' => 'x']);
    $role = Role::create(['name' => 'super_admin', 'guard_name' => 'admin']);

    DB::table(config('permission.table_names.model_has_roles', 'model_has_roles'))->insert([
        'role_id' => $role->id,
        'model_type' => $admin->getMorphClass(),
        'model_id' => $admin->id,
    ]);
    $admin->unsetRelation('roles');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    Auth::guard('admin')->setUser($admin);
    app('auth')->shouldUse('admin');

    expect($admin->hasRole('super_admin', 'admin'))->toBeTrue('precondition: the role is held on the admin guard')
        ->and(SuperAdmin::is($admin))->toBeFalse();
});

it('resolves the same user model in the users panel and in isContextualSubject when the default guard uses another provider', function (): void {
    config()->set('auth.guards.web.provider', 't100_people');
    config()->set('auth.providers.t100_people', ['driver' => 'eloquent', 'model' => FakeEloquentUser::class]);
    config()->set('auth.providers.users.model', T91Admin::class);
    config()->set('clearance.modules.users', true);
    Livewire::withoutLazyLoading();

    $user = t100User('person@example.com');
    $admin = T91Admin::create(['name' => 'a', 'email' => 'other-model@example.com', 'password' => 'x']);
    expect($admin->id)->toBe($user->id);

    expect(Identity::userModel())->toBe(FakeEloquentUser::class)
        ->and(app(ContextService::class)->isContextualSubject($user))->toBeTrue();

    Livewire::test(UserClearanceManager::class, ['userId' => $user->id])
        ->assertSee('person@example.com')
        ->assertDontSee('other-model@example.com');
});
