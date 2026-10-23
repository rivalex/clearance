<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Rivalex\Clearance\Exceptions\ClearanceScopeViolationException;
use Rivalex\Clearance\Services\UserClearanceService;
use Rivalex\Clearance\Tests\Support\FakeEloquentUser;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $this->runMigrations();

    Schema::create('fake_users', static function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->string('password');
        $table->timestamps();
    });

    config()->set('clearance.user_model', FakeEloquentUser::class);

    $this->service = new UserClearanceService;
});

function t86User(string $email): FakeEloquentUser
{
    return FakeEloquentUser::create(['name' => $email, 'email' => $email, 'password' => 'x']);
}

function t86Role(string $name, string ...$permissions): Role
{
    $role = Role::create(['name' => $name, 'guard_name' => 'web']);

    foreach ($permissions as $permission) {
        $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    return $role;
}

function t86SuperAdmin(string $email): FakeEloquentUser
{
    $user = t86User($email);
    $user->assignRole(Role::findOrCreate('super_admin', 'web'));

    return $user;
}

function t86Direct(FakeEloquentUser $user): array
{
    return $user->fresh()->permissions()->where('guard_name', 'web')->pluck('name')->sort()->values()->all();
}

it('refuses removing a role the target does not hold and changes nothing', function (): void {
    $actor = t86User('actor@example.com');
    $actor->givePermissionTo(Permission::findOrCreate('orders-read', 'web'));
    $target = t86User('target@example.com');
    $target->givePermissionTo(Permission::findOrCreate('orders-read', 'web'));
    $clerk = t86Role('clerk', 'orders-read');

    expect(fn () => $this->service->removeGlobalRoleAs($actor, $target, $clerk))
        ->toThrow(ClearanceScopeViolationException::class, 'User does not hold role [clerk].');

    expect(t86Direct($target))->toBe(['orders-read']);
});

it('refuses a non super_admin actor removing an ordinary role from a super_admin target', function (): void {
    $actor = t86User('actor@example.com');
    $actor->givePermissionTo(Permission::findOrCreate('orders-read', 'web'));
    $target = t86SuperAdmin('root@example.com');
    $clerk = t86Role('clerk', 'orders-read');
    $target->assignRole($clerk);

    expect(fn () => $this->service->removeGlobalRoleAs($actor, $target, $clerk))
        ->toThrow(ClearanceScopeViolationException::class, 'Only super_admin may change the roles of a super_admin.');

    expect($target->fresh()->hasRole('clerk'))->toBeTrue();
});

it('removes the role and keeps the direct permissions while another role on the same guard remains', function (): void {
    $actor = t86User('actor@example.com');
    $actor->givePermissionTo(Permission::findOrCreate('orders-read', 'web'));
    $target = t86User('target@example.com');
    $clerk = t86Role('clerk', 'orders-read');
    $target->assignRole($clerk, t86Role('auditor', 'orders-read'));
    $target->givePermissionTo(Permission::findOrCreate('reports-view', 'web'));

    $this->service->removeGlobalRoleAs($actor, $target, $clerk);

    expect($target->fresh()->hasRole('clerk'))->toBeFalse();
    expect($target->fresh()->hasRole('auditor'))->toBeTrue();
    expect(t86Direct($target))->toBe(['reports-view']);
});

it('refuses removing the last role on the guard when a direct permission is above the actor ceiling', function (): void {
    $actor = t86User('actor@example.com');
    $actor->givePermissionTo(Permission::findOrCreate('orders-read', 'web'));
    $target = t86User('target@example.com');
    $clerk = t86Role('clerk', 'orders-read');
    $target->assignRole($clerk);
    $target->givePermissionTo(Permission::findOrCreate('orders-read', 'web'));
    $target->givePermissionTo(Permission::findOrCreate('orders-delete', 'web'));

    expect(fn () => $this->service->removeGlobalRoleAs($actor, $target, $clerk))
        ->toThrow(ClearanceScopeViolationException::class, 'Cannot remove role [clerk]: the user holds direct permission(s) [orders-delete] that you do not hold.');

    expect($target->fresh()->hasRole('clerk'))->toBeTrue();
    expect(t86Direct($target))->toBe(['orders-delete', 'orders-read']);
});

it('lets a super_admin remove the last role on the guard and revoke every direct permission', function (): void {
    $root = t86SuperAdmin('root@example.com');
    $target = t86User('target@example.com');
    $clerk = t86Role('clerk', 'orders-read');
    $target->assignRole($clerk);
    $target->givePermissionTo(Permission::findOrCreate('orders-read', 'web'));
    $target->givePermissionTo(Permission::findOrCreate('orders-delete', 'web'));

    $this->service->removeGlobalRoleAs($root, $target, $clerk);

    expect($target->fresh()->hasRole('clerk'))->toBeFalse();
    expect(t86Direct($target))->toBe([]);
});

it('removes the last role on the guard and revokes the direct permissions when the actor holds every one', function (): void {
    $actor = t86User('actor@example.com');
    $actor->givePermissionTo(Permission::findOrCreate('orders-read', 'web'));
    $actor->givePermissionTo(Permission::findOrCreate('reports-view', 'web'));
    $target = t86User('target@example.com');
    $clerk = t86Role('clerk', 'orders-read');
    $target->assignRole($clerk);
    $target->givePermissionTo(Permission::findOrCreate('reports-view', 'web'));

    $this->service->removeGlobalRoleAs($actor, $target, $clerk);

    expect($target->fresh()->hasRole('clerk'))->toBeFalse();
    expect(t86Direct($target))->toBe([]);
});

it('keeps the trusted removeGlobalRole() revoking every direct permission even when another role remains', function (): void {
    $target = t86User('target@example.com');
    $clerk = t86Role('clerk', 'orders-read');
    $target->assignRole($clerk, t86Role('auditor', 'orders-read'));
    $target->givePermissionTo(Permission::findOrCreate('reports-view', 'web'));

    $this->service->removeGlobalRole($target, $clerk);

    expect($target->fresh()->hasRole('clerk'))->toBeFalse();
    expect($target->fresh()->hasRole('auditor'))->toBeTrue();
    expect(t86Direct($target))->toBe([]);
});
