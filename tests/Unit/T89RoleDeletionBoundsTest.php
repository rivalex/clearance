<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Rivalex\Clearance\Exceptions\ClearanceProtectedResourceException;
use Rivalex\Clearance\Exceptions\ClearanceScopeViolationException;
use Rivalex\Clearance\Livewire\Roles\DeleteRole;
use Rivalex\Clearance\Models\RoleMeta;
use Rivalex\Clearance\Models\UserContextPermissionOverride;
use Rivalex\Clearance\Models\UserRoleContext;
use Rivalex\Clearance\Services\ContextService;
use Rivalex\Clearance\Services\UserClearanceService;
use Rivalex\Clearance\Tests\Support\FakeEloquentUser;
use Rivalex\Clearance\Tests\Support\FakeHierarchicalContext;
use Rivalex\Clearance\Tests\Support\T89ScopedUser;
use Rivalex\Clearance\Tests\Support\T89SoftDeletingUser;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

// Role deletion removes holders only through the removal rules, keeps denies, and never
// lets a global scope or soft deletion make a real holder look like an orphan.

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

    Schema::create('t89_soft_users', static function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->string('password');
        $table->timestamps();
        $table->softDeletes();
    });

    config()->set('clearance.user_model', FakeEloquentUser::class);

    $this->service = new UserClearanceService;
    $this->context = FakeHierarchicalContext::create(['parent_id' => null]);
});

function t89User(string $email): FakeEloquentUser
{
    return FakeEloquentUser::create(['name' => $email, 'email' => $email, 'password' => 'x']);
}

