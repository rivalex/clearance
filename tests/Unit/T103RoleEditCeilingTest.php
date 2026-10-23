<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Rivalex\Clearance\Exceptions\ClearanceProtectedResourceException;
use Rivalex\Clearance\Exceptions\ClearanceScopeViolationException;
use Rivalex\Clearance\Livewire\Roles\RoleForm;
use Rivalex\Clearance\Models\ClearanceMeta;
use Rivalex\Clearance\Models\ClearanceSettings;
use Rivalex\Clearance\Models\Permission as ClearancePermission;
use Rivalex\Clearance\Models\RoleMeta;
use Rivalex\Clearance\Models\UserContextPermissionOverride;
use Rivalex\Clearance\Models\UserRoleContext;
use Rivalex\Clearance\Services\RoleService;
use Rivalex\Clearance\Support\ErrorMessage;
use Rivalex\Clearance\Tests\Support\FakeEloquentUser;
use Spatie\Permission\Models\Role;

// Role edits follow the actor ceiling: rename, permission removal, parent trims, the cascade into a locked
// child, the lock flag, the scope value, the display metadata and the contextual pins.

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

    config()->set('clearance.user_model', FakeEloquentUser::class);

    $names = ['clearance-access', 'clearance-roles-write', 'orders-edit', 'reports-read'];

    foreach ($names as $name) {
        ClearancePermission::create(['name' => $name, 'guard_name' => 'web']);
    }

    $superAdminRole = Role::create(['name' => 'super_admin', 'guard_name' => 'web']);
    $superAdminRole->syncPermissions($names);

    $this->actor = t103User('actor@example.com');
    $this->actor->givePermissionTo('clearance-access', 'clearance-roles-write');

    $this->superAdmin = t103User('root@example.com');
    $this->superAdmin->assignRole($superAdminRole);
});

function t103User(string $email): FakeEloquentUser
{
    return FakeEloquentUser::create(['name' => $email, 'email' => $email, 'password' => 'x']);
}

/** Creates a web role holding the named permissions. */
function t103Role(string $name, array $permissions = []): Role
{
    $role = Role::create(['name' => $name, 'guard_name' => 'web']);
    $role->syncPermissions($permissions);

    return $role;
}

/** Component path of the ability flags for a permission name inside the RoleForm state. */
function t103Path(mixed $component, string $permission): string
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
function t103Names(Role $role): array
{
    $names = $role->fresh()->permissions->pluck('name')->all();
    sort($names);

    return $names;
}

// T103.a
it('refuses to rename a role that holds a permission the actor lacks', function (): void {
    $this->actor->givePermissionTo('orders-edit');
    $role = t103Role('editor', ['orders-edit', 'reports-read']);

    Livewire::actingAs($this->actor)->test(RoleForm::class, ['roleId' => $role->id])
        ->set('name', 'editor-renamed')
        ->call('save')
        ->assertSet('errorMessage', __('clearance::ui.errors.rename_missing', ['role' => 'editor', 'permissions' => 'reports-read']));

    expect($role->fresh()->name)->toBe('editor');
});

// T103.b
it('refuses to rename a role the actor holds', function (): void {
    $role = t103Role('staff');
    $this->actor->assignRole($role);

    Livewire::actingAs($this->actor)->test(RoleForm::class, ['roleId' => $role->id])
        ->set('name', 'staff-renamed')
        ->call('save')
        ->assertSet('errorMessage', __('clearance::ui.errors.rename_held_role'));

    expect($role->fresh()->name)->toBe('staff');
});

// T103.c
it('refuses to rename a role the actor holds in a context', function (): void {
    $role = t103Role('staff');
    UserRoleContext::create([
        'user_id' => $this->actor->getKey(),
        'role_id' => $role->id,
        'context_type' => 'App\\Models\\Team',
        'context_id' => 1,
    ]);

    Livewire::actingAs($this->actor)->test(RoleForm::class, ['roleId' => $role->id])
        ->set('name', 'staff-renamed')
        ->call('save')
        ->assertSet('errorMessage', __('clearance::ui.errors.rename_held_role'));

    expect($role->fresh()->name)->toBe('staff');
});

