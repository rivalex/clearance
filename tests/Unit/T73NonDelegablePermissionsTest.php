<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Rivalex\Clearance\Exceptions\ClearanceConfigException;
use Rivalex\Clearance\Exceptions\ClearanceProtectedResourceException;
use Rivalex\Clearance\Livewire\Settings\BulkAssignDefaultRole;
use Rivalex\Clearance\Livewire\Users\AssignRoleModal;
use Rivalex\Clearance\Models\ClearanceSettings;
use Rivalex\Clearance\Models\UserContextPermissionOverride;
use Rivalex\Clearance\Models\UserRoleContext;
use Rivalex\Clearance\Services\PermissionService;
use Rivalex\Clearance\Services\RoleService;
use Rivalex\Clearance\Services\UserClearanceService;
use Rivalex\Clearance\Tests\Support\FakeEloquentUser;
use Rivalex\Clearance\Tests\Support\FakeHierarchicalContext;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $this->runMigrations();

    Schema::create('fake_hierarchical_contexts', static function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('parent_id')->nullable();
    });

    Schema::create('fake_users', static function ($table): void {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->string('password');
        $table->timestamps();
    });

    config()->set('clearance.user_model', FakeEloquentUser::class);
    config()->set('clearance.non_delegable', ['team-assign_permissions', 'team-invite', 'billing-*', 'company-delete', 'company-transfer']);

    $this->service = new UserClearanceService;
    $this->company = FakeHierarchicalContext::create(['parent_id' => null]);
    $this->store = FakeHierarchicalContext::create(['parent_id' => $this->company->id]);
    $this->staffRole = Role::create(['name' => 'staff', 'guard_name' => 'web']);
});

function t73User(string $email): FakeEloquentUser
{
    return FakeEloquentUser::create(['name' => $email, 'email' => $email, 'password' => 'x']);
}

