<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Rivalex\Clearance\Exceptions\ClearanceProtectedResourceException;
use Rivalex\Clearance\Exceptions\ClearanceScopeViolationException;
use Rivalex\Clearance\Livewire\Guards\DeleteGuard;
use Rivalex\Clearance\Livewire\Guards\GuardManager;
use Rivalex\Clearance\Livewire\Permissions\DeletePermission;
use Rivalex\Clearance\Livewire\Permissions\PermissionForm;
use Rivalex\Clearance\Livewire\Permissions\PermissionManager;
use Rivalex\Clearance\Livewire\Roles\RoleManager;
use Rivalex\Clearance\Models\Guard;
use Rivalex\Clearance\Models\Permission;
use Rivalex\Clearance\Services\GuardService;
use Rivalex\Clearance\Services\PermissionService;
use Rivalex\Clearance\Support\LikePattern;
use Rivalex\Clearance\Tests\Support\FakeEloquentUser;
use Spatie\Permission\Models\Role;

// Permission deletion follows the actor (holders, non-delegable), a guard in use cannot be re-pointed or deleted,
// the permissions list builds valid SQL per driver and escapes its search, and group writes are atomic.

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

    config()->set('clearance.non_delegable', []);

    $this->permissions = app(PermissionService::class);
});

function t106Permission(string $name, string $guard = 'web'): Permission
{
    return Permission::create(['name' => $name, 'guard_name' => $guard]);
}

function t106User(string $email): FakeEloquentUser
{
    return FakeEloquentUser::create(['name' => $email, 'email' => $email, 'password' => 'x']);
}

/** A permissions writer who is not super_admin: holds the panel access permission and nothing else. */
function t106Writer(): FakeEloquentUser
{
    $writer = t106User('writer@example.com');
    $writer->givePermissionTo(Permission::findOrCreate('clearance-access', 'web'));

    return $writer;
}

it('refuses a writer deleting a held permission they do not hold, and the holder keeps it', function (): void {
    $permission = t106Permission('orders-read');
    $holder = t106User('holder@example.com');
    $holder->givePermissionTo($permission);

    expect(fn () => $this->permissions->deleteAs(t106Writer(), $permission))
        ->toThrow(ClearanceScopeViolationException::class, 'Cannot delete permission [orders-read]: users hold it and you do not hold it yourself.');

    expect(Permission::where('name', 'orders-read')->exists())->toBeTrue()
        ->and($holder->fresh()->hasPermissionTo('orders-read'))->toBeTrue();
});

it('lets a writer delete a held permission they hold themselves', function (): void {
    $permission = t106Permission('orders-read');
    t106User('holder@example.com')->givePermissionTo($permission);
    $writer = t106Writer();
    $writer->givePermissionTo($permission);

    $this->permissions->deleteAs($writer, $permission);

    expect(Permission::where('name', 'orders-read')->exists())->toBeFalse();
});

it('lets a writer delete a permission nobody holds', function (): void {
    $permission = t106Permission('orders-read');

    $this->permissions->deleteAs(t106Writer(), $permission);

    expect(Permission::where('name', 'orders-read')->exists())->toBeFalse();
});

it('refuses a writer deleting a permission that only a role holds and they lack', function (): void {
    $permission = t106Permission('orders-read');
    Role::create(['name' => 'clerk', 'guard_name' => 'web'])->givePermissionTo($permission);

    expect(fn () => $this->permissions->deleteAs(t106Writer(), $permission))
        ->toThrow(ClearanceScopeViolationException::class);

    expect(Permission::where('name', 'orders-read')->exists())->toBeTrue();
});