// T103.d
it('refuses to rename the default role', function (): void {
    $role = t103Role('newcomer');
    ClearanceSettings::set('default_role_id', (string) $role->id);

    Livewire::actingAs($this->actor)->test(RoleForm::class, ['roleId' => $role->id])
        ->set('name', 'newcomer-renamed')
        ->call('save')
        ->assertSet('errorMessage', __('clearance::ui.errors.default_role_rename'));

    expect($role->fresh()->name)->toBe('newcomer');
});

// T103.e
it('lets a roles writer rename a role they neither hold nor exceed', function (): void {
    $this->actor->givePermissionTo('orders-edit');
    $role = t103Role('editor', ['orders-edit']);

    Livewire::actingAs($this->actor)->test(RoleForm::class, ['roleId' => $role->id])
        ->set('name', 'editor-renamed')
        ->call('save')
        ->assertSet('errorMessage', null);

    expect($role->fresh()->name)->toBe('editor-renamed');
});

// T103.f
it('refuses to remove a permission the actor does not hold', function (): void {
    $this->actor->givePermissionTo('orders-edit');
    $role = t103Role('ops', ['orders-edit', 'reports-read']);

    $component = Livewire::actingAs($this->actor)->test(RoleForm::class, ['roleId' => $role->id]);
    $component->set(t103Path($component, 'reports-read').'.selected', false)
        ->call('save')
        ->assertSet('errorMessage', __('clearance::ui.errors.role_remove_missing', ['permissions' => 'reports-read', 'role' => 'ops']));

    expect(t103Names($role))->toBe(['orders-edit', 'reports-read']);
});

// T103.g
it('lets the actor remove a permission they hold', function (): void {
    $this->actor->givePermissionTo('orders-edit');
    $role = t103Role('ops', ['orders-edit']);

    $component = Livewire::actingAs($this->actor)->test(RoleForm::class, ['roleId' => $role->id]);
    $component->set(t103Path($component, 'orders-edit').'.selected', false)
        ->call('save')
        ->assertSet('errorMessage', null);

    expect(t103Names($role))->toBe([]);
});

// T103.h
it('refuses a parent change whose trim removes a permission the actor lacks', function (): void {
    $this->actor->givePermissionTo('orders-edit');
    $base = t103Role('base', ['orders-edit']);
    $child = t103Role('child', ['orders-edit', 'reports-read']);

    Livewire::actingAs($this->actor)->test(RoleForm::class, ['roleId' => $child->id])
        ->set('parentRoleId', $base->id)
        ->call('save')
        ->assertSet('errorMessage', __('clearance::ui.errors.role_remove_missing', ['permissions' => 'reports-read', 'role' => 'child']));

    expect(t103Names($child))->toBe(['orders-edit', 'reports-read'])
        ->and(RoleMeta::where('role_id', $child->id)->value('parent_role_id'))->toBeNull();

    // The trim itself, without the form, is refused by the service for the same reason.
    expect(fn () => app(RoleService::class)->setParentAs($this->actor, $child, $base))
        ->toThrow(ClearanceScopeViolationException::class, 'Cannot remove permission(s) [reports-read] from role [child]: you do not hold them.');

    expect(t103Names($child))->toBe(['orders-edit', 'reports-read'])
        ->and(RoleMeta::where('role_id', $child->id)->value('parent_role_id'))->toBeNull();
});

// T103.i
it('refuses an edit that cascades into a locked child', function (): void {
    $this->actor->givePermissionTo('orders-edit');
    $base = t103Role('base', ['orders-edit']);
    $kid = t103Role('kid', ['orders-edit']);
    RoleMeta::create(['role_id' => $kid->id, 'is_locked' => true, 'scope' => RoleMeta::SCOPE_GLOBAL, 'parent_role_id' => $base->id]);

    $component = Livewire::actingAs($this->actor)->test(RoleForm::class, ['roleId' => $base->id]);
    $component->set(t103Path($component, 'orders-edit').'.selected', false)
        ->call('save')
        ->assertSet('errorMessage', __('clearance::ui.errors.cascade_locked_child', ['role' => 'base', 'child' => 'kid']));

    expect(t103Names($base))->toBe(['orders-edit'])
        ->and(t103Names($kid))->toBe(['orders-edit']);
});