function t89Role(string $name, string ...$permissions): Role
{
    $role = Role::create(['name' => $name, 'guard_name' => 'web']);

    foreach ($permissions as $permission) {
        $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    return $role;
}

/** A non-super_admin actor holding clearance-access and orders-read. */
function t89Manager(): FakeEloquentUser
{
    $manager = t89User('manager@example.com');
    $manager->givePermissionTo(Permission::findOrCreate('clearance-access', 'web'));
    $manager->givePermissionTo(Permission::findOrCreate('orders-read', 'web'));

    return $manager;
}

function t89SuperAdminRole(): Role
{
    return Role::findOrCreate('super_admin', 'web');
}

function t89SuperAdmin(string $email = 'root@example.com'): FakeEloquentUser
{
    $user = t89User($email);
    $user->assignRole(t89SuperAdminRole());
    $user->givePermissionTo(Permission::findOrCreate('clearance-access', 'web'));

    return $user;
}

function t89Bind(FakeEloquentUser $user, Role $role, FakeHierarchicalContext $context): void
{
    UserRoleContext::create([
        'user_id' => $user->id,
        'role_id' => $role->id,
        'context_type' => FakeHierarchicalContext::class,
        'context_id' => $context->id,
    ]);
}

function t89Deny(FakeEloquentUser $user, Role $role, FakeHierarchicalContext $context, string $permission): void
{
    UserContextPermissionOverride::create([
        'user_id' => $user->id,
        'role_id' => $role->id,
        'permission_id' => Permission::findOrCreate($permission, 'web')->id,
        'context_type' => FakeHierarchicalContext::class,
        'context_id' => $context->id,
        'type' => UserContextPermissionOverride::TYPE_FORCED_OFF,
    ]);
}

function t89HolderRows(Role $role): int
{
    return DB::table('model_has_roles')->where('role_id', $role->id)->count();
}

function t89Delete(FakeEloquentUser $actor, Role $role, string $action = 'detach', ?Role $target = null): mixed
{
    return Livewire::actingAs($actor)->test(DeleteRole::class, ['roleId' => $role->id])
        ->set('action', $action)
        ->set('targetRoleId', $target?->id)
        ->set('confirmText', 'DELETE '.$role->name)
        ->call('confirmDelete');
}

it('refuses detaching the unlocked super_admin role before any write and every holder keeps it (case 1)', function (): void {
    $role = t89SuperAdminRole();
    $first = t89SuperAdmin('first@example.com');
    $second = t89SuperAdmin('second@example.com');

    expect(fn () => $this->service->detachRoleHoldersAs($first, $role))
        ->toThrow(ClearanceProtectedResourceException::class);

    expect($first->fresh()->hasRole('super_admin'))->toBeTrue()
        ->and($second->fresh()->hasRole('super_admin'))->toBeTrue()
        ->and(t89HolderRows($role))->toBe(2);
});

it('refuses detaching a locked role and the holders keep it (case 2)', function (): void {
    $role = t89Role('clerk', 'orders-read');
    RoleMeta::create(['role_id' => $role->id, 'is_locked' => true, 'scope' => 'global']);
    $holder = t89User('holder@example.com');
    $holder->assignRole($role);

    expect(fn () => $this->service->detachRoleHoldersAs(t89SuperAdmin(), $role))
        ->toThrow(ClearanceProtectedResourceException::class, 'locked');

    expect($holder->fresh()->hasRole('clerk'))->toBeTrue()
        ->and(t89HolderRows($role))->toBe(1);
});

it('refuses a manager detaching a role held by a super_admin or holding a permission the manager lacks (case 3)', function (): void {
    $manager = t89Manager();
    $clerk = t89Role('clerk', 'orders-read');
    $plain = t89User('plain@example.com');
    $plain->assignRole($clerk);
    $root = t89SuperAdmin();
    $root->assignRole($clerk);

    expect(fn () => $this->service->detachRoleHoldersAs($manager, $clerk))
        ->toThrow(ClearanceScopeViolationException::class, 'Only super_admin may change the roles of a super_admin.');

    expect($plain->fresh()->hasRole('clerk'))->toBeTrue()
        ->and($root->fresh()->hasRole('clerk'))->toBeTrue()
        ->and(t89HolderRows($clerk))->toBe(2);

    $wide = t89Role('wide', 'orders-delete');
    $other = t89User('other@example.com');
    $other->assignRole($wide);

    expect(fn () => $this->service->detachRoleHoldersAs($manager, $wide))
        ->toThrow(ClearanceScopeViolationException::class, 'orders-delete');

    expect($other->fresh()->hasRole('wide'))->toBeTrue();

    $component = t89Delete($manager, $wide);

    expect($component->get('errorMessage'))->toContain('orders-delete')
        ->and(Role::find($wide->id))->not->toBeNull();
});

it('refuses detaching a role whose binding carries forced_off for a manager and allows it for super_admin (case 4)', function (): void {
    $manager = t89Manager();
    $clerk = t89Role('clerk', 'orders-read');
    $staff = t89User('staff@example.com');
    t89Bind($staff, $clerk, $this->context);
    t89Deny($staff, $clerk, $this->context, 'orders-read');

    expect(fn () => $this->service->detachRoleHoldersAs($manager, $clerk))
        ->toThrow(ClearanceProtectedResourceException::class, 'denied permissions');

    expect(UserRoleContext::where('role_id', $clerk->id)->count())->toBe(1)
        ->and(UserContextPermissionOverride::where('role_id', $clerk->id)->count())->toBe(1);

    $this->service->detachRoleHoldersAs(t89SuperAdmin(), $clerk);

    expect(UserRoleContext::where('role_id', $clerk->id)->count())->toBe(0)
        ->and(UserContextPermissionOverride::where('role_id', $clerk->id)->count())->toBe(0);
});

it('detaches holders within the ceiling, applies the direct permission rule and deletes the role (case 5)', function (): void {
    $manager = t89Manager();
    $clerk = t89Role('clerk', 'orders-read');
    $other = t89Role('other', 'orders-read');
    $solo = t89User('solo@example.com');
    $solo->assignRole($clerk);
    $solo->givePermissionTo(Permission::findOrCreate('orders-read', 'web'));
    $multi = t89User('multi@example.com');
    $multi->assignRole($clerk);
    $multi->assignRole($other);
    $multi->givePermissionTo(Permission::findOrCreate('orders-read', 'web'));
    $id = $clerk->id;

    $component = t89Delete($manager, $clerk);

    expect($component->get('errorMessage'))->toBeNull()
        ->and(Role::find($id))->toBeNull()
        ->and(DB::table('model_has_roles')->where('role_id', $id)->count())->toBe(0)
        ->and($solo->fresh()->hasRole('clerk'))->toBeFalse()
        ->and($solo->fresh()->permissions()->count())->toBe(0)
        ->and($multi->fresh()->hasRole('other'))->toBeTrue()
        ->and($multi->fresh()->permissions()->pluck('name')->all())->toBe(['orders-read']);
});

it('keeps the forced_off rows on reassignment, re-keyed to the target role, and resolveFor still denies (case 6)', function (): void {
    $viewer = t89Role('viewer', 'orders-read');
    $clerk = t89Role('clerk', 'orders-read');
    $target = t89Role('clerk-two', 'orders-read');
    $staff = t89User('staff@example.com');
    $staff->assignRole($viewer);
    t89Bind($staff, $clerk, $this->context);
    t89Deny($staff, $clerk, $this->context, 'orders-read');

    $contexts = new ContextService;

    expect($contexts->resolveFor($staff, $this->context)->contains('orders-read'))->toBeFalse();

    $this->service->reassignRoleHoldersAs(t89SuperAdmin(), $clerk, $target);

    $overrides = UserContextPermissionOverride::where('user_id', $staff->id)->get();

    expect($overrides)->toHaveCount(1)
        ->and($overrides->first()->role_id)->toBe($target->id)
        ->and($overrides->first()->type)->toBe(UserContextPermissionOverride::TYPE_FORCED_OFF)
        ->and(UserRoleContext::where('role_id', $target->id)->count())->toBe(1)
        ->and((new ContextService)->resolveFor($staff->fresh(), $this->context)->contains('orders-read'))->toBeFalse();
});

it('refuses reassigning the super_admin role (case 7)', function (): void {
    $role = t89SuperAdminRole();
    $target = t89Role('clerk', 'orders-read');
    $root = t89SuperAdmin();

    expect(fn () => $this->service->reassignRoleHoldersAs($root, $role, $target))
        ->toThrow(ClearanceProtectedResourceException::class, 'cannot be reassigned');

    expect($root->fresh()->hasRole('super_admin'))->toBeTrue()
        ->and($root->fresh()->hasRole('clerk'))->toBeFalse();
});

it('tolerates orphan holder rows and a binding in a deleted context for a manager, and still refuses a resolvable super_admin holder (case 8)', function (): void {
    $manager = t89Manager();
    $clerk = t89Role('clerk', 'orders-read');
    $real = t89User('real@example.com');
    $real->assignRole($clerk);

    DB::table('model_has_roles')->insert([
        ['role_id' => $clerk->id, 'model_type' => 'App\\T89\\MissingModel', 'model_id' => 1],
        ['role_id' => $clerk->id, 'model_type' => FakeEloquentUser::class, 'model_id' => 9999],
    ]);
    UserRoleContext::create([
        'user_id' => $real->id,
        'role_id' => $clerk->id,
        'context_type' => FakeHierarchicalContext::class,
        'context_id' => 9999,
    ]);

    $root = t89SuperAdmin();
    $root->assignRole($clerk);

    expect(fn () => $this->service->detachRoleHoldersAs($manager, $clerk))
        ->toThrow(ClearanceScopeViolationException::class, 'Only super_admin may change the roles of a super_admin.');

    expect(t89HolderRows($clerk))->toBe(4)
        ->and(UserRoleContext::where('role_id', $clerk->id)->count())->toBe(1)
        ->and(Role::find($clerk->id))->not->toBeNull();

    $root->removeRole($clerk);
    $id = $clerk->id;

    $component = t89Delete($manager, $clerk);

    expect($component->get('errorMessage'))->toBeNull()
        ->and(Role::find($id))->toBeNull()
        ->and(DB::table('model_has_roles')->where('role_id', $id)->count())->toBe(0)
        ->and(UserRoleContext::where('role_id', $id)->count())->toBe(0);
});

it('treats a holder hidden by a global scope or soft-deleted as real, so a manager cannot detach a super_admin (case 9)', function (): void {
    $manager = t89Manager();

    $clerk = t89Role('clerk', 'orders-read');
    $scoped = T89ScopedUser::create(['name' => 'scoped', 'email' => 'scoped@example.com', 'password' => 'x']);
    $scoped->assignRole(t89SuperAdminRole());
    $scoped->assignRole($clerk);

    expect(T89ScopedUser::find($scoped->id))->toBeNull();

    expect(fn () => $this->service->detachRoleHoldersAs($manager, $clerk))
        ->toThrow(ClearanceScopeViolationException::class, 'Only super_admin may change the roles of a super_admin.');

    expect(t89HolderRows($clerk))->toBe(1);

    $trashedRole = t89Role('auditor', 'orders-read');
    $trashed = T89SoftDeletingUser::create(['name' => 'trashed', 'email' => 'trashed@example.com', 'password' => 'x']);
    $trashed->assignRole(t89SuperAdminRole());
    $trashed->assignRole($trashedRole);
    $trashed->delete();

    expect(T89SoftDeletingUser::find($trashed->id))->toBeNull();

    expect(fn () => $this->service->detachRoleHoldersAs($manager, $trashedRole))
        ->toThrow(ClearanceScopeViolationException::class, 'Only super_admin may change the roles of a super_admin.');

    expect(t89HolderRows($trashedRole))->toBe(1);
});

it('refuses a manager detaching a system-assigned-only role through a binding in a deleted context (case 10)', function (): void {
    config()->set('clearance.non_delegable', ['clearance-access', 'orders-secret']);

    $manager = t89Manager();
    $secret = t89Role('secret', 'orders-secret');
    $staff = t89User('staff@example.com');
    UserRoleContext::create([
        'user_id' => $staff->id,
        'role_id' => $secret->id,
        'context_type' => FakeHierarchicalContext::class,
        'context_id' => 9999,
    ]);

    expect(fn () => $this->service->detachRoleHoldersAs($manager, $secret))
        ->toThrow(ClearanceProtectedResourceException::class, 'can only be removed by a super_admin');

    expect(UserRoleContext::where('role_id', $secret->id)->count())->toBe(1)
        ->and(Role::find($secret->id))->not->toBeNull();

    $this->service->detachRoleHoldersAs(t89SuperAdmin(), $secret);

    expect(UserRoleContext::where('role_id', $secret->id)->count())->toBe(0);
});