function t73Role(string $name, string ...$permissions): Role
{
    $role = Role::create(['name' => $name, 'guard_name' => 'web']);

    foreach ($permissions as $permission) {
        $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    return $role;
}

/** A manager holding the given permissions through a role bound on the company. */
function t73Manager(FakeHierarchicalContext $company, string ...$permissions): FakeEloquentUser
{
    $manager = t73User('manager@example.com');
    UserRoleContext::create([
        'user_id' => $manager->id,
        'role_id' => t73Role('manager', ...$permissions)->id,
        'context_type' => FakeHierarchicalContext::class,
        'context_id' => $company->id,
    ]);

    return $manager;
}

function t73SuperAdmin(): FakeEloquentUser
{
    $admin = t73User('admin@example.com');
    $admin->assignRole(Role::create(['name' => 'super_admin', 'guard_name' => 'web']));

    return $admin;
}

it('classifies exact names, patterns and non-matches', function (): void {
    $permissions = app(PermissionService::class);

    expect($permissions->isDelegable('team-invite'))->toBeFalse()
        ->and($permissions->isDelegable('billing-update'))->toBeFalse()
        ->and($permissions->isDelegable('billingx-update'))->toBeTrue()
        ->and($permissions->isDelegable('team-read'))->toBeTrue();
});

it('honours the underscore separator for group patterns', function (): void {
    config()->set('clearance.naming_separator', '_');
    config()->set('clearance.non_delegable', ['billing_*']);
    $permissions = app(PermissionService::class);

    expect($permissions->isDelegable('billing_update'))->toBeFalse()
        ->and($permissions->isDelegable('billing-update'))->toBeTrue();
});

it('throws on malformed non_delegable entries', function (string $entry): void {
    config()->set('clearance.non_delegable', [$entry]);

    expect(fn () => app(PermissionService::class)->isDelegable('team-read'))
        ->toThrow(ClearanceConfigException::class);
})->with(['bill*', '*-update', 'bill*ing-*', '*']);

it('rejects the three grant paths for a manager holding a non-delegable permission', function (): void {
    $manager = t73Manager($this->company, 'team-assign_permissions');
    $staff = t73User('staff@example.com');
    $perm = Permission::findOrCreate('team-assign_permissions', 'web');

    expect(fn () => $this->service->syncGlobalExtraPermissions($manager, $staff, $this->staffRole, [$perm->id]))
        ->toThrow(ClearanceProtectedResourceException::class);
    expect(fn () => $this->service->syncContextualExtraPermissions($manager, $staff, $this->staffRole, $this->store, [$perm->id]))
        ->toThrow(ClearanceProtectedResourceException::class);
    expect(fn () => $this->service->assignContextualAs($manager, $staff, t73Role('assigner', 'team-assign_permissions'), $this->store))
        ->toThrow(ClearanceProtectedResourceException::class);

    expect($staff->fresh()->hasDirectPermission('team-assign_permissions'))->toBeFalse()
        ->and(UserContextPermissionOverride::count())->toBe(0)
        ->and(UserRoleContext::where('user_id', $staff->id)->count())->toBe(0);
});

it('applies the same three rejections to super_admin', function (): void {
    $admin = t73SuperAdmin();
    $staff = t73User('staff@example.com');
    $perm = Permission::findOrCreate('team-assign_permissions', 'web');

    expect(fn () => $this->service->syncGlobalExtraPermissions($admin, $staff, $this->staffRole, [$perm->id]))
        ->toThrow(ClearanceProtectedResourceException::class);
    expect(fn () => $this->service->syncContextualExtraPermissions($admin, $staff, $this->staffRole, $this->store, [$perm->id]))
        ->toThrow(ClearanceProtectedResourceException::class);
    expect(fn () => $this->service->assignContextualAs($admin, $staff, t73Role('assigner', 'team-assign_permissions'), $this->store))
        ->toThrow(ClearanceProtectedResourceException::class);

    expect($staff->fresh()->hasDirectPermission('team-assign_permissions'))->toBeFalse()
        ->and(UserContextPermissionOverride::count())->toBe(0)
        ->and(UserRoleContext::where('user_id', $staff->id)->count())->toBe(0);
});

it('refuses a role with a non-delegable permission on the actor-checked paths but not on the trusted ones', function (): void {
    $admin = t73SuperAdmin();
    $staff = t73User('staff@example.com');
    $billing = t73Role('billing-admin', 'billing-update');

    expect(fn () => $this->service->assignGlobalRoleAs($admin, $staff, $billing))
        ->toThrow(ClearanceProtectedResourceException::class);
    expect(fn () => $this->service->assignContextualAs($admin, $staff, $billing, $this->store))
        ->toThrow(ClearanceProtectedResourceException::class);

    $this->service->assignGlobalRole($staff, $billing);
    $this->service->assignContextual($staff, $billing, $this->store);

    expect($staff->fresh()->hasRole('billing-admin'))->toBeTrue()
        ->and(UserRoleContext::where('user_id', $staff->id)->count())->toBe(1);
});

it('still assigns a role without non-delegable permissions within the ceiling', function (): void {
    $manager = t73Manager($this->company, 'orders-read');
    $staff = t73User('staff@example.com');

    $this->service->assignContextualAs($manager, $staff, t73Role('clerk', 'orders-read'), $this->store);

    expect(UserRoleContext::where('user_id', $staff->id)->count())->toBe(1);
});

it('never restricts revocation', function (): void {
    $staff = t73User('staff@example.com');
    $billing = t73Role('billing-admin', 'billing-update');
    $this->service->assignGlobalRole($staff, $billing);
    $this->service->assignContextual($staff, $billing, $this->store);

    $this->service->removeContextual($staff, $billing, $this->store);
    $this->service->removeGlobalRole($staff, $billing);

    expect(UserRoleContext::where('user_id', $staff->id)->count())->toBe(0)
        ->and($staff->fresh()->hasRole('billing-admin'))->toBeFalse();

    $admin = t73SuperAdmin();
    $perm = Permission::findOrCreate('company-delete', 'web');
    $staff->givePermissionTo($perm);
    $staff->assignRole($this->staffRole);

    $this->service->syncGlobalExtraPermissions($admin, $staff, $this->staffRole, []);

    expect($staff->fresh()->hasDirectPermission('company-delete'))->toBeFalse();
});

it('lets super_admin add a non-delegable permission to a role through RoleService', function (): void {
    $admin = t73SuperAdmin();
    $role = t73Role('plain');
    $perm = Permission::findOrCreate('billing-update', 'web');

    app(RoleService::class)->syncPermissions($admin, $role, [$perm]);

    expect($role->fresh()->hasPermissionTo('billing-update'))->toBeTrue();
});

it('behaves as before when non_delegable is empty', function (): void {
    config()->set('clearance.non_delegable', []);
    $actor = t73User('actor@example.com');
    $staff = t73User('staff@example.com');
    $perm = Permission::findOrCreate('billing-update', 'web');
    $actor->givePermissionTo($perm);

    $this->service->syncGlobalExtraPermissions($actor, $staff, $this->staffRole, [$perm->id]);

    expect($staff->fresh()->hasDirectPermission('billing-update'))->toBeTrue();
});

it('does not list a system-assigned-only role in the assign modal', function (): void {
    config()->set('clearance.modules.users', true);
    $plain = t73Role('plain', 'orders-read');
    $billing = t73Role('billing-admin', 'billing-update');
    $target = t73User('target@example.com');

    foreach (['global', 'contextual'] as $scope) {
        config()->set('clearance.contextual_models', [FakeHierarchicalContext::class => ['label' => 'Store']]);

        Livewire::test(AssignRoleModal::class, [
            'userId' => $target->id,
            'scope' => $scope,
            'contextClass' => $scope === 'contextual' ? FakeHierarchicalContext::class : '',
        ])->assertViewHas('availableRoles', function (array $roles) use ($plain, $billing): bool {
            $ids = collect($roles)->pluck('id')->all();

            return in_array($plain->id, $ids, true) && ! in_array($billing->id, $ids, true);
        });
    }
});

it('assigns nobody and shows the new message when the default role is system-assigned only', function (): void {
    $billingAdmin = t73Role('billing-admin', 'billing-update');
    ClearanceSettings::set('default_role_id', (string) $billingAdmin->id);
    $user = t73User('someone@example.com');

    Livewire::test(BulkAssignDefaultRole::class)
        ->call('bulkAssignDefaultRole')
        ->assertSet('bulkSuccess', false)
        ->assertSet('bulkMessage', __('clearance::ui.settings.bulk_role_system_only'));

    expect($user->fresh()->hasRole('billing-admin'))->toBeFalse();
});
