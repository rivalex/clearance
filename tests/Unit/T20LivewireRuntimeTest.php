<?php

declare(strict_types=1);

use Illuminate\View\View;
use Rivalex\Clearance\Livewire\Guards\GuardManager;
use Rivalex\Clearance\Livewire\Permissions\PermissionForm;
use Rivalex\Clearance\Livewire\Permissions\DeletePermission;
use Rivalex\Clearance\Livewire\Permissions\EditPermission;
use Rivalex\Clearance\Livewire\Permissions\NewPermission;
use Rivalex\Clearance\Livewire\Permissions\PermissionManager;
use Rivalex\Clearance\Livewire\Roles\DeleteRole;
use Rivalex\Clearance\Livewire\Roles\EditRole;
use Rivalex\Clearance\Livewire\Roles\NewRole;
use Rivalex\Clearance\Livewire\Roles\RoleForm;
use Rivalex\Clearance\Livewire\Roles\RoleManager;
use Rivalex\Clearance\Livewire\Users\UserClearanceManager;
use Rivalex\Clearance\Livewire\Users\AssignRoleModal;
use Rivalex\Clearance\Livewire\Users\RemoveAssignmentModal;
use Rivalex\Clearance\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $this->runMigrations();
});

// ─── GuardManager ────────────────────────────────────────────────────────────

it('GuardManager mount runs without error', function (): void {
    $component = new GuardManager;
    app()->call([$component, 'mount']);

    expect($component)->toBeInstanceOf(GuardManager::class);
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


it('NewPermission showModal defaults to false', function (): void {
    $component = new NewPermission;

    expect($component->showModal)->toBeFalse();
});

it('NewPermission onPermissionSaved closes modal', function (): void {
    $component = new NewPermission;
    $component->showModal = true;
    $component->onPermissionSaved();

    expect($component->showModal)->toBeFalse();
});

it('EditPermission stores prefix and sets modalName in mount', function (): void {
    $component = new EditPermission;
    $component->prefix = 'orders';
    $component->guard = 'web';
    $component->groupKey = 'testkey';
    app()->call([$component, 'mount']);

    expect($component->prefix)->toBe('orders')
        ->and($component->modalName)->toBe('edit-permission-testkey');
});

it('DeletePermission confirmDelete removes group on correct text (T25)', function (): void {
    Permission::create(['name' => 'orders-create', 'guard_name' => 'web']);
    Permission::create(['name' => 'orders-read',   'guard_name' => 'web']);

    $component = new DeletePermission;
    $component->prefix = 'orders';
    $component->guard = 'web';
    $component->groupKey = md5('orders|web');
    $component->confirmText = 'DELETE orders';
    app()->call([$component, 'confirmDelete']);

    expect(Permission::where('name', 'like', 'orders-%')->count())->toBe(0);
});

it('DeletePermission confirmDelete is no-op on wrong text', function (): void {
    Permission::create(['name' => 'orders-read', 'guard_name' => 'web']);

    $component = new DeletePermission;
    $component->prefix = 'orders';
    $component->confirmText = 'wrong';
    app()->call([$component, 'confirmDelete']);

    expect(Permission::where('name', 'like', 'orders-%')->count())->toBe(1);
});

it('PermissionManager render returns View', function (): void {
    Permission::create(['name' => 'orders-read', 'guard_name' => 'web']);
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

it('NewRole showModal defaults to false', function (): void {
    $component = new NewRole;

    expect($component->showModal)->toBeFalse();
});

it('NewRole onRoleSaved closes modal', function (): void {
    $component = new NewRole;
    $component->showModal = true;
    $component->onRoleSaved();

    expect($component->showModal)->toBeFalse();
});

it('EditRole sets modalName in mount', function (): void {
    $component = new EditRole;
    $component->roleId = 7;
    app()->call([$component, 'mount']);

    expect($component->roleId)->toBe(7)
        ->and($component->modalName)->toBe('edit-role-7');
});

it('DeleteRole confirmDelete removes role on correct text (T25)', function (): void {
    $role = Role::create(['name' => 'editor', 'guard_name' => 'web']);
    $id   = $role->id;

    $component = new DeleteRole;
    $component->roleId = $id;
    $component->confirmText = 'DELETE editor';
    app()->call([$component, 'mount']);
    app()->call([$component, 'confirmDelete']);

    expect(Role::find($id))->toBeNull();
});

it('DeleteRole confirmDelete detaches users and deletes role (T25)', function (): void {
    $role = Role::create(['name' => 'editor', 'guard_name' => 'web']);
    \Illuminate\Support\Facades\DB::table('model_has_roles')->insert([
        'role_id' => $role->id, 'model_type' => 'App\\Models\\User', 'model_id' => 1,
    ]);

    $component = new DeleteRole;
    $component->roleId    = $role->id;
    $component->action    = 'detach';
    $component->confirmText = 'DELETE editor';
    app()->call([$component, 'mount']);
    app()->call([$component, 'confirmDelete']);

    // Users detached (model_has_roles cleared), role deleted
    expect(Role::find($role->id))->toBeNull();
    expect(\Illuminate\Support\Facades\DB::table('model_has_roles')
        ->where('role_id', $role->id)->count())->toBe(0);
});

it('DeleteRole confirmDelete reassign blocks without targetRoleId (T25b)', function (): void {
    $role = Role::create(['name' => 'editor', 'guard_name' => 'web']);
    \Illuminate\Support\Facades\DB::table('model_has_roles')->insert([
        'role_id' => $role->id, 'model_type' => 'App\\Models\\User', 'model_id' => 1,
    ]);

    $component = new DeleteRole;
    $component->roleId      = $role->id;
    $component->action      = 'reassign';
    $component->targetRoleId = null;
    $component->confirmText = 'DELETE editor';
    app()->call([$component, 'mount']);
    app()->call([$component, 'confirmDelete']);

    // Blocked: no targetRoleId provided
    expect(Role::find($role->id))->not->toBeNull();
    expect($component->errorMessage)->not->toBeNull();
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

it('RoleForm updatedGuardName reloads permission groups', function (): void {
    Permission::create(['name' => 'orders-read', 'guard_name' => 'api']);

    $component = new RoleForm;
    app()->call([$component, 'mount']);
    $component->guardName = 'api';
    $component->updatedGuardName();

    expect($component->permissionGroups)->toHaveCount(1);
});

it('RoleForm render returns View', function (): void {
    $component = new RoleForm;

    expect($component->render())->toBeInstanceOf(View::class);
});

// ─── UserClearanceManager ────────────────────────────────────────────────────

it('UserClearanceManager has required properties', function (): void {
    expect(class_exists(UserClearanceManager::class))->toBeTrue();
    expect(method_exists(UserClearanceManager::class, 'mount'))->toBeTrue();
    expect(method_exists(UserClearanceManager::class, 'placeholder'))->toBeTrue();
});

it('AssignRoleModal has required structure', function (): void {
    expect(class_exists(AssignRoleModal::class))->toBeTrue();
    expect(method_exists(AssignRoleModal::class, 'save'))->toBeTrue();
    expect(method_exists(AssignRoleModal::class, 'rules'))->toBeTrue();
});

it('RemoveAssignmentModal has required structure', function (): void {
    expect(class_exists(RemoveAssignmentModal::class))->toBeTrue();
    expect(method_exists(RemoveAssignmentModal::class, 'confirmDelete'))->toBeTrue();
});
