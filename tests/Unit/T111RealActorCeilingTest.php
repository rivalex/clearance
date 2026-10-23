<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Rivalex\Clearance\Clearance;
use Rivalex\Clearance\Livewire\Roles\RoleForm;
use Rivalex\Clearance\Livewire\Users\ContextualRolesPanel;
use Rivalex\Clearance\Livewire\Users\GlobalRolesPanel;
use Rivalex\Clearance\Models\RoleMeta;
use Rivalex\Clearance\Models\UserContextPermissionOverride;
use Rivalex\Clearance\Models\UserRoleContext;
use Rivalex\Clearance\Tests\Support\FakeEloquentUser;
use Rivalex\Clearance\Tests\Support\FakeHierarchicalContext;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

// The ceiling and non-delegable rules driven through the Livewire actions by a real actor who is not a
// super_admin, with the guest bypass off. Each refusal has a matching allowed case, so a refusal is never
// the only thing that is proved.

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

    Schema::create('fake_hierarchical_contexts', static function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('parent_id')->nullable();
    });

    config()->set('clearance.user_model', FakeEloquentUser::class);
    config()->set('clearance.modules.users', true);
    config()->set('clearance.contextual_models', [FakeHierarchicalContext::class => ['label' => 'Store']]);

    foreach (['clearance-access', 'clearance-roles-write', 'clearance-permissions-write', 'clearance-guards-write', 'clearance-settings-write', 'clearance-users-write'] as $name) {
        Permission::findOrCreate($name, 'web');
    }

    Clearance::$bypassGuestChecksForTests = false;

    // A roles writer and users writer: every section permission is held, none of the permissions under test.
    $this->actor = t111User('actor@example.com');
    $this->actor->givePermissionTo('clearance-access', 'clearance-roles-write', 'clearance-users-write');
});

function t111User(string $email): FakeEloquentUser
{
    return FakeEloquentUser::create(['name' => $email, 'email' => $email, 'password' => 'x']);
}

/** Component path of the ability flags for a permission name inside the RoleForm state. */
function t111Path(mixed $component, string $permission): string
{
    foreach ($component->get('permissionGroups') as $group => $data) {
        foreach ($data['abilities'] as $index => $ability) {
            if ($ability['name'] === $permission) {
                return "permissionGroups.{$group}.abilities.{$index}";
            }
        }
    }

    throw new RuntimeException("Permission {$permission} not found in the form.");
}

/** Names of the permissions of a role, sorted. */
function t111Names(Role $role): array
{
    $names = $role->fresh()->permissions->pluck('name')->all();
    sort($names);

    return $names;
}

/** Opens the RoleForm of a role as the actor and ticks one permission. */
function t111SelectOnRole(FakeEloquentUser $actor, Role $role, string $permission): mixed
{
    $component = Livewire::actingAs($actor)->test(RoleForm::class, ['roleId' => $role->id]);

    return $component->set(t111Path($component, $permission).'.selected', true);
}

// --- RoleForm::save(): the ceiling of RoleService::syncPermissions() ---

it('refuses through RoleForm to add a permission to a role the actor holds', function (): void {
    $role = Role::create(['name' => 'delegated-admin', 'guard_name' => 'web']);
    $this->actor->assignRole($role);
    Permission::create(['name' => 'orders-delete', 'guard_name' => 'web']);

    $component = t111SelectOnRole($this->actor, $role, 'orders-delete')->call('save');

    expect($component->get('errorMessage'))->not->toBeNull()
        ->and(t111Names($role))->toBe([]);
});

it('refuses through RoleForm to add a clearance permission to a role', function (): void {
    $target = Role::create(['name' => 'some-other-role', 'guard_name' => 'web']);

    $component = t111SelectOnRole($this->actor, $target, 'clearance-users-write')->call('save');

    expect($component->get('errorMessage'))->not->toBeNull()
        ->and(t111Names($target))->toBe([]);
});

