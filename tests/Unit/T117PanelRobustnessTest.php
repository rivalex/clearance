<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Rivalex\Clearance\Exceptions\ClearanceScopeViolationException;
use Rivalex\Clearance\Livewire\Permissions\DeletePermission;
use Rivalex\Clearance\Livewire\Permissions\EditPermission;
use Rivalex\Clearance\Livewire\Permissions\PermissionForm;
use Rivalex\Clearance\Livewire\Permissions\PermissionGroupRow;
use Rivalex\Clearance\Livewire\Permissions\PermissionManager;
use Rivalex\Clearance\Livewire\Roles\RoleForm;
use Rivalex\Clearance\Livewire\Settings\BulkAssignDefaultRole;
use Rivalex\Clearance\Livewire\Users\AssignRoleModal;
use Rivalex\Clearance\Livewire\Users\ContextualRolesPanel;
use Rivalex\Clearance\Livewire\Users\GlobalRolesPanel;
use Rivalex\Clearance\Livewire\Users\RemoveAssignmentModal;
use Rivalex\Clearance\Livewire\Users\UserClearanceManager;
use Rivalex\Clearance\Models\RoleMeta;
use Rivalex\Clearance\Models\UserRoleContext;
use Rivalex\Clearance\Services\RoleService;
use Rivalex\Clearance\Support\LikePattern;
use Rivalex\Clearance\Tests\Support\FakeContext;
use Rivalex\Clearance\Tests\Support\FakeEloquentUser;
use Rivalex\Clearance\Tests\Support\T92TenantUserScope;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

// Panel robustness: a permission name without the separator, the edit modal on its own guard, the render re-check
// of the Users panels, a non-numeric user id, malformed client arrays, the SQL Server LIKE class and a role on a
// guard without a provider model.

beforeEach(function (): void {
    $this->runMigrations();

    Schema::create('fake_users', static function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->string('password');
        $table->timestamps();
    });

    Schema::create('fake_contexts', static function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });

    config()->set('clearance.user_model', FakeEloquentUser::class);
    config()->set('clearance.modules.users', true);
    config()->set('clearance.contextual_models', [FakeContext::class => ['label' => 'Store']]);

    foreach (['clearance-access', 'clearance-users-write', 'clearance-roles-write', 'clearance-permissions-write'] as $name) {
        Permission::findOrCreate($name, 'web');
    }
});

function t117User(string $email): FakeEloquentUser
{
    return FakeEloquentUser::create(['name' => $email, 'email' => $email, 'password' => 'x']);
}

function t117SuperAdmin(string $email = 'root@a.test'): FakeEloquentUser
{
    $user = t117User($email);
    $user->assignRole(Role::findOrCreate('super_admin', 'web'));
    $user->givePermissionTo(['clearance-access', 'clearance-users-write', 'clearance-roles-write', 'clearance-permissions-write']);

    return $user;
}

function t117UsersWriter(string $email = 'manager@a.test'): FakeEloquentUser
{
    $user = t117User($email);
    $user->givePermissionTo(['clearance-access', 'clearance-users-write']);

    return $user;
}

it('renders a permission whose name has no separator as a one-ability row', function (): void {
    Permission::create(['name' => 'edit articles', 'guard_name' => 'web']);

    $row = Livewire::test(PermissionGroupRow::class, ['group' => 'edit articles', 'guard' => 'web'])
        ->assertOk()
        ->assertDontSeeLivewire(EditPermission::class)
        ->assertDontSeeLivewire(DeletePermission::class);

    expect($row->get('abilities'))->toBe([['color' => 'amber', 'label' => 'Edit Articles', 'name' => 'edit articles']]);

    Livewire::withoutLazyLoading()->test(PermissionManager::class)->assertOk()->assertSee('Edit Articles');
});

it('opens the edit modal on its own guard', function (): void {
    Permission::create(['name' => 'orders-read', 'guard_name' => 'api']);
    Permission::create(['name' => 'orders-delete', 'guard_name' => 'api']);
    Permission::create(['name' => 'orders-read', 'guard_name' => 'web']);

    $this->actingAs(t117SuperAdmin(), 'web');

    Livewire::test(PermissionForm::class, ['editingPrefix' => 'orders', 'guard' => 'web', 'groupKey' => 'k', 'modalName' => 'm'])
        ->assertSet('editingGuard', 'web')
        ->assertSet('crudAbilities', ['read'])
        ->set('crudAbilities', ['read', 'create'])
        ->call('save');

    expect(Permission::where('name', 'orders-create')->where('guard_name', 'web')->exists())->toBeTrue()
        ->and(Permission::where('name', 'orders-create')->where('guard_name', 'api')->exists())->toBeFalse()
        ->and(Permission::where('name', 'orders-delete')->where('guard_name', 'api')->exists())->toBeTrue();
});

