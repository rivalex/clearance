<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Rivalex\Clearance\Exceptions\ClearanceProtectedResourceException;
use Rivalex\Clearance\Exceptions\ClearanceScopeViolationException;
use Rivalex\Clearance\Livewire\Roles\RoleForm;
use Rivalex\Clearance\Livewire\Settings;
use Rivalex\Clearance\Livewire\Settings\BulkAssignDefaultRole;
use Rivalex\Clearance\Livewire\Users\ContextualRolesPanel;
use Rivalex\Clearance\Models\ClearanceSettings;
use Rivalex\Clearance\Models\Permission as ClearancePermission;
use Rivalex\Clearance\Models\RoleMeta;
use Rivalex\Clearance\Models\UserContextPermissionOverride;
use Rivalex\Clearance\Models\UserRoleContext;
use Rivalex\Clearance\Services\ContextService;
use Rivalex\Clearance\Services\PermissionService;
use Rivalex\Clearance\Services\RoleService;
use Rivalex\Clearance\Services\UserClearanceService;
use Rivalex\Clearance\Tests\Support\FakeContext;
use Rivalex\Clearance\Tests\Support\FakeEloquentUser;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

// Pins, ceiling parents, the default role and permission deletion follow the actor ceiling for an actor
// who is not super_admin; super_admin stays unrestricted.

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
        $table->string('name')->nullable();
        $table->timestamps();
    });

    config()->set('clearance.user_model', FakeEloquentUser::class);
    config()->set('clearance.modules.users', true);
    config()->set('clearance.contextual_models', [FakeContext::class => ['label' => 'Store']]);
    config()->set('clearance.non_delegable', []);

    foreach (['clearance-access', 'clearance-roles-write', 'clearance-permissions-write', 'clearance-guards-write', 'clearance-settings-write', 'clearance-users-write'] as $name) {
        Permission::findOrCreate($name, 'web');
    }

    $this->root = t115User('root@example.com');
    $superAdmin = Role::create(['name' => 'super_admin', 'guard_name' => 'web']);
    $superAdmin->syncPermissions(Permission::where('name', 'like', 'clearance-%')->get());
    $this->root->assignRole($superAdmin);
    $this->superAdminRole = $superAdmin;

    $this->context = FakeContext::create(['name' => 'Store 1']);
    $this->service = new UserClearanceService;
});

