<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Rivalex\Clearance\Exceptions\ClearanceProtectedResourceException;
use Rivalex\Clearance\Exceptions\ClearanceScopeViolationException;
use Rivalex\Clearance\Livewire\Guards\GuardForm;
use Rivalex\Clearance\Livewire\Permissions\PermissionForm;
use Rivalex\Clearance\Livewire\Roles\DeleteRole;
use Rivalex\Clearance\Livewire\Roles\RoleForm;
use Rivalex\Clearance\Models\Guard;
use Rivalex\Clearance\Models\RoleMeta;
use Rivalex\Clearance\Models\UserRoleContext;
use Rivalex\Clearance\Services\GuardService;
use Rivalex\Clearance\Services\PermissionService;
use Rivalex\Clearance\Services\RoleService;
use Rivalex\Clearance\Tests\Support\FakeContext;
use Rivalex\Clearance\Tests\Support\FakeEloquentUser;
use Rivalex\Clearance\Tests\Support\T94ThrowingRoleService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

// Findings #15 to #18: database guards never override config guards, role and permission forms
// only accept known guards, ceiling parents and context types are bounded, and the role form
// saves atomically and lets unexpected exceptions propagate.

beforeEach(function (): void {
    $this->runMigrations();

    Livewire::withoutLazyLoading();

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
    config()->set('clearance.contextual_models', [FakeContext::class => ['label' => 'Store']]);
    config()->set('clearance.guards', []);
    config()->set('auth.guards', [
        'web' => ['driver' => 'session', 'provider' => 'users'],
        'api' => ['driver' => 'token', 'provider' => 'users'],
    ]);

    $this->roles = app(RoleService::class);
});

function t94User(string $email): FakeEloquentUser
{
    return FakeEloquentUser::create(['name' => $email, 'email' => $email, 'password' => 'x']);
}

function t94Role(string $name, string $guard = 'web'): Role
{
    return Role::create(['name' => $name, 'guard_name' => $guard]);
}

/** Signs in a super_admin holding clearance-access and returns it. */
function t94Admin(): FakeEloquentUser
{
    $actor = t94User('t94-admin@example.com');
    $actor->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));
    $actor->givePermissionTo(Permission::findOrCreate('clearance-access', 'web'));
    test()->actingAs($actor, 'web');

    return $actor;
}

// --- GuardService ---------------------------------------------------------------------------

it('refuses to create a database guard named like a config guard', function (): void {
    expect(fn () => app(GuardService::class)->create('web', 'token', 'users'))
        ->toThrow(ClearanceProtectedResourceException::class);

    expect(Guard::where('name', 'web')->exists())->toBeFalse();
});

it('never lets a database guard created after boot override a config guard at runtime', function (): void {
    Guard::create(['name' => 'web', 'driver' => 'token', 'provider' => 'users']);

    $all = app(GuardService::class)->all();

    expect($all['web']['driver'])->toBe('session')
        ->and($all['web']['is_db'])->toBeTrue()
        ->and($all['web']['id'])->not->toBeNull()
        ->and(config('auth.guards.web.driver'))->toBe('session');
});

it('refuses a provider that is not in auth.providers on create and on update', function (): void {
    $service = app(GuardService::class);

    expect(fn () => $service->create('fresh', 'session', 'ghosts'))
        ->toThrow(ClearanceProtectedResourceException::class);

    expect(Guard::where('name', 'fresh')->exists())->toBeFalse();

    $guard = $service->create('fresh', 'session', 'users');

    expect(fn () => $service->update($guard, 'session', 'ghosts'))
        ->toThrow(ClearanceProtectedResourceException::class);

    expect($guard->fresh()->provider)->toBe('users');
});

it('refuses to update a database row whose name is a config guard', function (): void {
    $shadow = Guard::create(['name' => 'web', 'driver' => 'token', 'provider' => 'users']);

    expect(fn () => app(GuardService::class)->update($shadow, 'session', 'users'))
        ->toThrow(ClearanceProtectedResourceException::class);
});

it('adds only database guards whose driver is in the allow-list and writes only those to auth.guards', function (): void {
    config()->set('clearance.allowed_guard_drivers', ['session']);

    Guard::create(['name' => 'extra', 'driver' => 'session', 'provider' => 'users']);
    Guard::create(['name' => 'rogue', 'driver' => 'evil', 'provider' => 'users']);

    $all = app(GuardService::class)->all();

    expect($all)->toHaveKey('extra')
        ->and($all)->not->toHaveKey('rogue')
        ->and(config('auth.guards'))->toHaveKey('extra')
        ->and(config('auth.guards'))->not->toHaveKey('rogue');
});

it('updates a regular database guard after it was injected into auth.guards', function (): void {
    $service = app(GuardService::class);
    $guard = $service->create('extra', 'session', 'users');
    $service->all();

    $updated = $service->update($guard, 'token', 'users');

    expect($updated->driver)->toBe('token');
});

