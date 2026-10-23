<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Rivalex\Clearance\Exceptions\ClearanceProtectedResourceException;
use Rivalex\Clearance\Exceptions\ClearanceScopeViolationException;
use Rivalex\Clearance\Livewire\Roles\DeleteRole;
use Rivalex\Clearance\Livewire\Roles\RoleManager;
use Rivalex\Clearance\Models\ClearanceSettings;
use Rivalex\Clearance\Models\UserRoleContext;
use Rivalex\Clearance\Services\RoleService;
use Rivalex\Clearance\Services\UserClearanceService;
use Rivalex\Clearance\Tests\Support\FakeHierarchicalContext;
use Rivalex\Clearance\Tests\Support\T92TenantUserScope;
use Rivalex\Clearance\Tests\Support\T97ClearanceUser;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

// Deleting a role follows the actor ceiling: reassignment checks the replaced role as well as the target,
// the holders must be manageable, the default role is never deleted, and the Roles page honours renamed pivot columns.

/**
 * Prepares the tables. Custom pivot column names must be set before the Spatie migration runs.
 *
 * @param  array<string, string>  $columnNames
 */
function t104Setup(array $columnNames = []): void
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

    Schema::create('fake_hierarchical_contexts', static function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('parent_id')->nullable();
    });

    config()->set('clearance.user_model', T97ClearanceUser::class);
}

function t104User(string $email): T97ClearanceUser
{
    return T97ClearanceUser::create(['name' => $email, 'email' => $email, 'password' => 'x']);
}