it('answers 403 on a refresh of the global panel after users write is revoked', function (): void {
    $actor = t117UsersWriter();
    $target = t117User('target@a.test');
    $this->actingAs($actor, 'web');

    $component = Livewire::withoutLazyLoading()->test(GlobalRolesPanel::class, ['userId' => $target->id])->assertOk();

    $component->call('$refresh')->assertOk();

    $actor->revokePermissionTo('clearance-users-write');
    $actor->unsetRelation('permissions');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $component->call('$refresh')->assertForbidden();
});

it('answers 403 on a refresh after the target leaves the user scope', function (string $component, array $extra): void {
    $actor = t117UsersWriter();
    $target = t117User('t117@b.test');
    $this->actingAs($actor, 'web');

    $mounted = Livewire::withoutLazyLoading()->test($component, ['userId' => $target->id] + $extra)->assertOk();

    config()->set('clearance.user_scope', T92TenantUserScope::class);

    $mounted->call('$refresh')->assertForbidden();
})->with([
    'global panel' => [GlobalRolesPanel::class, []],
    'contextual panel' => [ContextualRolesPanel::class, ['contextClass' => FakeContext::class]],
]);

it('answers 403 for a non-numeric user id before querying the user table', function (string $component, array $params): void {
    $this->actingAs(t117UsersWriter(), 'web');

    DB::flushQueryLog();
    DB::enableQueryLog();

    Livewire::withoutLazyLoading()->test($component, $params)->assertForbidden();

    expect(collect(DB::getQueryLog())->filter(fn (array $q): bool => str_contains($q['query'], 'fake_users'))->count())->toBe(0);
})->with([
    'manager' => [UserClearanceManager::class, ['userId' => 'abc']],
    'global panel' => [GlobalRolesPanel::class, ['userId' => 'abc']],
    'contextual panel' => [ContextualRolesPanel::class, ['userId' => 'abc', 'contextClass' => FakeContext::class]],
    'assign modal' => [AssignRoleModal::class, ['userId' => 'abc', 'scope' => 'global']],
    'remove modal' => [RemoveAssignmentModal::class, ['userId' => 'abc', 'roleId' => 1, 'scope' => 'global']],
]);

it('refuses malformed nested arrays without a server error', function (): void {
    $this->actingAs(t117SuperAdmin(), 'web');
    $target = t117User('target@a.test');

    $clerk = Role::create(['name' => 'clerk', 'guard_name' => 'web']);
    RoleMeta::create(['role_id' => $clerk->id, 'scope' => RoleMeta::SCOPE_CONTEXTUAL, 'context_types' => [FakeContext::class]]);
    FakeContext::create(['name' => 'Store 1']);

    Livewire::withoutLazyLoading()
        ->test(AssignRoleModal::class, ['userId' => $target->id, 'scope' => 'contextual', 'contextClass' => FakeContext::class])
        ->set('selectedRoleId', $clerk->id)
        ->set('selectedContextIds', [[1, 2]])
        ->call('save')
        ->assertOk()
        ->assertSet('errorMessage', fn ($message) => $message !== null);

    expect(UserRoleContext::count())->toBe(0);

    Livewire::withoutLazyLoading()
        ->test(PermissionForm::class)
        ->set('prefix', 'orders')
        ->set('guardName', 'web')
        ->set('crudAbilities', [['x']])
        ->call('save')
        ->assertOk()
        ->assertSet('errorMessage', fn ($message) => $message !== null);

    expect(Permission::where('name', 'like', 'orders%')->count())->toBe(0);

    $editor = Role::create(['name' => 'editor', 'guard_name' => 'web']);

    Livewire::withoutLazyLoading()
        ->test(RoleForm::class, ['roleId' => $editor->id])
        ->set('contextTypes', [['x']])
        ->call('save')
        ->assertOk()
        ->assertSet('errorMessage', fn ($message) => $message !== null);

    Livewire::withoutLazyLoading()
        ->test(RoleForm::class, ['roleId' => $editor->id])
        ->set('permissionGroups', ['orders' => 'broken', 'x' => ['abilities' => [['id' => [1]], 'y']]])
        ->call('save')
        ->assertOk();

    expect($editor->fresh()->permissions)->toBeEmpty()
        ->and($editor->fresh()->name)->toBe('editor');
});