// T103.j
it('refuses to set is_locked for a roles writer', function (): void {
    $role = t103Role('plain');

    Livewire::actingAs($this->actor)->test(RoleForm::class, ['roleId' => $role->id])
        ->set('isLocked', true)
        ->call('save')
        ->assertSet('errorMessage', __('clearance::ui.errors.role_lock_super_admin'));

    expect((bool) RoleMeta::where('role_id', $role->id)->value('is_locked'))->toBeFalse();
});

// T103.k
it('refuses a roles writer any change to a locked role', function (): void {
    $role = t103Role('sealed');
    RoleMeta::create(['role_id' => $role->id, 'is_locked' => true, 'scope' => RoleMeta::SCOPE_GLOBAL]);

    Livewire::actingAs($this->actor)->test(RoleForm::class, ['roleId' => $role->id])
        ->set('isLocked', false)
        ->call('save')
        ->assertSet('errorMessage', __('clearance::ui.errors.role_locked_change'));

    expect((bool) RoleMeta::where('role_id', $role->id)->value('is_locked'))->toBeTrue();

    expect(fn () => app(RoleService::class)->updateMetaAs($this->actor, $role, false, RoleMeta::SCOPE_GLOBAL, null))
        ->toThrow(ClearanceProtectedResourceException::class, 'This role is locked and cannot be changed.');
});

// T103.l
it('lets a super_admin save a locked role and clear the lock', function (): void {
    $role = t103Role('sealed', ['orders-edit']);
    RoleMeta::create(['role_id' => $role->id, 'is_locked' => true, 'scope' => RoleMeta::SCOPE_GLOBAL]);

    // Saved without renaming and without touching the lock: it stays locked.
    $component = Livewire::actingAs($this->superAdmin)->test(RoleForm::class, ['roleId' => $role->id]);
    $component->set(t103Path($component, 'reports-read').'.selected', true)
        ->call('save')
        ->assertSet('errorMessage', null);

    expect(t103Names($role))->toBe(['orders-edit', 'reports-read'])
        ->and((bool) RoleMeta::where('role_id', $role->id)->value('is_locked'))->toBeTrue();

    Livewire::actingAs($this->superAdmin)->test(RoleForm::class, ['roleId' => $role->id])
        ->set('isLocked', false)
        ->call('save')
        ->assertSet('errorMessage', null);

    expect((bool) RoleMeta::where('role_id', $role->id)->value('is_locked'))->toBeFalse();
});

// T103.m
it('refuses a scope outside global and contextual', function (): void {
    $role = t103Role('plain');
    RoleMeta::create(['role_id' => $role->id, 'is_locked' => false, 'scope' => RoleMeta::SCOPE_GLOBAL]);

    $component = Livewire::actingAs($this->actor)->test(RoleForm::class, ['roleId' => $role->id])
        ->set('scope', 'x')
        ->call('save');

    expect($component->get('errorMessage'))->not->toBeNull()
        ->and(RoleMeta::where('role_id', $role->id)->value('scope'))->toBe(RoleMeta::SCOPE_GLOBAL);

    expect(fn () => app(RoleService::class)->updateMeta($role, false, 'x', null))
        ->toThrow(ClearanceScopeViolationException::class, 'Scope [x] is not valid: use global or contextual.');

    expect(RoleMeta::where('role_id', $role->id)->value('scope'))->toBe(RoleMeta::SCOPE_GLOBAL);
});

