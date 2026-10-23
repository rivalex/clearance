<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Rivalex\Clearance\Exceptions\ClearanceProtectedResourceException;
use Rivalex\Clearance\Livewire\Permissions\DeletePermission;
use Rivalex\Clearance\Livewire\Permissions\PermissionForm;
use Rivalex\Clearance\Livewire\Permissions\PermissionGroupRow;
use Rivalex\Clearance\Models\Permission;
use Rivalex\Clearance\Services\PermissionService;
use Rivalex\Clearance\Tests\Support\FakeEloquentUser;
use Rivalex\Clearance\Tests\Support\T95ThrowingPermissionService;
use Spatie\Permission\Models\Role;

// Findings #10, #19, #20, #38: reserved permission names, exact group matching, locked group row
// state and atomic group deletion.

beforeEach(function (): void {
    $this->runMigrations();

    Livewire::withoutLazyLoading();

    $this->permissions = app(PermissionService::class);
});

function t95Permission(string $name): Permission
{
    return Permission::create(['name' => $name, 'guard_name' => 'web']);
}

function t95ActAsSuperAdmin(): void
{
    Schema::create('fake_users', static function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->string('password');
        $table->timestamps();
    });

    $actor = FakeEloquentUser::create(['name' => 'root', 'email' => 'root@example.com', 'password' => 'x']);
    $actor->assignRole(Role::findOrCreate('super_admin', 'web'));
    $actor->givePermissionTo(Permission::findOrCreate((string) config('clearance.access_permission', 'clearance-access'), 'web'));

    test()->actingAs($actor, 'web');
}

it('refuses to create a clearance-prefixed permission', function (): void {
    expect(fn () => $this->permissions->create('clearance-x', 'web'))
        ->toThrow(ClearanceProtectedResourceException::class);

    expect(Permission::where('name', 'clearance-x')->exists())->toBeFalse();
});

it('refuses to create the configured access permission', function (): void {
    config()->set('clearance.access_permission', 'panel-open');

    expect(fn () => $this->permissions->create('panel-open', 'web'))
        ->toThrow(ClearanceProtectedResourceException::class);
});

it('refuses a whole group before writing anything when one target name is reserved', function (): void {
    config()->set('clearance.access_permission', 'orders-open');

    expect(fn () => $this->permissions->createGroup('orders', 'web', ['read', 'open']))
        ->toThrow(ClearanceProtectedResourceException::class);

    expect(Permission::where('name', 'orders-read')->exists())->toBeFalse();
});

it('refuses a group update before writing anything when one added name is reserved', function (): void {
    config()->set('clearance.access_permission', 'orders-open');
    t95Permission('orders-read');
    t95Permission('orders-open');

    expect(fn () => $this->permissions->updateGroup('orders', 'web', ['read', 'open'], ['read', 'export']))
        ->toThrow(ClearanceProtectedResourceException::class);

    expect(Permission::where('name', 'orders-export')->exists())->toBeFalse()
        ->and(Permission::where('name', 'orders-open')->exists())->toBeTrue();
});

it('shows a reserved name from the form as an error message', function (): void {
    config()->set('clearance.access_permission', 'orders-open');

    Livewire::test(PermissionForm::class)
        ->set('prefix', 'orders')
        ->set('guardName', 'web')
        ->set('customAbilities', ['open'])
        ->call('save')
        ->assertSet('errorMessage', fn (?string $message) => $message !== null && str_contains($message, 'orders-open'));

    expect(Permission::where('name', 'orders-open')->exists())->toBeFalse();
});

it('refuses to rename a permission to the configured access permission', function (): void {
    config()->set('clearance.access_permission', 'panel-open');
    $permission = t95Permission('orders-read');

    expect(fn () => $this->permissions->rename($permission, 'panel-open'))
        ->toThrow(ClearanceProtectedResourceException::class);

    expect($permission->fresh()->name)->toBe('orders-read');
});

it('refuses to rename or delete the configured access permission', function (): void {
    config()->set('clearance.access_permission', 'panel-open');
    $permission = t95Permission('panel-open');

    expect(fn () => $this->permissions->rename($permission, 'orders-read'))
        ->toThrow(ClearanceProtectedResourceException::class)
        ->and(fn () => $this->permissions->delete($permission))
        ->toThrow(ClearanceProtectedResourceException::class);

    expect($permission->fresh()->name)->toBe('panel-open');
});

it('still validates the clearance-access name as a well-formed name', function (): void {
    $this->permissions->validate('clearance-access');

    expect(true)->toBeTrue();
});

it('deletes group audit_log and leaves audit-log permissions intact', function (): void {
    config()->set('clearance.access_permission', 'audit-log-read');
    t95Permission('audit_log-read');
    t95Permission('audit_log-delete');
    t95Permission('audit-log-read');
    t95Permission('auditXlog-read');
    t95ActAsSuperAdmin();

    Livewire::test(DeletePermission::class, ['prefix' => 'audit_log', 'guard' => 'web', 'groupKey' => 'k'])
        ->set('confirmText', 'DELETE audit_log')
        ->call('confirmDelete');

    expect(Permission::pluck('name')->sort()->values()->all())->toBe(['audit-log-read', 'auditXlog-read']);
});

it('does not list audit-log abilities in the audit_log group', function (): void {
    t95Permission('audit_log-read');
    t95Permission('audit-log-export');

    expect(collect(Permission::abilities(group: 'audit_log', guard: 'web')['abilities'])->pluck('name')->all())
        ->toBe(['read']);
});

it('keeps the group intact when a delete fails halfway', function (): void {
    t95ActAsSuperAdmin();
    t95Permission('orders-read');
    t95Permission('orders-create');
    t95Permission('orders-update');

    app()->instance(PermissionService::class, new T95ThrowingPermissionService(app('config')));

    expect(fn () => Livewire::test(DeletePermission::class, ['prefix' => 'orders', 'guard' => 'web', 'groupKey' => 'k'])
        ->set('confirmText', 'DELETE orders')
        ->call('confirmDelete'))
        ->toThrow(RuntimeException::class);

    expect(Permission::where('name', 'like', 'orders-%')->count())->toBe(3);
});

it('does not delete a group that contains a protected permission', function (): void {
    config()->set('clearance.access_permission', 'panel-open');
    t95Permission('panel-open');
    t95Permission('panel-read');
    t95ActAsSuperAdmin();

    Livewire::test(DeletePermission::class, ['prefix' => 'panel', 'guard' => 'web', 'groupKey' => 'k'])
        ->set('confirmText', 'DELETE panel')
        ->call('confirmDelete');

    expect(Permission::where('name', 'like', 'panel-%')->count())->toBe(2);
});

it('rejects a tampered prefix on the group row', function (): void {
    t95Permission('orders-read');

    expect(fn () => Livewire::test(PermissionGroupRow::class, ['group' => 'orders', 'guard' => 'web'])
        ->set('prefix', 'audit'))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});