it('refuses through RoleForm to add a permission the actor does not hold to another role', function (): void {
    $target = Role::create(['name' => 'target-role', 'guard_name' => 'web']);
    Permission::create(['name' => 'orders-delete', 'guard_name' => 'web']);

    $component = t111SelectOnRole($this->actor, $target, 'orders-delete')->call('save');

    expect($component->get('errorMessage'))->not->toBeNull()
        ->and(t111Names($target))->toBe([]);
});

it('lets the actor add through RoleForm a permission they hold to another role', function (): void {
    $target = Role::create(['name' => 'target-role2', 'guard_name' => 'web']);
    $perm = Permission::create(['name' => 'orders-edit', 'guard_name' => 'web']);
    $this->actor->givePermissionTo($perm);

    $component = t111SelectOnRole($this->actor, $target, 'orders-edit')->call('save');

    expect($component->get('errorMessage'))->toBeNull()
        ->and(t111Names($target))->toBe(['orders-edit']);
});

// --- RoleForm::save(): non-delegable permissions on a role with holders ---

it('refuses through RoleForm a non-delegable permission on a role with a global holder', function (): void {
    config()->set('clearance.non_delegable', ['billing-*']);

    $role = Role::create(['name' => 'owner', 'guard_name' => 'web']);
    t111User('holder@example.com')->assignRole($role);
    $this->actor->givePermissionTo(Permission::findOrCreate('billing-update', 'web'));

    $component = t111SelectOnRole($this->actor, $role, 'billing-update')->call('save');

    expect($component->get('errorMessage'))->not->toBeNull()
        ->and(t111Names($role))->toBe([]);
});

it('refuses through RoleForm a non-delegable permission on a role held only through a contextual binding', function (): void {
    config()->set('clearance.non_delegable', ['billing-*']);

    $role = Role::create(['name' => 'owner', 'guard_name' => 'web']);
    $company = FakeHierarchicalContext::create(['parent_id' => null]);
    UserRoleContext::create([
        'user_id' => t111User('holder@example.com')->id,
        'role_id' => $role->id,
        'context_type' => FakeHierarchicalContext::class,
        'context_id' => $company->id,
    ]);
    $this->actor->givePermissionTo(Permission::findOrCreate('billing-update', 'web'));

    $component = t111SelectOnRole($this->actor, $role, 'billing-update')->call('save');

    expect($component->get('errorMessage'))->not->toBeNull()
        ->and(t111Names($role))->toBe([]);
});

it('lets the actor add through RoleForm a delegable permission they hold to a role with holders', function (): void {
    config()->set('clearance.non_delegable', ['billing-*']);

    $role = Role::create(['name' => 'staff', 'guard_name' => 'web']);
    t111User('holder@example.com')->assignRole($role);
    $this->actor->givePermissionTo(Permission::findOrCreate('orders-read', 'web'));

    $component = t111SelectOnRole($this->actor, $role, 'orders-read')->call('save');

    expect($component->get('errorMessage'))->toBeNull()
        ->and(t111Names($role))->toBe(['orders-read']);
});

// --- GlobalRolesPanel::saveExtraPerms(): the ceiling on direct permissions ---

/** A user holding a role, with the panel opened by the actor and one direct permission ticked. */
function t111GlobalPanel(FakeEloquentUser $actor, FakeEloquentUser $target, Role $role, Permission $permission): mixed
{
    return Livewire::actingAs($actor)->test(GlobalRolesPanel::class, ['userId' => $target->id])
        ->set("manualPermissions.{$role->id}.{$permission->id}", true);
}

it('answers 403 through the global panel when the actor grants themselves a permission they do not hold', function (): void {
    $role = Role::create(['name' => 'editor', 'guard_name' => 'web']);
    $perm = Permission::create(['name' => 'posts-delete', 'guard_name' => 'web']);
    $this->actor->assignRole($role);

    t111GlobalPanel($this->actor, $this->actor, $role, $perm)->call('saveExtraPerms', $role->id)->assertForbidden();

    expect($this->actor->fresh()->hasDirectPermission('posts-delete'))->toBeFalse();
});