it('escapes the SQL Server character class in a search', function (): void {
    expect(LikePattern::contains('a[b]_c'))->toBe('%a![b]!_c%');

    Role::create(['name' => 'x[y', 'guard_name' => 'web']);
    Role::create(['name' => 'xy', 'guard_name' => 'web']);

    $names = Role::whereRaw("name LIKE ? ESCAPE '!'", [LikePattern::contains('x[y')])->pluck('name')->all();

    expect($names)->toBe(['x[y']);
});

it('refuses a role on a guard without a provider model', function (): void {
    config()->set('auth.guards.t117-token', ['driver' => 'token', 'provider' => null]);

    expect(fn () => app(RoleService::class)->create('reader', 't117-token'))
        ->toThrow(ClearanceScopeViolationException::class, 'Guard [t117-token] has no user provider model: a role on it could not be deleted.');

    $this->actingAs(t117SuperAdmin(), 'web');

    Livewire::withoutLazyLoading()
        ->test(RoleForm::class)
        ->set('name', 'reader')
        ->set('guardName', 't117-token')
        ->call('save')
        ->assertSet('errorMessage', __('clearance::ui.errors.role_guard_no_provider', ['guard' => 't117-token']));

    expect(Role::where('name', 'reader')->exists())->toBeFalse();

    app(RoleService::class)->create('reader', 'web');

    expect(Role::where('name', 'reader')->where('guard_name', 'web')->exists())->toBeTrue();
});

it('refuses to replace a ceiling parent whose permissions the actor does not hold', function (): void {
    $service = app(RoleService::class);

    $edit = Permission::findOrCreate('orders-edit', 'web');
    $reports = Permission::findOrCreate('reports-read', 'web');

    $base1 = Role::create(['name' => 'base1', 'guard_name' => 'web']);
    $base2 = Role::create(['name' => 'base2', 'guard_name' => 'web']);
    $child = Role::create(['name' => 'child', 'guard_name' => 'web']);

    $base1->givePermissionTo([$edit, $reports]);
    $base2->givePermissionTo($edit);
    $child->givePermissionTo($edit);

    $service->setParent($child, $base1);

    $actor = t117User('swapper@a.test');
    $actor->givePermissionTo(['clearance-access', 'clearance-roles-write', 'orders-edit']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect(fn () => $service->setParentAs($actor, $child, $base2))
        ->toThrow(ClearanceScopeViolationException::class, 'Cannot replace the ceiling parent [base1] of role [child]: you do not hold permission(s) [reports-read].');

    expect((int) RoleMeta::where('role_id', $child->id)->value('parent_role_id'))->toBe($base1->id);

    // An actor holding the old parent's permissions may replace it.
    $actor->givePermissionTo('reports-read');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $service->setParentAs($actor->fresh(), $child, $base2);

    expect((int) RoleMeta::where('role_id', $child->id)->value('parent_role_id'))->toBe($base2->id);

    // A super_admin is not bound by the rule.
    $service->setParent($child, $base1);
    $service->setParentAs(t117SuperAdmin('root2@a.test'), $child, $base2);

    expect((int) RoleMeta::where('role_id', $child->id)->value('parent_role_id'))->toBe($base2->id);
});

it('shows the bulk default-role trigger only to an actor with settings and users write', function (): void {
    Permission::findOrCreate('clearance-settings-write', 'web');

    $settingsOnly = t117User('settings@a.test');
    $settingsOnly->givePermissionTo(['clearance-access', 'clearance-settings-write']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->actingAs($settingsOnly, 'web');

    Livewire::withoutLazyLoading()
        ->test(BulkAssignDefaultRole::class)
        ->assertOk()
        ->assertDontSee(__('clearance::ui.settings.bulk.action'));

    $both = t117User('both@a.test');
    $both->givePermissionTo(['clearance-access', 'clearance-settings-write', 'clearance-users-write']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->actingAs($both, 'web');

    Livewire::withoutLazyLoading()
        ->test(BulkAssignDefaultRole::class)
        ->assertOk()
        ->assertSee(__('clearance::ui.settings.bulk.action'));
});
