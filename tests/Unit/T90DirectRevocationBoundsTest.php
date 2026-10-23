<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Rivalex\Clearance\Exceptions\ClearanceProtectedResourceException;
use Rivalex\Clearance\Exceptions\ClearanceScopeViolationException;
use Rivalex\Clearance\Livewire\Users\GlobalRolesPanel;
use Rivalex\Clearance\Models\UserContextPermissionOverride;
use Rivalex\Clearance\Models\UserRoleContext;
use Rivalex\Clearance\Services\UserClearanceService;
use Rivalex\Clearance\Tests\Support\FakeEloquentUser;
use Rivalex\Clearance\Tests\Support\FakeHierarchicalContext;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

// Revoking direct permissions and removing a deny-carrying binding follow the removal rules.
// Each case is arranged so that only ONE check can refuse.

beforeEach(function (): void {
    $this->runMigrations();

    Schema::create('fake_hierarchical_contexts', static function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('parent_id')->nullable();
    });

    Schema::create('fake_users', static function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->string('password');
        $table->timestamps();
    });

    config()->set('clearance.user_model', FakeEloquentUser::class);

    $this->service = new UserClearanceService;
    $this->context = FakeHierarchicalContext::create(['parent_id' => null]);
});

function t90User(string $email): FakeEloquentUser
{
    return FakeEloquentUser::create(['name' => $email, 'email' => $email, 'password' => 'x']);
}

function t90Role(string $name, string ...$permissions): Role
{
    $role = Role::create(['name' => $name, 'guard_name' => 'web']);

    foreach ($permissions as $permission) {
        $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    return $role;
}

function t90SuperAdmin(string $email): FakeEloquentUser
{
    $user = t90User($email);
    $user->assignRole(Role::findOrCreate('super_admin', 'web'));
    $user->givePermissionTo(Permission::findOrCreate('clearance-access', 'web'));

    return $user;
}

/** A non-super_admin actor holding the given permissions directly. */
function t90Actor(string ...$permissions): FakeEloquentUser
{
    $actor = t90User('actor@example.com');

    foreach ($permissions as $permission) {
        $actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    return $actor;
}

function t90Direct(FakeEloquentUser $user): array
{
    return $user->fresh()->permissions()->where('guard_name', 'web')->pluck('name')->sort()->values()->all();
}

it('refuses an empty sync on a role the target does not hold, even for a super_admin, and keeps the direct permissions', function (): void {
    $root = t90SuperAdmin('root@example.com');
    $target = t90User('target@example.com');
    $target->givePermissionTo(Permission::findOrCreate('orders-read', 'web'));
    $clerk = t90Role('clerk');

    expect(fn () => $this->service->syncGlobalExtraPermissions($root, $target, $clerk, []))
        ->toThrow(ClearanceScopeViolationException::class, 'User does not hold role [clerk].');

    expect(t90Direct($target))->toBe(['orders-read']);
});

it('refuses a non super_admin actor revoking direct permissions of a super_admin target', function (): void {
    $actor = t90Actor('orders-read');
    $target = t90SuperAdmin('root@example.com');
    $clerk = t90Role('clerk');
    $target->assignRole($clerk);
    $target->givePermissionTo(Permission::findOrCreate('orders-read', 'web'));
    $keep = Permission::findOrCreate('clearance-access', 'web');

    expect(fn () => $this->service->syncGlobalExtraPermissions($actor, $target, $clerk, [$keep->id]))
        ->toThrow(ClearanceScopeViolationException::class, 'Only super_admin may change the roles of a super_admin.');

    expect(t90Direct($target))->toContain('orders-read');
});

it('refuses revoking a direct permission the actor does not hold', function (): void {
    $actor = t90Actor('orders-read');
    $target = t90User('target@example.com');
    $clerk = t90Role('clerk');
    $target->assignRole($clerk);
    $target->givePermissionTo(Permission::findOrCreate('orders-delete', 'web'));

    expect(fn () => $this->service->syncGlobalExtraPermissions($actor, $target, $clerk, []))
        ->toThrow(ClearanceScopeViolationException::class, 'that you do not hold');

    expect(t90Direct($target))->toBe(['orders-delete']);
});

it('revokes a direct permission within bounds', function (): void {
    $actor = t90Actor('orders-delete');
    $target = t90User('target@example.com');
    $clerk = t90Role('clerk');
    $target->assignRole($clerk);
    $target->givePermissionTo(Permission::findOrCreate('orders-delete', 'web'));

    $this->service->syncGlobalExtraPermissions($actor, $target, $clerk, []);

    expect(t90Direct($target))->toBe([]);
});

it('refuses a manager removing a binding that carries forced_off and lets a super_admin do it', function (): void {
    $manager = t90Actor('orders-read');
    $clerk = t90Role('clerk', 'orders-read');
    $staff = t90User('staff@example.com');

    UserRoleContext::create([
        'user_id' => $staff->id,
        'role_id' => $clerk->id,
        'context_type' => FakeHierarchicalContext::class,
        'context_id' => $this->context->id,
    ]);
    UserContextPermissionOverride::create([
        'user_id' => $staff->id,
        'role_id' => $clerk->id,
        'permission_id' => Permission::findOrCreate('orders-read', 'web')->id,
        'context_type' => FakeHierarchicalContext::class,
        'context_id' => $this->context->id,
        'type' => UserContextPermissionOverride::TYPE_FORCED_OFF,
    ]);

    expect(fn () => $this->service->removeContextualAs($manager, $staff, $clerk, $this->context))
        ->toThrow(ClearanceProtectedResourceException::class, 'only super_admin can remove it');

    expect(UserRoleContext::where('role_id', $clerk->id)->count())->toBe(1)
        ->and(UserContextPermissionOverride::where('role_id', $clerk->id)->count())->toBe(1);

    $this->service->removeContextualAs(t90SuperAdmin('root@example.com'), $staff, $clerk, $this->context);

    expect(UserRoleContext::where('role_id', $clerk->id)->count())->toBe(0)
        ->and(UserContextPermissionOverride::where('role_id', $clerk->id)->count())->toBe(0);
});

it('refuses a non super_admin actor reassigning a role held globally by a super_admin and changes nothing', function (): void {
    $actor = t90Actor('orders-read');
    $from = t90Role('clerk', 'orders-read');
    $to = t90Role('auditor', 'orders-read');
    $root = t90SuperAdmin('root@example.com');
    $root->assignRole($from);

    expect(fn () => $this->service->reassignRoleHoldersAs($actor, $from, $to))
        ->toThrow(ClearanceScopeViolationException::class, 'Only super_admin may change the roles of a super_admin.');

    expect($root->fresh()->hasRole('clerk'))->toBeTrue()
        ->and($root->fresh()->hasRole('auditor'))->toBeFalse();
});

it('aborts saveExtraPerms for a role the target does not hold and writes nothing', function (): void {
    Livewire::withoutLazyLoading();
    config()->set('clearance.modules.users', true);

    $root = t90SuperAdmin('root@example.com');
    $target = t90User('target@example.com');
    $perm = Permission::findOrCreate('orders-read', 'web');
    $clerk = t90Role('clerk');

    Livewire::actingAs($root)->test(GlobalRolesPanel::class, ['userId' => $target->id])
        ->set("manualPermissions.{$clerk->id}.{$perm->id}", true)
        ->call('saveExtraPerms', $clerk->id)
        ->assertForbidden();

    expect(t90Direct($target))->toBe([]);
});
