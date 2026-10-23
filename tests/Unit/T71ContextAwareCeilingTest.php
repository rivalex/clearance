<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Rivalex\Clearance\Exceptions\ClearanceProtectedResourceException;
use Rivalex\Clearance\Exceptions\ClearanceScopeViolationException;
use Rivalex\Clearance\Models\UserContextPermissionOverride;
use Rivalex\Clearance\Models\UserRoleContext;
use Rivalex\Clearance\Services\UserClearanceService;
use Rivalex\Clearance\Tests\Support\FakeEloquentUser;
use Rivalex\Clearance\Tests\Support\FakeHierarchicalContext;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    config()->set('clearance.user_model', FakeEloquentUser::class);
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

    $this->service = new UserClearanceService;
    $this->company = FakeHierarchicalContext::create(['parent_id' => null]);
    $this->store = FakeHierarchicalContext::create(['parent_id' => $this->company->id]);
    $this->staffRole = Role::create(['name' => 'staff', 'guard_name' => 'web']);
});

function t71User(string $email): FakeEloquentUser
{
    return FakeEloquentUser::create(['name' => $email, 'email' => $email, 'password' => 'x']);
}

function t71Bind(FakeEloquentUser $user, Role $role, FakeHierarchicalContext $context): void
{
    UserRoleContext::create([
        'user_id' => $user->id,
        'role_id' => $role->id,
        'context_type' => FakeHierarchicalContext::class,
        'context_id' => $context->id,
    ]);
}

function t71Role(string $name, string ...$permissions): Role
{
    $role = Role::create(['name' => $name, 'guard_name' => 'web']);

    foreach ($permissions as $permission) {
        $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    return $role;
}

it('allows a manager bound to the parent context to pin a permission in a child context', function (): void {
    $manager = t71User('manager@example.com');
    $staff = t71User('staff@example.com');
    $perm = Permission::findOrCreate('orders-read', 'web');
    t71Bind($manager, t71Role('manager', 'orders-read'), $this->company);
    t71Bind($staff, $this->staffRole, $this->store);

    $this->service->syncContextualExtraPermissions($manager, $staff, $this->staffRole, $this->store, [$perm->id]);

    expect(UserContextPermissionOverride::where('user_id', $staff->id)
        ->where('permission_id', $perm->id)->where('context_id', $this->store->id)->exists())->toBeTrue();
});

it('rejects the same manager in a store of another company', function (): void {
    $manager = t71User('manager@example.com');
    $staff = t71User('staff@example.com');
    $perm = Permission::findOrCreate('orders-read', 'web');
    t71Bind($manager, t71Role('manager', 'orders-read'), $this->company);

    $companyB = FakeHierarchicalContext::create(['parent_id' => null]);
    $storeB1 = FakeHierarchicalContext::create(['parent_id' => $companyB->id]);

    expect(fn () => $this->service->syncContextualExtraPermissions($manager, $staff, $this->staffRole, $storeB1, [$perm->id]))
        ->toThrow(ClearanceScopeViolationException::class);
});

it('rejects a manager without the permission in that context even if another context grants it', function (): void {
    $manager = t71User('manager@example.com');
    $staff = t71User('staff@example.com');
    $perm = Permission::findOrCreate('orders-read', 'web');
    $otherCompany = FakeHierarchicalContext::create(['parent_id' => null]);

    t71Bind($manager, t71Role('manager-plain'), $this->company);
    t71Bind($manager, t71Role('manager-orders', 'orders-read'), $otherCompany);

    expect(fn () => $this->service->syncContextualExtraPermissions($manager, $staff, $this->staffRole, $this->store, [$perm->id]))
        ->toThrow(ClearanceScopeViolationException::class);
});

it('lets an actor holding the permission through a global role grant it in any context', function (): void {
    $actor = t71User('actor@example.com');
    $staff = t71User('staff@example.com');
    $perm = Permission::findOrCreate('orders-read', 'web');
    $actor->assignRole(t71Role('global-orders', 'orders-read'));
    t71Bind($staff, $this->staffRole, $this->store);

    $this->service->syncContextualExtraPermissions($actor, $staff, $this->staffRole, $this->store, [$perm->id]);

    expect(UserContextPermissionOverride::where('user_id', $staff->id)
        ->where('permission_id', $perm->id)->exists())->toBeTrue();
});

it('lets an actor holding the permission only as a direct permission grant it in any context', function (): void {
    $actor = t71User('direct@example.com');
    $staff = t71User('staff@example.com');
    $perm = Permission::findOrCreate('orders-read', 'web');
    $actor->givePermissionTo($perm);
    t71Bind($staff, $this->staffRole, $this->store);

    $this->service->syncContextualExtraPermissions($actor, $staff, $this->staffRole, $this->store, [$perm->id]);

    expect(UserContextPermissionOverride::where('user_id', $staff->id)
        ->where('permission_id', $perm->id)->exists())->toBeTrue();
});

it('rejects a global-role holder whose contextual binding forces the permission off in the target context', function (): void {
    $actor = t71User('denied@example.com');
    $staff = t71User('staff@example.com');
    $perm = Permission::findOrCreate('orders-read', 'web');
    $actor->assignRole(t71Role('global-orders', 'orders-read'));

    $restricted = t71Role('restricted');
    t71Bind($actor, $restricted, $this->store);
    UserContextPermissionOverride::create([
        'user_id' => $actor->id,
        'role_id' => $restricted->id,
        'permission_id' => $perm->id,
        'context_type' => FakeHierarchicalContext::class,
        'context_id' => $this->store->id,
        'type' => UserContextPermissionOverride::TYPE_FORCED_OFF,
    ]);

    expect(fn () => $this->service->syncContextualExtraPermissions($actor, $staff, $this->staffRole, $this->store, [$perm->id]))
        ->toThrow(ClearanceScopeViolationException::class);
});

it('still rejects self-modification by a non super_admin', function (): void {
    $manager = t71User('manager@example.com');
    $perm = Permission::findOrCreate('orders-read', 'web');
    t71Bind($manager, t71Role('manager', 'orders-read'), $this->company);

    expect(fn () => $this->service->syncContextualExtraPermissions($manager, $manager, $this->staffRole, $this->store, [$perm->id]))
        ->toThrow(ClearanceScopeViolationException::class);
});

it('still rejects clearance-* permissions', function (): void {
    $manager = t71User('manager@example.com');
    $staff = t71User('staff@example.com');
    $perm = Permission::findOrCreate('clearance-access', 'web');
    t71Bind($manager, t71Role('manager', 'clearance-access'), $this->company);

    expect(fn () => $this->service->syncContextualExtraPermissions($manager, $staff, $this->staffRole, $this->store, [$perm->id]))
        ->toThrow(ClearanceProtectedResourceException::class);
});
