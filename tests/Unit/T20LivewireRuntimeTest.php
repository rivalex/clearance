<?php

declare(strict_types=1);

use Illuminate\View\View;
use Rivalex\Clearance\Livewire\Guards\GuardManager;
use Rivalex\Clearance\Livewire\Hierarchy\HierarchyManager;
use Rivalex\Clearance\Livewire\Permissions\PermissionForm;
use Rivalex\Clearance\Livewire\Permissions\PermissionManager;
use Rivalex\Clearance\Livewire\Roles\RoleForm;
use Rivalex\Clearance\Livewire\Roles\RoleManager;
use Rivalex\Clearance\Livewire\Users\UserRoleManager;
use Rivalex\Clearance\Models\RoleHierarchy;
use Rivalex\Clearance\Models\RolePermissionOverride;
use Rivalex\Clearance\Models\UserRoleContext;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $this->runMigrations();
});

// ─── GuardManager ────────────────────────────────────────────────────────────

it('GuardManager mount populates guards array', function (): void {
    $component = new GuardManager;
    app()->call([$component, 'mount']);

    expect($component->guards)->toBeArray();
});

it('GuardManager render returns View', function (): void {
    $component = new GuardManager;

    expect($component->render())->toBeInstanceOf(View::class);
});

// ─── PermissionManager ───────────────────────────────────────────────────────

it('PermissionManager mount runs without error', function (): void {
    Permission::create(['name' => 'orders-read', 'guard_name' => 'web']);

    $component = new PermissionManager;
    app()->call([$component, 'mount']); // no-op; data computed in render()

    expect($component)->toBeInstanceOf(PermissionManager::class);
});

it('PermissionManager create opens form in create mode', function (): void {
    $component = new PermissionManager;
    $component->create();

    expect($component->showForm)->toBeTrue()
        ->and($component->editingPrefix)->toBe('');
});

it('PermissionManager edit opens form for given prefix', function (): void {
    $component = new PermissionManager;
    $component->edit('orders');

    expect($component->editingPrefix)->toBe('orders')
        ->and($component->showForm)->toBeTrue();
});

it('PermissionManager closeForm resets state', function (): void {
    $component = new PermissionManager;
    $component->edit('orders');
    $component->closeForm();

    expect($component->showForm)->toBeFalse()
        ->and($component->editingPrefix)->toBe('');
});

it('PermissionManager colorForGroup returns consistent color', function (): void {
    $component = new PermissionManager;

    expect($component->colorForGroup('orders'))->toBeString()
        ->and($component->colorForGroup('orders'))->toBe($component->colorForGroup('orders'));
});

it('PermissionManager delete stages prefix for confirmation (T25)', function (): void {
    $component = new PermissionManager;
    $component->delete('orders');

    expect($component->deletingPrefix)->toBe('orders')
        ->and($component->showForm)->toBeFalse();
});

it('PermissionManager confirmDeleteGroup removes all permissions in group', function (): void {
    Permission::create(['name' => 'orders-create', 'guard_name' => 'web']);
    Permission::create(['name' => 'orders-read',   'guard_name' => 'web']);

    $component = new PermissionManager;
    $component->delete('orders');
    $component->deleteConfirmText = 'DELETE orders';
    app()->call([$component, 'confirmDeleteGroup']);

    expect(Permission::where('name', 'like', 'orders-%')->count())->toBe(0)
        ->and($component->deletingPrefix)->toBe('');
});

it('PermissionManager confirmDeleteGroup wrong text is no-op', function (): void {
    Permission::create(['name' => 'orders-read', 'guard_name' => 'web']);

    $component = new PermissionManager;
    $component->delete('orders');
    $component->deleteConfirmText = 'wrong';
    app()->call([$component, 'confirmDeleteGroup']);

    expect(Permission::where('name', 'like', 'orders-%')->count())->toBe(1);
});

it('PermissionManager onPermissionSaved resets form', function (): void {
    $component = new PermissionManager;
    $component->showForm = true;
    $component->editingPrefix = 'orders';
    $component->onPermissionSaved();

    expect($component->showForm)->toBeFalse()
        ->and($component->editingPrefix)->toBe('');
});

it('PermissionManager render returns View', function (): void {
    $component = new PermissionManager;

    expect($component->render())->toBeInstanceOf(View::class);
});

// ─── PermissionForm ──────────────────────────────────────────────────────────