it('shows the refusal of GuardForm as errorMessage and writes nothing', function (): void {
    t94Admin();

    Livewire::test(GuardForm::class)
        ->set('name', 'fresh')
        ->set('driver', 'session')
        ->set('provider', 'ghosts')
        ->call('save')
        ->assertSet('errorMessage', fn (?string $message) => $message !== null && str_contains($message, 'auth.providers'));

    expect(Guard::where('name', 'fresh')->exists())->toBeFalse();
});

it('locks GuardForm guardId against client updates', function (): void {
    t94Admin();
    $guard = Guard::create(['name' => 'extra', 'driver' => 'session', 'provider' => 'users']);

    $component = Livewire::test(GuardForm::class, ['guardId' => $guard->id]);

    expect(fn () => $component->set('guardId', $guard->id + 1))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

// --- Role and permission forms ----------------------------------------------------------------

it('refuses a role on a guard that does not exist', function (): void {
    t94Admin();

    Livewire::test(RoleForm::class)
        ->set('name', 'ghost-role')
        ->set('guardName', 'nonexistent')
        ->call('save')
        ->assertSet('errorMessage', fn (?string $message) => $message !== null);

    expect(Role::where('name', 'ghost-role')->exists())->toBeFalse();
});

it('refuses a permission group on a guard that does not exist', function (): void {
    t94Admin();

    Livewire::test(PermissionForm::class)
        ->set('prefix', 'ghosts')
        ->set('guardName', 'nonexistent')
        ->set('crudAbilities', ['read'])
        ->call('save')
        ->assertSet('errorMessage', fn (?string $message) => $message !== null);

    expect(Permission::where('name', 'ghosts-read')->exists())->toBeFalse();
});

it('locks PermissionForm editingGuard and guard against client updates', function (): void {
    t94Admin();
    Permission::create(['name' => 'orders-read', 'guard_name' => 'web']);

    $component = Livewire::test(PermissionForm::class, ['editingPrefix' => 'orders']);

    expect(fn () => $component->set('editingGuard', 'api'))
        ->toThrow(CannotUpdateLockedPropertyException::class);
    expect(fn () => $component->set('guard', 'api'))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('saves a permission group edit on the guard resolved at mount, not on the client guard', function (): void {
    t94Admin();
    Permission::create(['name' => 'orders-read', 'guard_name' => 'web']);

    Livewire::test(PermissionForm::class, ['editingPrefix' => 'orders'])
        ->set('guardName', 'api')
        ->set('crudAbilities', ['read', 'create'])
        ->call('save');

    expect(Permission::where('name', 'orders-create')->where('guard_name', 'web')->exists())->toBeTrue()
        ->and(Permission::where('name', 'orders-create')->where('guard_name', 'api')->exists())->toBeFalse();
});

it('ignores a client guard when editing an existing role', function (): void {
    t94Admin();
    $role = t94Role('editor');
    Permission::create(['name' => 'orders-read', 'guard_name' => 'api']);

    Livewire::test(RoleForm::class, ['roleId' => $role->id])
        ->set('guardName', 'api')
        ->set('permissionGroups.orders.abilities.0.selected', true)
        ->call('save')
        ->assertSet('errorMessage', null);

    expect($role->fresh()->guard_name)->toBe('web')
        ->and($role->fresh()->permissions)->toHaveCount(0);
});

it('refuses a ceiling parent that is not among the offered options', function (): void {
    t94Admin();
    $foreign = t94Role('foreign-parent', 'api');

    Livewire::test(RoleForm::class)
        ->set('name', 'child-role')
        ->set('guardName', 'web')
        ->set('parentRoleId', $foreign->id)
        ->call('save')
        ->assertSet('errorMessage', fn (?string $message) => $message !== null);

    expect(Role::where('name', 'child-role')->exists())->toBeFalse();
});

it('refuses a ceiling parent id that no longer exists', function (): void {
    t94Admin();
    $gone = t94Role('gone-parent');
    $goneId = $gone->id;
    $gone->delete();

    Livewire::test(RoleForm::class)
        ->set('name', 'child-role')
        ->set('guardName', 'web')
        ->set('parentRoleId', $goneId)
        ->call('save')
        ->assertSet('errorMessage', fn (?string $message) => $message !== null);

    expect(Role::where('name', 'child-role')->exists())->toBeFalse();
});

it('refuses a role name already used on the same guard', function (): void {
    t94Admin();
    t94Role('duplicate');

    Livewire::test(RoleForm::class)
        ->set('name', 'duplicate')
        ->set('guardName', 'web')
        ->call('save')
        ->assertSet('errorMessage', fn (?string $message) => $message !== null);

    expect(Role::where('name', 'duplicate')->count())->toBe(1);
});

it('rolls back the rename and the permission sync of the same save when updateMeta is refused', function (): void {
    t94Admin();
    $role = t94Role('editor');
    Permission::create(['name' => 'orders-read', 'guard_name' => 'web']);

    Livewire::test(RoleForm::class, ['roleId' => $role->id])
        ->set('name', 'renamed-editor')
        ->set('permissionGroups.orders.abilities.0.selected', true)
        ->set('scope', RoleMeta::SCOPE_CONTEXTUAL)
        ->set('contextTypes', ['Unknown\\ContextType'])
        ->call('save')
        ->assertSet('errorMessage', fn (?string $message) => $message !== null && str_contains($message, 'contextual_models'));

    expect($role->fresh()->name)->toBe('editor')
        ->and(DB::table('role_has_permissions')->where('role_id', $role->id)->count())->toBe(0);
});

it('lets an unexpected exception propagate instead of showing it as errorMessage', function (): void {
    t94Admin();
    app()->instance(RoleService::class, new T94ThrowingRoleService(app(PermissionService::class)));

    expect(fn () => Livewire::test(RoleForm::class)
        ->set('name', 'boom-role')
        ->set('guardName', 'web')
        ->call('save'))
        ->toThrow(RuntimeException::class);

    expect(Role::where('name', 'boom-role')->exists())->toBeFalse();
});

// --- RoleService::setParent -----------------------------------------------------------------

it('refuses setParent with the role as its own parent', function (): void {
    $role = t94Role('solo');

    expect(fn () => $this->roles->setParent($role, $role))
        ->toThrow(ClearanceScopeViolationException::class);

    expect(RoleMeta::where('role_id', $role->id)->value('parent_role_id'))->toBeNull();
});

it('refuses setParent across guards', function (): void {
    $child = t94Role('child');
    $parent = t94Role('parent', 'api');

    expect(fn () => $this->roles->setParent($child, $parent))
        ->toThrow(ClearanceScopeViolationException::class);

    expect(RoleMeta::where('role_id', $child->id)->value('parent_role_id'))->toBeNull();
});

it('refuses setParent when super_admin is the child or the parent', function (): void {
    $superAdmin = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    $other = t94Role('other');

    expect(fn () => $this->roles->setParent($superAdmin, $other))
        ->toThrow(ClearanceScopeViolationException::class);
    expect(fn () => $this->roles->setParent($other, $superAdmin))
        ->toThrow(ClearanceScopeViolationException::class);

    expect(RoleMeta::whereNotNull('parent_role_id')->count())->toBe(0);
});

// --- RoleService::updateMeta ----------------------------------------------------------------

it('refuses narrowing the context types of a role with holders', function (): void {
    $role = t94Role('stores');
    RoleMeta::create([
        'role_id' => $role->id,
        'scope' => RoleMeta::SCOPE_CONTEXTUAL,
        'context_types' => [FakeContext::class, 'Stored\\Other'],
    ]);
    $context = FakeContext::create(['name' => 'Store 1']);
    UserRoleContext::create([
        'user_id' => t94User('t94-bound@example.com')->id,
        'role_id' => $role->id,
        'context_type' => FakeContext::class,
        'context_id' => $context->id,
    ]);

    expect(fn () => $this->roles->updateMeta($role, false, RoleMeta::SCOPE_CONTEXTUAL, [FakeContext::class]))
        ->toThrow(ClearanceScopeViolationException::class);

    expect(RoleMeta::where('role_id', $role->id)->value('context_types'))->toBe([FakeContext::class, 'Stored\\Other']);
});

it('narrows the context types of a role without holders and keeps an already stored unknown type', function (): void {
    $role = t94Role('free-stores');
    RoleMeta::create([
        'role_id' => $role->id,
        'scope' => RoleMeta::SCOPE_CONTEXTUAL,
        'context_types' => [FakeContext::class, 'Stored\\Legacy'],
    ]);

    $this->roles->updateMeta($role, false, RoleMeta::SCOPE_CONTEXTUAL, ['Stored\\Legacy']);

    expect(RoleMeta::where('role_id', $role->id)->value('context_types'))->toBe(['Stored\\Legacy']);
});

it('refuses a context type that is not in clearance.contextual_models', function (): void {
    $role = t94Role('unknown-type');

    expect(fn () => $this->roles->updateMeta($role, false, RoleMeta::SCOPE_CONTEXTUAL, ['Unknown\\ContextType']))
        ->toThrow(ClearanceScopeViolationException::class);

    expect(RoleMeta::where('role_id', $role->id)->exists())->toBeFalse();
});

// --- DeleteRole -----------------------------------------------------------------------------

it('refuses to reassign the holders of a deleted role to a role on another guard', function (): void {
    $admin = t94Admin();
    $role = t94Role('to-delete');
    $foreign = t94Role('foreign-target', 'api');
    $holder = t94User('t94-holder@example.com');
    $holder->assignRole($role);

    Livewire::actingAs($admin)->test(DeleteRole::class, ['roleId' => $role->id])
        ->set('action', 'reassign')
        ->set('targetRoleId', $foreign->id)
        ->set('confirmText', 'DELETE '.$role->name)
        ->call('confirmDelete')
        ->assertSet('errorMessage', fn (?string $message) => $message !== null);

    expect(Role::find($role->id))->not->toBeNull()
        ->and(DB::table('model_has_roles')->where('role_id', $role->id)->count())->toBe(1)
        ->and(DB::table('model_has_roles')->where('role_id', $foreign->id)->count())->toBe(0);
});
