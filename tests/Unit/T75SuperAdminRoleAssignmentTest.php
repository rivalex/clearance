<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Rivalex\Clearance\Exceptions\ClearanceScopeViolationException;
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
    $this->context = FakeHierarchicalContext::create(['parent_id' => null]);
});

function t75User(string $email): FakeEloquentUser
{
    return FakeEloquentUser::create(['name' => $email, 'email' => $email, 'password' => 'x']);
}

function t75Role(string $name, string ...$permissions): Role
{
    $role = Role::create(['name' => $name, 'guard_name' => 'web']);

    foreach ($permissions as $permission) {
        $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    return $role;
}

/**
 * A non super_admin actor holding every permission of the given role through another role (trusted Spatie setup).
 */
function t75ActorHolding(Role $role): FakeEloquentUser
{
    $actor = t75User('actor@example.com');
    $actor->assignRole(t75Role('helper', ...$role->permissions->pluck('name')->all()));

    return $actor;
}

it('rejects a global super_admin assignment by a non super_admin who holds every permission of the role', function (): void {
    $superAdminRole = t75Role('super_admin', 'orders-read', 'orders-delete');
    $actor = t75ActorHolding($superAdminRole);
    $target = t75User('target@example.com');

    expect(fn () => $this->service->assignGlobalRoleAs($actor, $target, $superAdminRole))
        ->toThrow(ClearanceScopeViolationException::class, 'Only super_admin may assign the super_admin role.');

    expect($target->fresh()->hasRole('super_admin'))->toBeFalse();
});

it('rejects a contextual super_admin assignment by a non super_admin who holds every permission of the role', function (): void {
    $superAdminRole = t75Role('super_admin', 'orders-read', 'orders-delete');
    $actor = t75ActorHolding($superAdminRole);
    $target = t75User('target@example.com');

    expect(fn () => $this->service->assignContextualAs($actor, $target, $superAdminRole, $this->context))
        ->toThrow(ClearanceScopeViolationException::class, 'Only super_admin may assign the super_admin role.');

    expect(UserRoleContext::where('user_id', $target->id)->count())->toBe(0);
});

it('lets a super_admin assign super_admin globally to another user', function (): void {
    $superAdminRole = t75Role('super_admin', 'orders-read');
    $actor = t75User('root@example.com');
    $actor->assignRole($superAdminRole);
    $target = t75User('target@example.com');

    $this->service->assignGlobalRoleAs($actor, $target, $superAdminRole);

    expect($target->fresh()->hasRole('super_admin'))->toBeTrue();
});

it('still lets the same non super_admin actor assign an ordinary role whose permissions they hold', function (): void {
    $superAdminRole = t75Role('super_admin', 'orders-read', 'orders-delete');
    $actor = t75ActorHolding($superAdminRole);
    $target = t75User('target@example.com');

    $this->service->assignGlobalRoleAs($actor, $target, t75Role('clerk', 'orders-read'));

    expect($target->fresh()->hasRole('clerk'))->toBeTrue();
});
