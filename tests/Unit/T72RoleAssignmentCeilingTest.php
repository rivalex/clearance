<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Rivalex\Clearance\Exceptions\ClearanceScopeViolationException;
use Rivalex\Clearance\Livewire\Users\AssignRoleModal;
use Rivalex\Clearance\Models\RoleMeta;
use Rivalex\Clearance\Models\UserRoleContext;
use Rivalex\Clearance\Services\UserClearanceService;
use Rivalex\Clearance\Tests\Support\FakeEloquentUser;
use Rivalex\Clearance\Tests\Support\FakeHierarchicalContext;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $this->runMigrations();
    config()->set('clearance.user_model', FakeEloquentUser::class);

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
    $this->companyA = FakeHierarchicalContext::create(['parent_id' => null]);
    $this->storeA1 = FakeHierarchicalContext::create(['parent_id' => $this->companyA->id]);
    $this->companyB = FakeHierarchicalContext::create(['parent_id' => null]);
    $this->storeB1 = FakeHierarchicalContext::create(['parent_id' => $this->companyB->id]);
});

function t72User(string $email): FakeEloquentUser
{
    return FakeEloquentUser::create(['name' => $email, 'email' => $email, 'password' => 'x']);
}

function t72Role(string $name, string ...$permissions): Role
{
    $role = Role::create(['name' => $name, 'guard_name' => 'web']);

    foreach ($permissions as $permission) {
        $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    return $role;
}

function t72Bind(FakeEloquentUser $user, Role $role, FakeHierarchicalContext $context): void
{
    UserRoleContext::create([
        'user_id' => $user->id,
        'role_id' => $role->id,
        'context_type' => FakeHierarchicalContext::class,
        'context_id' => $context->id,
    ]);
}

function t72Bindings(FakeEloquentUser $user): int
{
    return UserRoleContext::where('user_id', $user->id)->count();
}

it('lets a manager bound to the company assign a role he fully holds in a store of that company', function (): void {
    $manager = t72User('manager@example.com');
    $staff = t72User('staff@example.com');
    t72Bind($manager, t72Role('manager', 'orders-read'), $this->companyA);

    $this->service->assignContextualAs($manager, $staff, t72Role('clerk', 'orders-read'), $this->storeA1);

    expect(t72Bindings($staff))->toBe(1);
});

it('rejects the same assignment in a store of another company', function (): void {
    $manager = t72User('manager@example.com');
    $staff = t72User('staff@example.com');
    t72Bind($manager, t72Role('manager', 'orders-read'), $this->companyA);

    expect(fn () => $this->service->assignContextualAs($manager, $staff, t72Role('clerk', 'orders-read'), $this->storeB1))
        ->toThrow(ClearanceScopeViolationException::class);

    expect(t72Bindings($staff))->toBe(0);
});

it('rejects a contextual role with a permission the actor lacks and writes nothing', function (): void {
    $manager = t72User('manager@example.com');
    $staff = t72User('staff@example.com');
    t72Bind($manager, t72Role('manager', 'orders-read'), $this->companyA);

    expect(fn () => $this->service->assignContextualAs($manager, $staff, t72Role('clerk', 'orders-read', 'orders-delete'), $this->storeA1))
        ->toThrow(ClearanceScopeViolationException::class, 'orders-delete');

    expect(t72Bindings($staff))->toBe(0);
});

it('rejects a global role with a permission the actor lacks and allows one he holds fully', function (): void {
    $actor = t72User('actor@example.com');
    $staff = t72User('staff@example.com');
    $actor->givePermissionTo(Permission::findOrCreate('orders-read', 'web'));

    expect(fn () => $this->service->assignGlobalRoleAs($actor, $staff, t72Role('wide', 'orders-read', 'orders-delete')))
        ->toThrow(ClearanceScopeViolationException::class);
    expect($staff->fresh()->hasRole('wide'))->toBeFalse();

    $this->service->assignGlobalRoleAs($actor, $staff, t72Role('narrow', 'orders-read'));

    expect($staff->fresh()->hasRole('narrow'))->toBeTrue();
});

it('rejects self-assignment by a non super_admin and allows it for super_admin', function (): void {
    $actor = t72User('self@example.com');
    $role = t72Role('plain');

    expect(fn () => $this->service->assignGlobalRoleAs($actor, $actor, $role))
        ->toThrow(ClearanceScopeViolationException::class);
    expect(fn () => $this->service->assignContextualAs($actor, $actor, $role, $this->storeA1))
        ->toThrow(ClearanceScopeViolationException::class);

    $actor->assignRole(Role::create(['name' => 'super_admin', 'guard_name' => 'web']));
    $this->service->assignGlobalRoleAs($actor, $actor, $role);

    expect($actor->fresh()->hasRole('plain'))->toBeTrue();
});

it('rejects a staff member with no grants for any assignment', function (): void {
    $staff = t72User('nobody@example.com');
    $target = t72User('target@example.com');

    expect(fn () => $this->service->assignContextualAs($staff, $target, t72Role('clerk', 'orders-read'), $this->storeA1))
        ->toThrow(ClearanceScopeViolationException::class);
    expect(fn () => $this->service->assignGlobalRoleAs($staff, $target, t72Role('clerk2', 'orders-read')))
        ->toThrow(ClearanceScopeViolationException::class);
});

it('throws scope violations before the ceiling', function (): void {
    $staff = t72User('nobody@example.com');
    $target = t72User('target@example.com');

    $globalOnly = t72Role('global-only', 'orders-read');
    RoleMeta::create(['role_id' => $globalOnly->id, 'scope' => RoleMeta::SCOPE_GLOBAL]);
    $contextualOnly = t72Role('ctx-only', 'orders-read');
    RoleMeta::create(['role_id' => $contextualOnly->id, 'scope' => RoleMeta::SCOPE_CONTEXTUAL, 'context_types' => [FakeHierarchicalContext::class]]);

    expect(fn () => $this->service->assignContextualAs($staff, $target, $globalOnly, $this->storeA1))
        ->toThrow(ClearanceScopeViolationException::class, 'is global');
    expect(fn () => $this->service->assignGlobalRoleAs($staff, $target, $contextualOnly))
        ->toThrow(ClearanceScopeViolationException::class, 'is contextual');
});

it('keeps the trusted assignment methods free of any actor ceiling', function (): void {
    $target = t72User('target@example.com');

    $this->service->assignContextual($target, t72Role('clerk', 'orders-read'), $this->storeA1);
    $this->service->assignGlobalRole($target, t72Role('wide', 'orders-delete'));

    expect(t72Bindings($target))->toBe(1)
        ->and($target->fresh()->hasRole('wide'))->toBeTrue();
});

it('shows an error and writes nothing when the modal actor lacks a permission of the role', function (): void {
    config()->set('clearance.modules.users', true);
    config()->set('clearance.user_model', FakeEloquentUser::class);
    $actor = t72User('modal-actor@example.com');
    $actor->givePermissionTo(Permission::findOrCreate('clearance-access', 'web'));
    $target = t72User('modal-target@example.com');
    $role = t72Role('wide', 'orders-delete');
    $this->actingAs($actor);

    Livewire::test(AssignRoleModal::class, ['userId' => $target->id, 'scope' => 'global'])
        ->set('selectedRoleId', $role->id)
        ->call('save')
        ->assertSet('errorMessage', fn (?string $message) => $message !== null && str_contains($message, 'orders-delete'));

    expect($target->fresh()->hasRole('wide'))->toBeFalse();
});