it('PermissionForm mount sets defaults for new group', function (): void {
    $component = new PermissionForm;
    app()->call([$component, 'mount']);

    expect($component->prefix)->toBe('')
        ->and($component->editingPrefix)->toBe('')
        ->and($component->guardName)->toBe(config('auth.defaults.guard', 'web'))
        ->and($component->crudAbilities)->toBe([])
        ->and($component->customAbilities)->toBe([]);
});

it('PermissionForm mount loads existing group for edit', function (): void {
    Permission::create(['name' => 'orders-create', 'guard_name' => 'web']);
    Permission::create(['name' => 'orders-export', 'guard_name' => 'web']);

    $component = new PermissionForm;
    app()->call([$component, 'mount'], ['editingPrefix' => 'orders']);

    expect($component->prefix)->toBe('orders')
        ->and($component->guardName)->toBe('web')
        ->and($component->crudAbilities)->toContain('create')
        ->and($component->customAbilities)->toContain('export');
});

it('PermissionForm save sets errorMessage when no abilities selected', function (): void {
    $component = new PermissionForm;
    app()->call([$component, 'mount']);
    $component->prefix = 'orders';

    app()->call([$component, 'save']);

    expect($component->errorMessage)->toBe('Select at least one ability.');
});

it('PermissionForm save sets errorMessage on invalid prefix name', function (): void {
    $component = new PermissionForm;
    app()->call([$component, 'mount']);
    $component->prefix = 'INVALID NAME';
    $component->crudAbilities = ['create'];

    app()->call([$component, 'save']);

    expect($component->errorMessage)->not->toBeNull();
});

it('PermissionForm render returns View', function (): void {
    $component = new PermissionForm;

    expect($component->render())->toBeInstanceOf(View::class);
});

// ─── RoleManager ─────────────────────────────────────────────────────────────

it('RoleManager mount runs without error', function (): void {
    Role::create(['name' => 'admin', 'guard_name' => 'web']);

    $component = new RoleManager;
    app()->call([$component, 'mount']); // no-op; data computed in render()

    expect($component)->toBeInstanceOf(RoleManager::class);
});

it('RoleManager create opens form', function (): void {
    $component = new RoleManager;
    $component->create();

    expect($component->showForm)->toBeTrue()
        ->and($component->editingId)->toBeNull();
});

it('RoleManager edit opens form in edit mode', function (): void {
    $component = new RoleManager;
    $component->edit(7);

    expect($component->editingId)->toBe(7)
        ->and($component->showForm)->toBeTrue();
});

it('RoleManager closeForm resets state', function (): void {
    $component = new RoleManager;
    $component->edit(7);
    $component->closeForm();

    expect($component->showForm)->toBeFalse()
        ->and($component->editingId)->toBeNull();
});

it('RoleManager onRoleSaved resets form and reloads', function (): void {
    $component = new RoleManager;
    $component->showForm = true;
    $component->editingId = 3;
    $component->onRoleSaved();

    expect($component->showForm)->toBeFalse()
        ->and($component->editingId)->toBeNull();
});

it('RoleManager delete stages role for confirmation (T25)', function (): void {
    $role = Role::create(['name' => 'editor', 'guard_name' => 'web']);

    $component = new RoleManager;
    $component->delete($role->id);

    expect($component->deletingRoleId)->toBe($role->id)
        ->and(Role::find($role->id))->not->toBeNull(); // not deleted yet
});

it('RoleManager confirmDelete removes role after correct text', function (): void {
    $role = Role::create(['name' => 'editor', 'guard_name' => 'web']);
    $id   = $role->id;

    $component = new RoleManager;
    $component->delete($id);
    $component->deleteConfirmText = 'DELETE editor';
    app()->call([$component, 'confirmDelete']);

    expect(Role::find($id))->toBeNull()
        ->and($component->deletingRoleId)->toBeNull();
});

it('RoleManager confirmDelete blocks when users are assigned (T25)', function (): void {
    $role = Role::create(['name' => 'editor', 'guard_name' => 'web']);
    \Illuminate\Support\Facades\DB::table('model_has_roles')->insert([
        'role_id' => $role->id, 'model_type' => 'App\\Models\\User', 'model_id' => 1,
    ]);

    $component = new RoleManager;
    $component->delete($role->id);
    $component->deleteConfirmText = 'DELETE editor';
    app()->call([$component, 'confirmDelete']);

    expect(Role::find($role->id))->not->toBeNull(); // blocked
});