it('refuses a non-super_admin deleting a non-delegable permission and lets a super_admin do it', function (): void {
    config()->set('clearance.non_delegable', ['billing-*']);
    $permission = t106Permission('billing-update');

    expect(fn () => $this->permissions->deleteAs(t106Writer(), $permission))
        ->toThrow(ClearanceProtectedResourceException::class, 'Permission [billing-update] is non-delegable and can only be deleted by a super_admin.');

    expect(Permission::where('name', 'billing-update')->exists())->toBeTrue();

    $admin = t106User('admin@example.com');
    $admin->assignRole(Role::findOrCreate('super_admin', 'web'));

    $this->permissions->deleteAs($admin, $permission);

    expect(Permission::where('name', 'billing-update')->exists())->toBeFalse();
});

it('refuses a group update that removes a held ability and writes nothing', function (): void {
    t106Permission('orders-create');
    $read = t106Permission('orders-read');
    t106User('holder@example.com')->givePermissionTo($read);

    expect(fn () => $this->permissions->updateGroupAs(t106Writer(), 'orders', 'web', ['create', 'read'], ['create', 'export']))
        ->toThrow(ClearanceScopeViolationException::class);

    expect(Permission::where('name', 'like', 'orders-%')->pluck('name')->sort()->values()->all())
        ->toBe(['orders-create', 'orders-read']);
});

it('allows a group update that only adds abilities', function (): void {
    t106Permission('orders-create');

    $this->permissions->updateGroupAs(t106Writer(), 'orders', 'web', ['create'], ['create', 'export']);

    expect(Permission::where('name', 'like', 'orders-%')->pluck('name')->sort()->values()->all())
        ->toBe(['orders-create', 'orders-export']);
});

it('refuses the whole group when one member cannot be deleted', function (): void {
    t106Permission('orders-create');
    $read = t106Permission('orders-read');
    t106User('holder@example.com')->givePermissionTo($read);

    expect(fn () => $this->permissions->deleteGroupAs(t106Writer(), 'orders', 'web'))
        ->toThrow(ClearanceScopeViolationException::class);

    expect(Permission::where('name', 'like', 'orders-%')->count())->toBe(2);
});

it('shows the refusal in the DeletePermission modal and keeps the group', function (): void {
    $read = t106Permission('orders-read');
    t106User('holder@example.com')->givePermissionTo($read);
    $this->actingAs(t106Writer(), 'web');

    $component = Livewire::test(DeletePermission::class, ['prefix' => 'orders', 'guard' => 'web', 'groupKey' => 'k'])
        ->set('confirmText', 'DELETE orders')
        ->call('confirmDelete');

    expect($component->get('errorMessage'))->toBe('Cannot delete permission [orders-read]: users hold it and you do not hold it yourself.')
        ->and(Permission::where('name', 'orders-read')->exists())->toBeTrue();
});

it('shows the refusal when the permission form removes a held ability', function (): void {
    t106Permission('orders-create');
    $read = t106Permission('orders-read');
    t106User('holder@example.com')->givePermissionTo($read);
    $this->actingAs(t106Writer(), 'web');

    $component = Livewire::test(PermissionForm::class, ['editingPrefix' => 'orders'])
        ->set('crudAbilities', ['create'])
        ->call('save');

    expect($component->get('errorMessage'))->toContain('orders-read')
        ->and(Permission::where('name', 'orders-read')->exists())->toBeTrue();
});

it('creates a group in one transaction', function (): void {
    expect(fn () => $this->permissions->createGroup('orders', 'web', ['read', 'read']))
        ->toThrow(Exception::class);

    expect(Permission::where('name', 'like', 'orders-%')->count())->toBe(0);
});

it('refuses to re-point a guard that has a role, and to delete it', function (): void {
    $guard = Guard::create(['name' => 'custom', 'driver' => 'passport', 'provider' => 'users']);
    Role::create(['name' => 'api-reader', 'guard_name' => 'custom']);
    $service = new GuardService(app('config'));

    expect(fn () => $service->update($guard, 'passport', null))
        ->toThrow(ClearanceProtectedResourceException::class, 'Guard [custom] has roles or permissions: its driver and provider cannot be changed.');

    expect(fn () => $service->update($guard, 'token', 'users'))
        ->toThrow(ClearanceProtectedResourceException::class);

    expect(fn () => $service->delete($guard))
        ->toThrow(ClearanceProtectedResourceException::class, 'Guard [custom] has roles or permissions and cannot be deleted.');

    expect($guard->fresh()->provider)->toBe('users');
});