it('answers 403 through the global panel when the actor grants another user a permission they do not hold', function (): void {
    $target = t111User('target@example.com');
    $role = Role::create(['name' => 'editor2', 'guard_name' => 'web']);
    $perm = Permission::create(['name' => 'posts-delete', 'guard_name' => 'web']);
    $target->assignRole($role);

    t111GlobalPanel($this->actor, $target, $role, $perm)->call('saveExtraPerms', $role->id)->assertForbidden();

    expect($target->fresh()->hasDirectPermission('posts-delete'))->toBeFalse();
});

it('lets the actor grant through the global panel a permission they hold', function (): void {
    $target = t111User('target3@example.com');
    $role = Role::create(['name' => 'editor3', 'guard_name' => 'web']);
    $perm = Permission::create(['name' => 'posts-edit', 'guard_name' => 'web']);
    $target->assignRole($role);
    $this->actor->givePermissionTo($perm);

    t111GlobalPanel($this->actor, $target, $role, $perm)->call('saveExtraPerms', $role->id)->assertOk();

    expect($target->fresh()->hasDirectPermission('posts-edit'))->toBeTrue();
});

it('answers 403 through the global panel for a clearance permission, even when the actor holds it', function (): void {
    $target = t111User('target4@example.com');
    $role = Role::create(['name' => 'editor4', 'guard_name' => 'web']);
    $perm = Permission::findOrCreate('clearance-access', 'web');
    $target->assignRole($role);

    t111GlobalPanel($this->actor, $target, $role, $perm)->call('saveExtraPerms', $role->id)->assertForbidden();

    expect($target->fresh()->hasDirectPermission('clearance-access'))->toBeFalse();
});

// --- ContextualRolesPanel::saveContextualExtraPerms(): the ceiling on contextual pins ---

/** A contextual role bound to a user in a store, holding one permission. */
function t111ContextualSetup(FakeEloquentUser $target, string $permission): array
{
    $role = Role::create(['name' => 'ctx-role', 'guard_name' => 'web']);
    RoleMeta::create(['role_id' => $role->id, 'scope' => RoleMeta::SCOPE_CONTEXTUAL, 'context_types' => [FakeHierarchicalContext::class]]);
    $perm = Permission::create(['name' => $permission, 'guard_name' => 'web']);
    $role->givePermissionTo($perm);
    $store = FakeHierarchicalContext::create(['parent_id' => null]);

    UserRoleContext::create([
        'user_id' => $target->id,
        'role_id' => $role->id,
        'context_type' => FakeHierarchicalContext::class,
        'context_id' => $store->id,
    ]);

    return [$role, $perm, $store];
}

it('answers 403 through the contextual panel when the actor pins a permission they do not hold', function (): void {
    $target = t111User('ctarget@example.com');
    [$role, $perm, $store] = t111ContextualSetup($target, 'stores-manage');

    Livewire::actingAs($this->actor)
        ->test(ContextualRolesPanel::class, ['userId' => $target->id, 'contextClass' => FakeHierarchicalContext::class])
        ->set("contextualPermissions.{$store->id}.{$role->id}.{$perm->id}", true)
        ->call('saveContextualExtraPerms', $role->id, $store->id)
        ->assertForbidden();

    expect(UserContextPermissionOverride::count())->toBe(0);
});

it('lets the actor pin through the contextual panel a permission they hold', function (): void {
    $target = t111User('ctarget2@example.com');
    [$role, $perm, $store] = t111ContextualSetup($target, 'stores-manage');
    $this->actor->givePermissionTo($perm);

    Livewire::actingAs($this->actor)
        ->test(ContextualRolesPanel::class, ['userId' => $target->id, 'contextClass' => FakeHierarchicalContext::class])
        ->set("contextualPermissions.{$store->id}.{$role->id}.{$perm->id}", true)
        ->call('saveContextualExtraPerms', $role->id, $store->id)
        ->assertOk();

    expect(UserContextPermissionOverride::where('user_id', $target->id)
        ->where('permission_id', $perm->id)
        ->where('type', UserContextPermissionOverride::TYPE_FORCED_ON)
        ->exists())->toBeTrue();
});