it('RoleManager render returns View', function (): void {
    $component = new RoleManager;

    expect($component->render())->toBeInstanceOf(View::class);
});

// ─── RoleForm ────────────────────────────────────────────────────────────────

it('RoleForm mount sets defaults for new role', function (): void {
    $component = new RoleForm;
    app()->call([$component, 'mount']);

    expect($component->name)->toBe('')
        ->and($component->guardName)->toBe(config('auth.defaults.guard', 'web'))
        ->and($component->roleId)->toBeNull();
});

it('RoleForm mount loads existing role for edit', function (): void {
    $role = Role::create(['name' => 'editor', 'guard_name' => 'web']);

    $component = new RoleForm;
    app()->call([$component, 'mount'], ['roleId' => $role->id]);

    expect($component->name)->toBe('editor')
        ->and($component->guardName)->toBe('web');
});

it('RoleForm updatedGuardName reloads permission options', function (): void {
    Permission::create(['name' => 'orders-read', 'guard_name' => 'api']);

    $component = new RoleForm;
    app()->call([$component, 'mount']);
    $component->guardName = 'api';
    $component->updatedGuardName();

    expect($component->permissionOptions)->toHaveCount(1);
});

it('RoleForm render returns View', function (): void {
    $component = new RoleForm;

    expect($component->render())->toBeInstanceOf(View::class);
});

// ─── HierarchyManager ────────────────────────────────────────────────────────

it('HierarchyManager mount loads data', function (): void {
    $component = new HierarchyManager;
    app()->call([$component, 'mount']);

    expect($component->hierarchies)->toBeArray()
        ->and($component->allRoles)->toBeArray()
        ->and($component->orphanRoles)->toBeArray();
});

it('HierarchyManager addRelation with missing roles sets errorMessage', function (): void {
    $component = new HierarchyManager;
    $component->newParentId = null;
    $component->newChildId = null;

    app()->call([$component, 'addRelation']);

    expect($component->errorMessage)->toBe('Select both parent and child roles.');
});

it('HierarchyManager addRelation enforces V3 single-level', function (): void {
    $a = Role::create(['name' => 'a', 'guard_name' => 'web']);
    $b = Role::create(['name' => 'b', 'guard_name' => 'web']);
    $c = Role::create(['name' => 'c', 'guard_name' => 'web']);
    RoleHierarchy::create(['parent_role_id' => $a->id, 'child_role_id' => $b->id]);

    $component = new HierarchyManager;
    $component->newParentId = $b->id;
    $component->newChildId = $c->id;

    app()->call([$component, 'addRelation']);

    expect($component->errorMessage)->not->toBeNull();
});

it('HierarchyManager addRelation success creates relation', function (): void {
    $parent = Role::create(['name' => 'parent', 'guard_name' => 'web']);
    $child  = Role::create(['name' => 'child',  'guard_name' => 'web']);

    $component = new HierarchyManager;
    $component->newParentId = $parent->id;
    $component->newChildId  = $child->id;

    app()->call([$component, 'addRelation']);

    expect($component->errorMessage)->toBeNull()
        ->and($component->showAddRelation)->toBeFalse()
        ->and(RoleHierarchy::count())->toBe(1);
});

it('HierarchyManager removeRelation deletes relation', function (): void {
    $parent = Role::create(['name' => 'parent', 'guard_name' => 'web']);
    $child  = Role::create(['name' => 'child',  'guard_name' => 'web']);
    $rel    = RoleHierarchy::create(['parent_role_id' => $parent->id, 'child_role_id' => $child->id]);

    $component = new HierarchyManager;
    app()->call([$component, 'removeRelation'], ['id' => $rel->id]);

    expect(RoleHierarchy::find($rel->id))->toBeNull();
});

it('HierarchyManager drilldown toggles drilldownId', function (): void {
    $component = new HierarchyManager;
    $component->drilldown(3);
    expect($component->drilldownId)->toBe(3);

    $component->drilldown(3);
    expect($component->drilldownId)->toBeNull();
});

it('HierarchyManager openOverrideForm sets state', function (): void {
    $component = new HierarchyManager;
    $component->openOverrideForm(5);

    expect($component->overrideHierarchyId)->toBe(5)
        ->and($component->showOverrideForm)->toBeTrue()
        ->and($component->overridePermissionId)->toBeNull();
});