// T103.n
it('moves the display metadata when a role is renamed', function (): void {
    $role = t103Role('editor');
    ClearanceMeta::create(['subject_type' => 'role', 'subject_key' => 'editor', 'display_name' => 'Editor']);
    // A stale row left at the target name is dropped, never a unique-constraint error.
    ClearanceMeta::create(['subject_type' => 'role', 'subject_key' => 'writer', 'display_name' => 'Stale']);

    Livewire::actingAs($this->actor)->test(RoleForm::class, ['roleId' => $role->id])
        ->set('name', 'writer')
        ->call('save')
        ->assertSet('errorMessage', null);

    expect($role->fresh()->name)->toBe('writer')
        ->and(ClearanceMeta::where('subject_type', 'role')->where('subject_key', 'editor')->exists())->toBeFalse()
        ->and(ClearanceMeta::where('subject_type', 'role')->where('subject_key', 'writer')->value('display_name'))->toBe('Editor');
});

// T103.o
it('removes the display metadata when a role is deleted and keeps it while a same-named role remains', function (): void {
    $role = t103Role('editor');
    ClearanceMeta::create(['subject_type' => 'role', 'subject_key' => 'editor', 'display_name' => 'Editor']);

    app(RoleService::class)->delete($role);

    expect(ClearanceMeta::where('subject_type', 'role')->where('subject_key', 'editor')->exists())->toBeFalse();

    $web = t103Role('shared');
    Role::create(['name' => 'shared', 'guard_name' => 'api']);
    ClearanceMeta::create(['subject_type' => 'role', 'subject_key' => 'shared', 'display_name' => 'Shared']);

    app(RoleService::class)->delete($web);

    expect(ClearanceMeta::where('subject_type', 'role')->where('subject_key', 'shared')->exists())->toBeTrue();
});

// T103.p
it('deletes the forced_on pins of a removed permission and keeps the forced_off ones', function (): void {
    $role = t103Role('pinned', ['orders-edit', 'reports-read']);
    $orders = ClearancePermission::where('name', 'orders-edit')->firstOrFail();
    $reports = ClearancePermission::where('name', 'reports-read')->firstOrFail();

    $pin = static fn (int $userId, int $roleId, int $permissionId, string $type): UserContextPermissionOverride => UserContextPermissionOverride::create([
        'user_id' => $userId,
        'role_id' => $roleId,
        'permission_id' => $permissionId,
        'context_type' => 'App\\Models\\Team',
        'context_id' => 1,
        'type' => $type,
    ]);

    $pin(10, $role->id, $orders->id, UserContextPermissionOverride::TYPE_FORCED_ON);
    $pin(11, $role->id, $orders->id, UserContextPermissionOverride::TYPE_FORCED_OFF);
    $pin(12, $role->id, $reports->id, UserContextPermissionOverride::TYPE_FORCED_ON);

    app(RoleService::class)->syncPermissions($this->superAdmin, $role, [$reports]);

    expect(UserContextPermissionOverride::where('permission_id', $orders->id)->pluck('type')->all())
        ->toBe([UserContextPermissionOverride::TYPE_FORCED_OFF])
        ->and(UserContextPermissionOverride::where('permission_id', $reports->id)->count())->toBe(1);
});

// T103.q
it('maps every new refusal to its translated message', function (): void {
    $messages = [
        'rename_held_role' => ['Only super_admin may rename a role they hold.', []],
        'default_role_rename' => ['Only super_admin may rename the default role.', []],
        'rename_missing' => ['Cannot rename role [a]: you do not hold permission(s) [b, c].', ['role' => 'a', 'permissions' => 'b, c']],
        'role_remove_missing' => ['Cannot remove permission(s) [b] from role [a]: you do not hold them.', ['permissions' => 'b', 'role' => 'a']],
        'cascade_locked_child' => ['Cannot remove permission(s) from role [a]: its child role [k] is locked.', ['role' => 'a', 'child' => 'k']],
        'role_locked_change' => ['This role is locked and cannot be changed.', []],
        'role_lock_super_admin' => ['Only super_admin may lock or unlock a role.', []],
        'role_scope_invalid' => ['Scope [x] is not valid: use global or contextual.', ['scope' => 'x']],
    ];

    foreach ($messages as $key => [$english, $replace]) {
        expect(ErrorMessage::for(new ClearanceScopeViolationException($english)))
            ->toBe(__("clearance::ui.errors.{$key}", $replace));
    }
});