function t115User(string $email, string ...$permissions): FakeEloquentUser
{
    $user = FakeEloquentUser::create(['name' => $email, 'email' => $email, 'password' => 'x']);

    foreach ($permissions as $permission) {
        $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    return $user;
}

/** Creates a contextual role for FakeContext holding the named permissions. */
function t115ContextRole(string $name, array $permissions = []): Role
{
    $role = Role::create(['name' => $name, 'guard_name' => 'web']);
    $role->syncPermissions(array_map(fn (string $permission) => Permission::findOrCreate($permission, 'web'), $permissions));
    RoleMeta::create(['role_id' => $role->id, 'scope' => RoleMeta::SCOPE_CONTEXTUAL, 'context_types' => [FakeContext::class]]);

    return $role;
}

function t115Bind(FakeEloquentUser $user, Role $role, FakeContext $context): void
{
    UserRoleContext::create([
        'user_id' => $user->id,
        'role_id' => $role->id,
        'context_type' => FakeContext::class,
        'context_id' => $context->id,
    ]);
}

function t115Pin(FakeEloquentUser $user, Role $role, string $permission, string $type, FakeContext $context): void
{
    UserContextPermissionOverride::create([
        'user_id' => $user->id,
        'role_id' => $role->id,
        'permission_id' => Permission::findOrCreate($permission, 'web')->id,
        'context_type' => FakeContext::class,
        'context_id' => $context->id,
        'type' => $type,
    ]);
}

/** A scoped manager: access permission plus a binding in the context to a role granting the users write permission. */
function t115Manager(FakeContext $context, array $extra = ['orders-read']): FakeEloquentUser
{
    $manager = t115User('manager@example.com', 'clearance-access');
    $role = t115ContextRole('store-manager', ['clearance-users-write', ...$extra]);
    t115Bind($manager, $role, $context);

    return $manager;
}

// T115.a
it('drops a forced_on pin the target role lacks on reassignment and keeps a re-keyed forced_off', function (): void {
    $a = t115ContextRole('a', ['orders-read', 'orders-refund']);
    $b = t115ContextRole('b', ['orders-read']);
    $user = t115User('u@example.com');
    t115Bind($user, $a, $this->context);
    t115Pin($user, $a, 'orders-refund', UserContextPermissionOverride::TYPE_FORCED_ON, $this->context);
    t115Pin($user, $a, 'orders-read', UserContextPermissionOverride::TYPE_FORCED_OFF, $this->context);

    $this->service->reassignRoleHoldersAs($this->root, $a, $b);

    $refund = Permission::findByName('orders-refund', 'web');
    $read = Permission::findByName('orders-read', 'web');

    expect(UserContextPermissionOverride::where('permission_id', $refund->id)->exists())->toBeFalse()
        ->and(UserContextPermissionOverride::where('permission_id', $read->id)->where('role_id', $b->id)->where('type', UserContextPermissionOverride::TYPE_FORCED_OFF)->exists())->toBeTrue()
        ->and(app(ContextService::class)->canIn($user, 'orders-refund', $this->context, 'web'))->toBeFalse();
});

// T115.b
it('keeps a host pin outside the bound role when a scoped manager saves the contextual panel', function (): void {
    $manager = t115Manager($this->context);
    $clerk = t115ContextRole('clerk', ['orders-read']);
    $target = t115User('target@example.com');
    t115Bind($target, $clerk, $this->context);
    t115Pin($target, $clerk, 'reports-read', UserContextPermissionOverride::TYPE_FORCED_ON, $this->context);

    Livewire::withoutLazyLoading()->actingAs($manager)
        ->test(ContextualRolesPanel::class, ['userId' => $target->id, 'contextClass' => FakeContext::class])
        ->call('saveContextualExtraPerms', $clerk->id, $this->context->id)
        ->assertOk();

    expect(UserContextPermissionOverride::where('permission_id', Permission::findByName('reports-read', 'web')->id)->count())->toBe(1);
});

// T115.c
it('refuses removing a contextual pin the actor does not hold in the context', function (): void {
    $manager = t115Manager($this->context);
    $clerk = t115ContextRole('clerk2', ['orders-read', 'orders-refund']);
    $target = t115User('target@example.com');
    t115Bind($target, $clerk, $this->context);
    t115Pin($target, $clerk, 'orders-refund', UserContextPermissionOverride::TYPE_FORCED_ON, $this->context);
    t115Pin($target, $clerk, 'orders-read', UserContextPermissionOverride::TYPE_FORCED_ON, $this->context);
    $read = Permission::findByName('orders-read', 'web');
    $refund = Permission::findByName('orders-refund', 'web');

    expect(fn () => $this->service->syncContextualExtraPermissions($manager, $target, $clerk, $this->context, [$read->id]))
        ->toThrow(ClearanceScopeViolationException::class, 'Cannot remove pinned permission(s) [orders-refund]: you do not hold them in this context.');

    expect(UserContextPermissionOverride::count())->toBe(2);

    $this->service->syncContextualExtraPermissions($manager, $target, $clerk, $this->context, [$refund->id]);

    expect(UserContextPermissionOverride::where('permission_id', $read->id)->exists())->toBeFalse()
        ->and(UserContextPermissionOverride::where('permission_id', $refund->id)->exists())->toBeTrue();
});

// T115.d
it('refuses removing a direct permission the actor does not hold (regression of the existing rule)', function (): void {
    $r = Role::create(['name' => 'r', 'guard_name' => 'web']);
    $r->givePermissionTo(Permission::findOrCreate('orders-read', 'web'));
    $target = t115User('target@example.com');
    $target->assignRole($r);
    $target->givePermissionTo(Permission::findOrCreate('reports-export', 'web'));
    $writer = t115User('writer@example.com', 'clearance-access', 'clearance-users-write', 'orders-read');

    expect(fn () => $this->service->syncGlobalExtraPermissions($writer, $target, $r, []))
        ->toThrow(ClearanceScopeViolationException::class, 'that you do not hold');

    expect($target->fresh()->hasDirectPermission('reports-export'))->toBeTrue();
});

// T115.e
it('refuses removing a ceiling parent above the actor ceiling and lets super_admin do it', function (): void {
    $writer = t115User('writer@example.com', 'clearance-access', 'clearance-roles-write', 'orders-read');
    $p = Role::create(['name' => 'p', 'guard_name' => 'web']);
    $p->syncPermissions([Permission::findOrCreate('orders-read', 'web'), Permission::findOrCreate('orders-delete', 'web')]);
    $c = Role::create(['name' => 'c', 'guard_name' => 'web']);
    $c->syncPermissions([Permission::findByName('orders-read', 'web')]);
    RoleMeta::create(['role_id' => $c->id, 'parent_role_id' => $p->id]);

    Livewire::actingAs($writer)->test(RoleForm::class, ['roleId' => $c->id])
        ->set('parentRoleId', null)
        ->call('save')
        ->assertSet('errorMessage', __('clearance::ui.errors.ceiling_parent_remove_missing', ['parent' => 'p', 'role' => 'c', 'permissions' => 'orders-delete']));

    expect(RoleMeta::where('role_id', $c->id)->value('parent_role_id'))->toBe($p->id);

    Livewire::actingAs($this->root)->test(RoleForm::class, ['roleId' => $c->id])
        ->set('parentRoleId', null)
        ->call('save')
        ->assertSet('errorMessage', null);

    expect(RoleMeta::where('role_id', $c->id)->value('parent_role_id'))->toBeNull();
});

// T115.f
it('refuses adding permissions, changing the scope or locking the default role for a roles writer', function (): void {
    $m = Role::create(['name' => 'm', 'guard_name' => 'web']);
    $m->givePermissionTo(Permission::findOrCreate('orders-read', 'web'));
    ClearanceSettings::set('default_role_id', (string) $m->id);
    $ordersRead = Permission::findByName('orders-read', 'web');
    $postsEdit = Permission::findOrCreate('posts-edit', 'web');
    $writer = t115User('writer@example.com', 'clearance-access', 'clearance-roles-write', 'orders-read', 'posts-edit');
    $roles = app(RoleService::class);

    expect(fn () => $roles->syncPermissions($writer, $m, [$ordersRead, $postsEdit]))
        ->toThrow(ClearanceScopeViolationException::class, 'Only super_admin may add permissions to the default role.');

    expect(fn () => $roles->updateMetaAs($writer, $m, false, RoleMeta::SCOPE_CONTEXTUAL, [FakeContext::class]))
        ->toThrow(ClearanceScopeViolationException::class, 'Only super_admin may change the scope of the default role.');

    expect(fn () => $roles->updateMetaAs($writer, $m, true, RoleMeta::SCOPE_GLOBAL, null))
        ->toThrow(ClearanceProtectedResourceException::class, 'Only super_admin may lock or unlock a role.');

    expect($m->fresh()->permissions->pluck('name')->all())->toBe(['orders-read']);

    $roles->syncPermissions($this->root, $m, [$ordersRead, $postsEdit]);
    $roles->updateMetaAs($this->root, $m, false, RoleMeta::SCOPE_CONTEXTUAL, [FakeContext::class]);
    $roles->updateMetaAs($this->root, $m, true, RoleMeta::SCOPE_GLOBAL, null);

    expect($m->fresh()->permissions->pluck('name')->sort()->values()->all())->toBe(['orders-read', 'posts-edit'])
        ->and((bool) RoleMeta::where('role_id', $m->id)->value('is_locked'))->toBeTrue();
});

// T115.g
it('refuses deleting a permission held by the super_admin role or a locked role and counts forced_off holders', function (): void {
    $writer = t115User('writer@example.com', 'clearance-access', 'clearance-permissions-write', 'orders-read', 'orders-export');
    $this->superAdminRole->givePermissionTo(Permission::findOrCreate('orders-read', 'web'));
    $locked = Role::create(['name' => 'l', 'guard_name' => 'web']);
    $locked->givePermissionTo(Permission::findOrCreate('orders-export', 'web'));
    RoleMeta::create(['role_id' => $locked->id, 'is_locked' => true]);
    $service = app(PermissionService::class);
    $read = ClearancePermission::where('name', 'orders-read')->first();
    $export = ClearancePermission::where('name', 'orders-export')->first();

    expect(fn () => $service->deleteAs($writer, $read))
        ->toThrow(ClearanceProtectedResourceException::class, 'the super_admin role or a locked role holds it');
    expect(fn () => $service->deleteAs($writer, $export))
        ->toThrow(ClearanceProtectedResourceException::class, 'the super_admin role or a locked role holds it');

    expect(ClearancePermission::whereIn('name', ['orders-read', 'orders-export'])->count())->toBe(2);

    $service->deleteAs($this->root, $export);

    $service->deleteAs($this->root, $read);

    expect(ClearancePermission::where('name', 'orders-export')->exists())->toBeFalse()
        ->and(ClearancePermission::where('name', 'orders-read')->exists())->toBeFalse();

    $clerk = t115ContextRole('clerk3');
    $user = t115User('u@example.com');
    t115Bind($user, $clerk, $this->context);
    t115Pin($user, $clerk, 'orders-void', UserContextPermissionOverride::TYPE_FORCED_OFF, $this->context);
    $void = ClearancePermission::where('name', 'orders-void')->first();

    expect(fn () => $service->deleteAs($writer, $void))
        ->toThrow(ClearanceScopeViolationException::class, 'users hold it and you do not hold it yourself');
});

// T115.h
it('refuses bulk default-role assignment with the settings write permission only', function (string $component): void {
    $role = Role::create(['name' => 'member', 'guard_name' => 'web']);
    ClearanceSettings::set('default_role_id', (string) $role->id);
    $plain = t115User('plain@example.com');
    $settingsOnly = t115User('settings@example.com', 'clearance-access', 'clearance-settings-write');

    Livewire::withoutLazyLoading()->actingAs($settingsOnly)->test($component)
        ->call('bulkAssignDefaultRole')
        ->assertForbidden();

    expect($plain->fresh()->hasRole('member'))->toBeFalse();

    $both = t115User('both@example.com', 'clearance-access', 'clearance-settings-write', 'clearance-users-write');

    Livewire::withoutLazyLoading()->actingAs($both)->test($component)
        ->call('bulkAssignDefaultRole');

    expect($plain->fresh()->hasRole('member'))->toBeTrue();
})->with([
    'BulkAssignDefaultRole' => BulkAssignDefaultRole::class,
    'legacy Settings' => Settings::class,
]);