it('HierarchyManager addOverride with missing data sets errorMessage', function (): void {
    $component = new HierarchyManager;
    $component->overrideHierarchyId = null;
    $component->overridePermissionId = null;

    app()->call([$component, 'addOverride']);

    expect($component->errorMessage)->toBe('Select a hierarchy and permission.');
});

it('HierarchyManager removeOverride deletes override', function (): void {
    $parent  = Role::create(['name' => 'parent', 'guard_name' => 'web']);
    $child   = Role::create(['name' => 'child',  'guard_name' => 'web']);
    $perm    = Permission::create(['name' => 'orders-read', 'guard_name' => 'web']);
    $parent->givePermissionTo($perm);
    RoleHierarchy::create(['parent_role_id' => $parent->id, 'child_role_id' => $child->id]);
    $override = RolePermissionOverride::create([
        'parent_role_id' => $parent->id,
        'child_role_id'  => $child->id,
        'permission_id'  => $perm->id,
        'type'           => RolePermissionOverride::TYPE_FORCED_ON,
    ]);

    $component = new HierarchyManager;
    app()->call([$component, 'removeOverride'], ['overrideId' => $override->id]);

    expect(RolePermissionOverride::find($override->id))->toBeNull();
});

it('HierarchyManager render returns View', function (): void {
    $component = new HierarchyManager;

    expect($component->render())->toBeInstanceOf(View::class);
});

// ─── UserRoleManager ─────────────────────────────────────────────────────────

it('UserRoleManager mount with no auth user does not crash', function (): void {
    $component = new UserRoleManager;
    app()->call([$component, 'mount']);

    expect($component->assignments)->toBeArray()
        ->and($component->scopeContextType)->toBeNull();
});

it('UserRoleManager assign with missing fields sets errorMessage', function (): void {
    $component = new UserRoleManager;
    $component->assignUserId = null;

    $component->assign();

    expect($component->errorMessage)->toBe('All fields are required.');
});

it('UserRoleManager assign blocked outside manager scope (V4)', function (): void {
    $component = new UserRoleManager;
    $component->scopeContextType = 'App\\Models\\Store';
    $component->scopeContextId = 1;
    $component->assignUserId = 99;
    $component->assignRoleId = 1;
    $component->assignContextType = 'App\\Models\\OtherModel';
    $component->assignContextId = 1;

    $component->assign();

    expect($component->errorMessage)->toBe('Cannot assign outside your managed context.');
});

it('UserRoleManager revoke with non-existent id is a no-op', function (): void {
    $component = new UserRoleManager;
    $component->revoke(9999);

    expect($component->errorMessage)->toBeNull();
});

it('UserRoleManager revoke blocked outside manager scope (V4)', function (): void {
    $role       = Role::create(['name' => 'staff', 'guard_name' => 'web']);
    $assignment = UserRoleContext::create([
        'user_id' => 5, 'role_id' => $role->id,
        'context_type' => 'App\\Models\\OtherModel', 'context_id' => 99,
    ]);

    $component = new UserRoleManager;
    $component->scopeContextType = 'App\\Models\\Store';
    $component->scopeContextId = 1;

    $component->revoke($assignment->id);

    expect($component->errorMessage)->toBe('Cannot revoke outside your managed context.')
        ->and(UserRoleContext::find($assignment->id))->not->toBeNull();
});

it('UserRoleManager assign creates UserRoleContext record', function (): void {
    $role = Role::create(['name' => 'staff', 'guard_name' => 'web']);

    $component = new UserRoleManager;
    app()->call([$component, 'mount']);
    $component->assignUserId = 42;
    $component->assignRoleId = $role->id;
    $component->assignContextType = 'App\\Models\\Store';
    $component->assignContextId = 1;

    $component->assign();

    expect(UserRoleContext::where('user_id', 42)->exists())->toBeTrue()
        ->and($component->errorMessage)->toBeNull();
});

it('UserRoleManager revoke deletes UserRoleContext record', function (): void {
    $role       = Role::create(['name' => 'staff', 'guard_name' => 'web']);
    $assignment = UserRoleContext::create([
        'user_id' => 5, 'role_id' => $role->id,
        'context_type' => 'App\\Models\\Store', 'context_id' => 1,
    ]);

    $component = new UserRoleManager;
    app()->call([$component, 'mount']);
    $component->revoke($assignment->id);

    expect(UserRoleContext::find($assignment->id))->toBeNull();
});

it('UserRoleManager render returns View', function (): void {
    $component = new UserRoleManager;

    expect($component->render())->toBeInstanceOf(View::class);
});
