<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Rivalex\Clearance\Exceptions\ClearanceProtectedResourceException;
use Rivalex\Clearance\Exceptions\ClearanceScopeViolationException;
use Rivalex\Clearance\Livewire\Roles\RoleForm;
use Rivalex\Clearance\Models\Permission as ClearancePermission;
use Rivalex\Clearance\Services\PermissionService;
use Rivalex\Clearance\Services\RoleService;
use Rivalex\Clearance\Services\UserClearanceService;
use Rivalex\Clearance\Support\ErrorMessage;
use Rivalex\Clearance\Tests\Support\FakeEloquentUser;
use Spatie\Permission\Models\Role;

// The super_admin role is changed only by a super_admin, and the configured access permission is
// protected exactly like the built-in clearance-* permissions.

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
    config()->set('clearance.access_permission', 'panel-open');

    $names = [
        'panel-open',
        'clearance-roles-write',
        'clearance-users-write',
        'clearance-permissions-write',
        'clearance-guards-write',
        'clearance-settings-write',
        'reports-read',
    ];

    foreach ($names as $name) {
        ClearancePermission::create(['name' => $name, 'guard_name' => 'web']);
    }

    $this->superAdminRole = Role::create(['name' => 'super_admin', 'guard_name' => 'web']);
    $this->superAdminRole->syncPermissions($names);

    $this->writer = t102User('writer@example.com');
    $this->writer->givePermissionTo('panel-open', 'clearance-roles-write');

    $this->superAdmin = t102User('root@example.com');
    $this->superAdmin->assignRole($this->superAdminRole);
});

function t102User(string $email): FakeEloquentUser
{
    return FakeEloquentUser::create(['name' => $email, 'email' => $email, 'password' => 'x']);
}

function t102Permission(string $name): ClearancePermission
{
    return ClearancePermission::where('name', $name)->where('guard_name', 'web')->firstOrFail();
}

/** Every permission of the super_admin role except the named ones. */
function t102Except(array $except): array
{
    return t102Role()->permissions
        ->reject(fn ($permission) => in_array($permission->name, $except, true))
        ->map(fn ($permission) => t102Permission($permission->name))
        ->values()
        ->all();
}

function t102Role(): Role
{
    return Role::where('name', 'super_admin')->where('guard_name', 'web')->firstOrFail()->fresh();
}

/** Component path of the ability flags for a permission name inside the RoleForm state. */
function t102Path(mixed $component, string $permission): string
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

// T102.a
it('opens the super_admin role read-only for a roles writer and refuses to save it', function (): void {
    $component = Livewire::actingAs($this->writer)->test(RoleForm::class, ['roleId' => t102Role()->id]);

    $component->assertSet('readOnly', true);

    $path = t102Path($component, 'panel-open');

    $component->set("{$path}.selected", false)
        ->set("{$path}.locked", false)
        ->call('save')
        ->assertSet('errorMessage', __('clearance::ui.errors.super_admin_role_change'));

    expect(t102Role()->permissions->pluck('name')->all())->toContain('panel-open');
});

// T102.b
it('opens the super_admin role editable for a super_admin and keeps the access permission locked', function (): void {
    $component = Livewire::actingAs($this->superAdmin)->test(RoleForm::class, ['roleId' => t102Role()->id]);

    $component->assertSet('readOnly', false);

    $path = t102Path($component, 'panel-open');

    expect($component->get("{$path}.locked"))->toBeTrue();
});

// T102.c
it('does not hide the save button on a role that is not super_admin', function (): void {
    $role = Role::create(['name' => 'staff', 'guard_name' => 'web']);

    Livewire::actingAs($this->writer)->test(RoleForm::class, ['roleId' => $role->id])
        ->assertSet('readOnly', false);
});

// T102.d
it('refuses a roles writer any change to the super_admin role at service level', function (): void {
    $service = app(RoleService::class);

    expect(fn () => $service->syncPermissions($this->writer, t102Role(), t102Except(['reports-read'])))
        ->toThrow(ClearanceScopeViolationException::class, 'Only super_admin may change the super_admin role.');

    expect(t102Role()->permissions->pluck('name')->all())->toContain('reports-read');
});

// T102.e
it('treats an unchanged permission set on the super_admin role as no change', function (): void {
    $service = app(RoleService::class);

    $service->syncPermissions($this->writer, t102Role(), t102Except([]));

    expect(t102Role()->permissions)->toHaveCount(7);
});

// T102.f
it('refuses to add the custom access permission to a role', function (): void {
    $role = Role::create(['name' => 'staff', 'guard_name' => 'web']);

    expect(fn () => app(RoleService::class)->syncPermissions($this->writer, $role, [t102Permission('panel-open')]))
        ->toThrow(ClearanceProtectedResourceException::class);

    expect($role->fresh()->permissions)->toHaveCount(0);
});

// T102.g
it('refuses to grant the custom access permission directly to another user', function (): void {
    $role = Role::create(['name' => 'staff', 'guard_name' => 'web']);
    $target = t102User('target@example.com');
    $target->assignRole($role);

    expect(fn () => app(UserClearanceService::class)->syncGlobalExtraPermissions(
        $this->writer,
        $target,
        $role,
        [t102Permission('panel-open')->id],
    ))->toThrow(ClearanceProtectedResourceException::class);

    expect($target->fresh()->getDirectPermissions())->toHaveCount(0);
});

// T102.h
it('lets a super_admin remove a host permission but not the custom access permission from super_admin', function (): void {
    $service = app(RoleService::class);

    $service->syncPermissions($this->superAdmin, t102Role(), t102Except(['reports-read']));

    expect(t102Role()->permissions->pluck('name')->all())->not->toContain('reports-read');

    // A super_admin cannot remove the custom access permission from super_admin.
    expect(fn () => $service->syncPermissions($this->superAdmin, t102Role(), t102Except(['panel-open'])))
        ->toThrow(ClearanceProtectedResourceException::class);

    expect(t102Role()->permissions->pluck('name')->all())->toContain('panel-open');
});

// T102.i
it('maps the super_admin role refusal to its translated message', function (): void {
    $exception = new ClearanceScopeViolationException('Only super_admin may change the super_admin role.');

    expect(ErrorMessage::for($exception))->toBe(__('clearance::ui.errors.super_admin_role_change'));
});

// T102.j
it('refuses to rename a non-delegable permission while it has holders', function (): void {
    config()->set('clearance.non_delegable', ['billing-update']);

    $billing = ClearancePermission::create(['name' => 'billing-update', 'guard_name' => 'web']);
    $service = app(PermissionService::class);

    $role = Role::create(['name' => 'staff', 'guard_name' => 'web']);
    $role->givePermissionTo($billing);

    expect(fn () => $service->rename($billing, 'billing-edit'))
        ->toThrow(ClearanceProtectedResourceException::class, 'non-delegable and cannot be renamed while it has holders');

    expect($billing->fresh()->name)->toBe('billing-update');
});

// T102.k
it('renames a non-delegable permission that has no holders', function (): void {
    config()->set('clearance.non_delegable', ['billing-update']);

    $billing = ClearancePermission::create(['name' => 'billing-update', 'guard_name' => 'web']);

    $renamed = app(PermissionService::class)->rename($billing, 'billing-edit');

    expect($renamed->name)->toBe('billing-edit');
});