function t104Role(string $name, string ...$permissions): Role
{
    $role = Role::create(['name' => $name, 'guard_name' => 'web']);

    foreach ($permissions as $permission) {
        $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    return $role;
}

/** A non-super_admin actor holding clearance-access and orders-read. */
function t104Manager(): T97ClearanceUser
{
    $manager = t104User('manager@a.test');
    $manager->givePermissionTo(Permission::findOrCreate('clearance-access', 'web'));
    $manager->givePermissionTo(Permission::findOrCreate('orders-read', 'web'));

    return $manager;
}

function t104SuperAdmin(): T97ClearanceUser
{
    $root = t104User('root@a.test');
    $root->assignRole(Role::findOrCreate('super_admin', 'web'));
    $root->givePermissionTo(Permission::findOrCreate('clearance-access', 'web'));

    return $root;
}

function t104Bind(T97ClearanceUser $user, Role $role, FakeHierarchicalContext $context): void
{
    UserRoleContext::create([
        'user_id' => $user->id,
        'role_id' => $role->id,
        'context_type' => FakeHierarchicalContext::class,
        'context_id' => $context->id,
    ]);
}

function t104Delete(T97ClearanceUser $actor, Role $role, string $action = 'detach', ?Role $target = null): mixed
{
    return Livewire::actingAs($actor)->test(DeleteRole::class, ['roleId' => $role->id])
        ->set('action', $action)
        ->set('targetRoleId', $target?->id)
        ->set('confirmText', 'DELETE '.$role->name)
        ->call('confirmDelete');
}

/** Number of queries run while the callback executes. */
function t104Queries(Closure $callback): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $callback();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

it('refuses reassigning global holders of a role above the actor ceiling to an empty role', function (): void {
    t104Setup();
    $manager = t104Manager();
    $wide = t104Role('wide', 'orders-delete');
    $empty = t104Role('empty');
    $holder = t104User('holder@a.test');
    $holder->assignRole($wide);

    expect(fn () => app(UserClearanceService::class)->reassignRoleHoldersAs($manager, $wide, $empty))
        ->toThrow(ClearanceScopeViolationException::class, 'Cannot remove role [wide]: you do not hold permission(s) [orders-delete].');

    expect($holder->fresh()->hasRole('wide'))->toBeTrue()
        ->and($holder->fresh()->hasRole('empty'))->toBeFalse();
});

it('refuses reassigning contextual bindings of a role above the actor ceiling to an empty role', function (): void {
    t104Setup();
    $manager = t104Manager();
    $context = FakeHierarchicalContext::create(['parent_id' => null]);
    $wide = t104Role('wide', 'orders-delete');
    $empty = t104Role('empty');
    $staff = t104User('staff@a.test');
    t104Bind($staff, $wide, $context);

    expect(fn () => app(UserClearanceService::class)->reassignRoleHoldersAs($manager, $wide, $empty))
        ->toThrow(ClearanceScopeViolationException::class, 'Cannot remove role [wide]: you do not hold permission(s) [orders-delete].');

    expect(UserRoleContext::where('role_id', $wide->id)->count())->toBe(1)
        ->and(UserRoleContext::where('role_id', $empty->id)->count())->toBe(0);
});

it('refuses reassigning a contextual binding of a super_admin for a non super_admin actor', function (): void {
    t104Setup();
    $manager = t104Manager();
    $context = FakeHierarchicalContext::create(['parent_id' => null]);
    $clerk = t104Role('clerk', 'orders-read');
    $empty = t104Role('empty');
    $root = t104SuperAdmin();
    t104Bind($root, $clerk, $context);

    expect(fn () => app(UserClearanceService::class)->reassignRoleHoldersAs($manager, $clerk, $empty))
        ->toThrow(ClearanceScopeViolationException::class, 'Only super_admin may change the roles of a super_admin.');

    expect(UserRoleContext::where('role_id', $clerk->id)->count())->toBe(1);

    app(UserClearanceService::class)->reassignRoleHoldersAs($root, $clerk, $empty);

    expect(UserRoleContext::where('role_id', $empty->id)->count())->toBe(1);
});

it('skips a binding whose user record does not exist when reassigning', function (): void {
    t104Setup();
    $manager = t104Manager();
    $context = FakeHierarchicalContext::create(['parent_id' => null]);
    $clerk = t104Role('clerk', 'orders-read');
    $empty = t104Role('empty');
    UserRoleContext::create(['user_id' => 9999, 'role_id' => $clerk->id, 'context_type' => FakeHierarchicalContext::class, 'context_id' => $context->id]);

    app(UserClearanceService::class)->reassignRoleHoldersAs($manager, $clerk, $empty);

    expect(UserRoleContext::where('role_id', $empty->id)->count())->toBe(1);
});

it('refuses changing a role held by a user outside the user scope, without an authenticated user', function (): void {
    t104Setup();
    config()->set('clearance.user_scope', T92TenantUserScope::class);
    $manager = t104Manager();
    $clerk = t104Role('clerk', 'orders-read');
    $empty = t104Role('empty');
    $outside = t104User('holder@b.test');
    $outside->assignRole($clerk);

    expect(auth()->user())->toBeNull();

    expect(fn () => app(UserClearanceService::class)->reassignRoleHoldersAs($manager, $clerk, $empty))
        ->toThrow(ClearanceScopeViolationException::class, 'Cannot change role [clerk]: it is held by a user you may not manage.');
    expect(fn () => app(UserClearanceService::class)->detachRoleHoldersAs($manager, $clerk))
        ->toThrow(ClearanceScopeViolationException::class, 'Cannot change role [clerk]: it is held by a user you may not manage.');

    expect($outside->fresh()->hasRole('clerk'))->toBeTrue();

    // The same role held by a user inside the scope is changed.
    $outside->removeRole($clerk);
    $inside = t104User('holder@a.test');
    $inside->assignRole($clerk);

    app(UserClearanceService::class)->reassignRoleHoldersAs($manager, $clerk, $empty);

    expect($inside->fresh()->hasRole('empty'))->toBeTrue();
});

it('refuses a binding user outside the user scope and the deletion through the component', function (): void {
    t104Setup();
    config()->set('clearance.user_scope', T92TenantUserScope::class);
    $manager = t104Manager();
    $context = FakeHierarchicalContext::create(['parent_id' => null]);
    $clerk = t104Role('clerk', 'orders-read');
    $outside = t104User('staff@b.test');
    t104Bind($outside, $clerk, $context);

    $component = t104Delete($manager, $clerk);

    expect($component->get('errorMessage'))->toBe(__('clearance::ui.errors.holder_out_of_scope', ['role' => 'clerk']))
        ->and(Role::find($clerk->id))->not->toBeNull()
        ->and(UserRoleContext::where('role_id', $clerk->id)->count())->toBe(1);
});

it('refuses deleting a role without holders when the actor lacks one of its permissions', function (): void {
    t104Setup();
    $manager = t104Manager();
    $wide = t104Role('wide', 'orders-read', 'orders-delete');

    expect(fn () => app(RoleService::class)->deleteAs($manager, $wide))
        ->toThrow(ClearanceScopeViolationException::class, 'Cannot delete role [wide]: you do not hold permission(s) [orders-delete].');

    $component = t104Delete($manager, $wide);

    expect($component->get('errorMessage'))->toBe(__('clearance::ui.errors.delete_missing', ['role' => 'wide', 'permissions' => 'orders-delete']))
        ->and(Role::find($wide->id))->not->toBeNull();

    t104Delete(t104SuperAdmin(), $wide);

    expect(Role::find($wide->id))->toBeNull();
});

it('lets an actor holding every permission of the role delete it', function (): void {
    t104Setup();
    $manager = t104Manager();
    $clerk = t104Role('clerk', 'orders-read');

    t104Delete($manager, $clerk);

    expect(Role::find($clerk->id))->toBeNull();
});

it('refuses deleting the default role even for a super_admin, while the trusted delete() stays unchanged', function (): void {
    t104Setup();
    $root = t104SuperAdmin();
    $member = t104Role('member', 'orders-read');
    ClearanceSettings::set('default_role_id', (string) $member->id);

    expect(fn () => app(RoleService::class)->deleteAs($root, $member))
        ->toThrow(ClearanceProtectedResourceException::class, 'The default role cannot be deleted; choose another default role first.');

    $component = t104Delete($root, $member);

    expect($component->get('errorMessage'))->toBe(__('clearance::ui.errors.default_role_no_delete'))
        ->and(Role::find($member->id))->not->toBeNull();

    app(RoleService::class)->delete($member);

    expect(Role::find($member->id))->toBeNull();
});

it('renders the Roles page and deletes a role with a custom role pivot column', function (): void {
    t104Setup(['role_pivot_key' => 'role_test_id']);
    Livewire::withoutLazyLoading();
    $root = t104SuperAdmin();
    $clerk = t104Role('clerk', 'orders-read');
    $holder = t104User('holder@a.test');
    $holder->assignRole($clerk);

    Livewire::test(RoleManager::class)
        ->assertViewHas('roleData', fn ($data) => collect($data->items())->firstWhere('role.id', $clerk->id)['users_count'] === 1);

    Livewire::test(DeleteRole::class, ['roleId' => $clerk->id])
        ->assertViewHas('affectedGlobalCount', 1);

    t104Delete($root, $clerk);

    expect(Role::find($clerk->id))->toBeNull()
        ->and($holder->fresh()->roles()->count())->toBe(0)
        ->and(DB::table('model_has_roles')->where('role_test_id', $clerk->id)->count())->toBe(0);
});

it('keeps the queries of one DeleteRole render independent of the number of roles and permissions', function (): void {
    t104Setup();
    $names = [];

    foreach (range(1, 30) as $number) {
        $names[] = Permission::findOrCreate('perm-'.$number, 'web')->name;
    }

    $target = t104Role('target');
    $count = static fn (): int => t104Queries(static function () use ($target): void {
        Livewire::test(DeleteRole::class, ['roleId' => $target->id]);
    });

    foreach (range(1, 10) as $number) {
        Role::create(['name' => 'role-'.$number, 'guard_name' => 'web'])->givePermissionTo($names);
    }
    $count();
    $withTen = $count();

    foreach (range(11, 30) as $number) {
        Role::create(['name' => 'role-'.$number, 'guard_name' => 'web'])->givePermissionTo($names);
    }
    $withThirty = $count();

    expect($withThirty)->toBe($withTen);
});

it('keeps the queries of the Roles page independent of the number of roles', function (): void {
    t104Setup();
    Livewire::withoutLazyLoading();
    $count = static fn (): int => t104Queries(static function (): void {
        Livewire::withoutLazyLoading()->test(RoleManager::class);
    });

    foreach (range(1, 20) as $number) {
        t104Role('role-'.$number, 'perm-'.$number);
    }
    $count();
    $withTwenty = $count();

    foreach (range(21, 40) as $number) {
        t104Role('role-'.$number, 'perm-'.$number);
    }
    $withForty = $count();

    expect($withForty)->toBe($withTwenty);
});