it('allows an unchanged save of a guard in use', function (): void {
    $guard = Guard::create(['name' => 'custom', 'driver' => 'passport', 'provider' => 'users']);
    t106Permission('orders-read', 'custom');

    $updated = (new GuardService(app('config')))->update($guard, 'passport', 'users');

    expect($updated->provider)->toBe('users');
});

it('changes and deletes a guard that has no role or permission', function (): void {
    $guard = Guard::create(['name' => 'custom', 'driver' => 'passport', 'provider' => 'users']);
    $service = new GuardService(app('config'));

    $updated = $service->update($guard, 'token', null);
    expect($updated->driver)->toBe('token');

    $service->delete($updated);
    expect(Guard::where('name', 'custom')->exists())->toBeFalse();
});

it('shows the refusal in the DeleteGuard modal', function (): void {
    $guard = Guard::create(['name' => 'custom', 'driver' => 'passport', 'provider' => 'users']);
    t106Permission('orders-read', 'custom');

    $component = Livewire::test(DeleteGuard::class, ['guardId' => $guard->id, 'name' => 'custom'])
        ->set('confirmText', 'DELETE custom')
        ->call('confirmDelete');

    expect($component->get('errorMessage'))->toBe('Guard [custom] has roles or permissions and cannot be deleted.')
        ->and(Guard::where('name', 'custom')->exists())->toBeTrue();
});

it('builds a SQL Server group expression without INSTR or SUBSTR(', function (): void {
    $expression = PermissionManager::groupExpression('sqlsrv');

    expect($expression)->toContain('CHARINDEX')
        ->and($expression)->toContain('SUBSTRING(')
        ->and($expression)->not->toContain('INSTR')
        ->and($expression)->not->toContain('SUBSTR(');
});

it('builds the group expression of the other drivers', function (): void {
    expect(PermissionManager::groupExpression('mysql'))->toContain('SUBSTRING_INDEX')
        ->and(PermissionManager::groupExpression('mariadb'))->toContain('SUBSTRING_INDEX')
        ->and(PermissionManager::groupExpression('pgsql'))->toContain('SPLIT_PART')
        ->and(PermissionManager::groupExpression('sqlite'))->toContain('INSTR(name')
        ->and(PermissionManager::groupExpression('other'))->toContain('INSTR(name');
});

it('escapes the wildcards and the escape character of a search term', function (): void {
    expect(LikePattern::contains('a%b_c!d'))->toBe('%a!%b!_c!!d%');
});

it('lists no role for a search of a percent sign', function (): void {
    Role::create(['name' => 'admin', 'guard_name' => 'web']);
    Role::create(['name' => 'editor', 'guard_name' => 'web']);

    $component = Livewire::test(RoleManager::class)->set('search', '%');

    expect($component->viewData('roleData')->count())->toBe(0);
});

it('lists only the group that contains an underscore literally', function (): void {
    t106Permission('ab_cd-read');
    t106Permission('abxcd-read');

    $component = Livewire::test(PermissionManager::class)->set('search', 'b_c');
    $groups = collect($component->viewData('groupedPermissions')->items())->pluck('group')->all();

    expect($groups)->toBe(['ab_cd']);
});

it('caps the incoming search value of the role and guard lists', function (): void {
    expect(Livewire::test(RoleManager::class)->set('search', str_repeat('a', 150))->get('search'))->toHaveLength(100)
        ->and(Livewire::test(GuardManager::class)->set('search', str_repeat('a', 150))->get('search'))->toHaveLength(100);
});
