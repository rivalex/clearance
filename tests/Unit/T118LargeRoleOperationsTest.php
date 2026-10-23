<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Rivalex\Clearance\Livewire\Roles\DeleteRole;
use Rivalex\Clearance\Models\UserRoleContext;
use Rivalex\Clearance\Tests\Support\FakeContext;
use Rivalex\Clearance\Tests\Support\FakeEloquentUser;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

// Large role operations: the Roles page refuses a delete above the holder limit and writes nothing,
// the console command deletes it as a super_admin actor, detaching or reassigning the holders.

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
    config()->set('clearance.panel_role_holder_limit', 3);

    Permission::findOrCreate('clearance-access', 'web');
    Permission::findOrCreate('clearance-roles-write', 'web');
});

function t118User(string $email): FakeEloquentUser
{
    return FakeEloquentUser::create(['name' => $email, 'email' => $email, 'password' => 'x']);
}

function t118Root(): FakeEloquentUser
{
    $root = t118User('root@t118.test');
    $root->assignRole(Role::findOrCreate('super_admin', 'web'));
    $root->givePermissionTo('clearance-access', 'clearance-roles-write');

    return $root;
}

function t118Writer(): FakeEloquentUser
{
    $writer = t118User('writer@t118.test');
    $writer->givePermissionTo('clearance-access', 'clearance-roles-write');

    return $writer;
}

function t118Role(string $name, int $holders): Role
{
    $role = Role::create(['name' => $name, 'guard_name' => 'web']);

    for ($i = 0; $i < $holders; $i++) {
        t118User("{$name}-{$i}@t118.test")->assignRole($role);
    }

    return $role;
}

function t118Pivots(Role $role): int
{
    return DB::table(config('permission.table_names.model_has_roles'))->where('role_id', $role->id)->count();
}

it('refuses a panel delete above the limit and writes nothing', function (): void {
    $role = t118Role('bulk', 4);
    $this->actingAs(t118Root(), 'web');

    Livewire::test(DeleteRole::class, ['roleId' => $role->id])
        ->set('confirmText', 'DELETE bulk')
        ->call('confirmDelete')
        ->assertSet('errorMessage', fn (?string $m): bool => $m !== null && str_contains($m, 'php artisan clearance:delete-role'))
        ->assertNotDispatched('role-deleted');

    expect(Role::where('name', 'bulk')->exists())->toBeTrue()
        ->and(t118Pivots($role))->toBe(4);
});

it('counts contextual bindings toward the limit', function (): void {
    Schema::create('fake_contexts', static function (Blueprint $table): void {
        $table->id();
        $table->string('name')->nullable();
        $table->timestamps();
    });

    $role = t118Role('bulk', 2);

    foreach ([1, 2] as $contextId) {
        UserRoleContext::create([
            'user_id' => 1,
            'role_id' => $role->id,
            'context_type' => FakeContext::class,
            'context_id' => $contextId,
        ]);
    }

    $this->actingAs(t118Root(), 'web');

    Livewire::test(DeleteRole::class, ['roleId' => $role->id])
        ->set('confirmText', 'DELETE bulk')
        ->call('confirmDelete')
        ->assertSet('errorMessage', fn (?string $m): bool => $m !== null && str_contains($m, 'php artisan clearance:delete-role'))
        ->assertNotDispatched('role-deleted');

    expect(Role::where('name', 'bulk')->exists())->toBeTrue()
        ->and(t118Pivots($role))->toBe(2)
        ->and(UserRoleContext::where('role_id', $role->id)->count())->toBe(2);
});

it('deletes from the panel at the limit', function (): void {
    $role = t118Role('bulk', 3);
    $this->actingAs(t118Root(), 'web');

    Livewire::test(DeleteRole::class, ['roleId' => $role->id])
        ->set('confirmText', 'DELETE bulk')
        ->call('confirmDelete')
        ->assertSet('errorMessage', null)
        ->assertDispatched('role-deleted');

    expect(Role::where('name', 'bulk')->exists())->toBeFalse();
});

it('deletes a large role from the console as a super_admin and detaches its holders', function (): void {
    $role = t118Role('bulk', 4);
    $root = t118Root();

    $exit = Artisan::call('clearance:delete-role', ['role' => 'bulk', '--actor' => (string) $root->id, '--force' => true]);
    $out = Artisan::output();

    expect($exit)->toBe(0)
        ->and($out)->toContain('Role [bulk] deleted: 4 global holder(s) and 0 contextual binding(s) removed.')
        ->and(Role::where('name', 'bulk')->exists())->toBeFalse()
        ->and(t118Pivots($role))->toBe(0);
});

it('refuses a console delete by an actor who is not a super_admin', function (): void {
    $role = t118Role('bulk', 4);
    $writer = t118Writer();

    $exit = Artisan::call('clearance:delete-role', ['role' => 'bulk', '--actor' => (string) $writer->id, '--force' => true]);
    $out = Artisan::output();

    expect($exit)->toBe(1)
        ->and($out)->toContain("The actor [{$writer->id}] is not a super_admin.")
        ->and(Role::where('name', 'bulk')->exists())->toBeTrue()
        ->and(t118Pivots($role))->toBe(4);
});

it('reassigns the holders from the console', function (): void {
    t118Role('bulk', 4);
    $member = Role::create(['name' => 'member', 'guard_name' => 'web']);
    $root = t118Root();

    $exit = Artisan::call('clearance:delete-role', ['role' => 'bulk', '--reassign' => 'member', '--actor' => (string) $root->id, '--force' => true]);
    $out = Artisan::output();

    expect($exit)->toBe(0)
        ->and($out)->toContain('moved to [member]')
        ->and(t118Pivots($member))->toBe(4)
        ->and(Role::where('name', 'bulk')->exists())->toBeFalse();
});

it('deletes nothing without --force in a non-interactive run', function (): void {
    t118Role('bulk', 4);
    $root = t118Root();

    $exit = Artisan::call('clearance:delete-role', ['role' => 'bulk', '--actor' => (string) $root->id, '--no-interaction' => true]);
    $out = Artisan::output();

    expect($exit)->toBe(1)
        ->and($out)->toContain('Nothing deleted.')
        ->and(Role::where('name', 'bulk')->exists())->toBeTrue();
});
