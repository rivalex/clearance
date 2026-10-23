<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Rivalex\Clearance\Exceptions\ClearanceProtectedResourceException;
use Rivalex\Clearance\Exceptions\ClearanceScopeViolationException;
use Rivalex\Clearance\Livewire\Users\RemoveAssignmentModal;
use Rivalex\Clearance\Models\UserRoleContext;
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

    Schema::create('fake_users', static function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->string('password');
        $table->timestamps();
    });

    config()->set('clearance.user_model', FakeEloquentUser::class);
    config()->set('clearance.non_delegable', ['billing-*']);
    config()->set('clearance.contextual_models', [FakeHierarchicalContext::class => ['label' => 'Store']]);

    $this->service = new UserClearanceService;
    $this->companyA = FakeHierarchicalContext::create(['parent_id' => null]);
    $this->storeA1 = FakeHierarchicalContext::create(['parent_id' => $this->companyA->id]);
});

function t82User(string $email): FakeEloquentUser
{
    return FakeEloquentUser::create(['name' => $email, 'email' => $email, 'password' => 'x']);
}

function t82Role(string $name, string ...$permissions): Role
{
    $role = Role::create(['name' => $name, 'guard_name' => 'web']);

    foreach ($permissions as $permission) {
        $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    return $role;
}

function t82SuperAdminRole(): Role
{
    return Role::findOrCreate('super_admin', 'web');
}

function t82SuperAdmin(string $email): FakeEloquentUser
{
    $user = t82User($email);
    $user->assignRole(t82SuperAdminRole());

    return $user;
}

function t82Bind(FakeEloquentUser $user, Role $role, FakeHierarchicalContext $context): void
{
    UserRoleContext::create([
        'user_id' => $user->id,
        'role_id' => $role->id,
        'context_type' => FakeHierarchicalContext::class,
        'context_id' => $context->id,
    ]);
}

function t82Bindings(FakeEloquentUser $user, Role $role): int
{
    return UserRoleContext::where('user_id', $user->id)->where('role_id', $role->id)->count();
}

it('refuses a non super_admin actor removing super_admin from a super_admin and keeps the role', function (): void {
    $root = t82SuperAdmin('root@example.com');
    t82SuperAdmin('root2@example.com');
    $actor = t82User('actor@example.com');
    $actor->givePermissionTo(Permission::findOrCreate('orders-read', 'web'));

    expect(fn () => $this->service->removeGlobalRoleAs($actor, $root, t82SuperAdminRole()))
        ->toThrow(ClearanceScopeViolationException::class, 'Only super_admin may remove the super_admin role.');

    expect($root->fresh()->hasRole('super_admin'))->toBeTrue();
});

it('refuses removing the last super_admin and allows it when another holder remains', function (): void {
    $root = t82SuperAdmin('root@example.com');

    expect(fn () => $this->service->removeGlobalRoleAs($root, $root, t82SuperAdminRole()))
        ->toThrow(ClearanceProtectedResourceException::class, 'The last super_admin cannot be removed.');
    expect($root->fresh()->hasRole('super_admin'))->toBeTrue();

    $second = t82SuperAdmin('root2@example.com');

    $this->service->removeGlobalRoleAs($root, $second, t82SuperAdminRole());

    expect($second->fresh()->hasRole('super_admin'))->toBeFalse();
    expect($root->fresh()->hasRole('super_admin'))->toBeTrue();
});

it('refuses removing a global role that holds a permission the actor lacks', function (): void {
    $actor = t82User('actor@example.com');
    $actor->givePermissionTo(Permission::findOrCreate('orders-read', 'web'));
    $target = t82User('target@example.com');
    $wide = t82Role('wide', 'orders-read', 'orders-delete');
    $target->assignRole($wide);

    expect(fn () => $this->service->removeGlobalRoleAs($actor, $target, $wide))
        ->toThrow(ClearanceScopeViolationException::class, 'orders-delete');

    expect($target->fresh()->hasRole('wide'))->toBeTrue();
});

it('allows removing a global role within the actor ceiling and still revokes the direct permissions', function (): void {
    $actor = t82User('actor@example.com');
    $actor->givePermissionTo(Permission::findOrCreate('orders-read', 'web'));
    $actor->givePermissionTo(Permission::findOrCreate('reports-view', 'web'));
    $target = t82User('target@example.com');
    $narrow = t82Role('narrow', 'orders-read');
    $target->assignRole($narrow);
    $target->givePermissionTo(Permission::findOrCreate('reports-view', 'web'));

    $this->service->removeGlobalRoleAs($actor, $target, $narrow);

    $target = $target->fresh();
    expect($target->hasRole('narrow'))->toBeFalse();
    expect($target->permissions()->where('guard_name', 'web')->count())->toBe(0);
});

it('lets only a super_admin remove a system-assigned-only role, globally and contextually', function (): void {
    $billing = t82Role('billing-owner', 'billing-view');
    $actor = t82User('actor@example.com');
    $actor->givePermissionTo(Permission::findOrCreate('billing-view', 'web'));
    $holder = t82User('holder@example.com');
    $holder->assignRole($billing);
    t82Bind($holder, $billing, $this->storeA1);

    expect(fn () => $this->service->removeGlobalRoleAs($actor, $holder, $billing))
        ->toThrow(ClearanceProtectedResourceException::class);
    expect(fn () => $this->service->removeContextualAs($actor, $holder, $billing, $this->storeA1))
        ->toThrow(ClearanceProtectedResourceException::class);

    expect($holder->fresh()->hasRole('billing-owner'))->toBeTrue();
    expect(t82Bindings($holder, $billing))->toBe(1);

    $root = t82SuperAdmin('root@example.com');

    $this->service->removeGlobalRoleAs($root, $holder, $billing);
    $this->service->removeContextualAs($root, $holder, $billing, $this->storeA1);

    expect($holder->fresh()->hasRole('billing-owner'))->toBeFalse();
    expect(t82Bindings($holder, $billing))->toBe(0);
});

it('lets a manager of company A remove a binding in store A1 within the ceiling and refuses one above it', function (): void {
    $manager = t82User('manager@example.com');
    t82Bind($manager, t82Role('manager', 'orders-read'), $this->companyA);
    $staff = t82User('staff@example.com');
    $clerk = t82Role('clerk', 'orders-read');
    $lead = t82Role('lead', 'orders-read', 'orders-delete');
    t82Bind($staff, $clerk, $this->storeA1);
    t82Bind($staff, $lead, $this->storeA1);

    expect(fn () => $this->service->removeContextualAs($manager, $staff, $lead, $this->storeA1))
        ->toThrow(ClearanceScopeViolationException::class, 'orders-delete');
    expect(t82Bindings($staff, $lead))->toBe(1);

    $this->service->removeContextualAs($manager, $staff, $clerk, $this->storeA1);

    expect(t82Bindings($staff, $clerk))->toBe(0);
});

it('shows the exception message and keeps the binding when the modal removal is refused', function (): void {
    config()->set('clearance.modules.users', true);
    Permission::findOrCreate('clearance-users-write', 'web');
    $actor = t82User('actor@example.com');
    $actor->givePermissionTo('clearance-users-write');
    $actor->givePermissionTo(Permission::findOrCreate('clearance-access', 'web'));
    Auth::guard()->setUser($actor);
    $target = t82User('target@example.com');
    $wide = t82Role('wide', 'orders-delete');
    $target->assignRole($wide);

    Livewire::test(RemoveAssignmentModal::class, [
        'userId' => $target->id,
        'roleId' => $wide->id,
        'scope' => 'global',
    ])
        ->set('confirmText', 'DELETE wide')
        ->call('confirmDelete')
        ->assertSet('errorMessage', 'Cannot remove role [wide]: you do not hold permission(s) [orders-delete].')
        ->assertNotDispatched('clearance:assignment-removed');

    expect($target->fresh()->hasRole('wide'))->toBeTrue();
});

it('keeps the trusted removeGlobalRole() and removeContextual() free of checks', function (): void {
    $root = t82SuperAdmin('root@example.com');
    $this->service->removeGlobalRole($root, t82SuperAdminRole());
    expect($root->fresh()->hasRole('super_admin'))->toBeFalse();

    $billing = t82Role('billing-owner', 'billing-view');
    $holder = t82User('holder@example.com');
    $holder->assignRole($billing);
    t82Bind($holder, $billing, $this->storeA1);

    $this->service->removeGlobalRole($holder, $billing);
    $this->service->removeContextual($holder, $billing, $this->storeA1);

    expect($holder->fresh()->hasRole('billing-owner'))->toBeFalse();
    expect(t82Bindings($holder, $billing))->toBe(0);
});
