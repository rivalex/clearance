<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Rivalex\Clearance\Livewire\Settings\BulkAssignDefaultRole;
use Rivalex\Clearance\Models\ClearanceSettings;
use Rivalex\Clearance\Tests\Support\FakeEloquentUser;
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
});

it('reports no default role configured', function (): void {
    Livewire::test(BulkAssignDefaultRole::class)
        ->call('bulkAssignDefaultRole')
        ->assertSet('bulkSuccess', false)
        ->assertSet('bulkMessage', __('clearance::ui.settings.bulk_no_role'));
});

it('reports role not found when the configured default role was deleted', function (): void {
    ClearanceSettings::set('default_role', 'ghost-role');

    Livewire::test(BulkAssignDefaultRole::class)
        ->call('bulkAssignDefaultRole')
        ->assertSet('bulkSuccess', false)
        ->assertSet('bulkMessage', __('clearance::ui.settings.bulk_role_not_found'));
});

it('reports no user model when it cannot be resolved', function (): void {
    ClearanceSettings::set('default_role', 'member');
    Role::create(['name' => 'member', 'guard_name' => 'web']);
    config()->set('clearance.user_model', null);
    config()->set('auth.providers.users.model', 'App\\Models\\NonExistentUser');

    Livewire::test(BulkAssignDefaultRole::class)
        ->call('bulkAssignDefaultRole')
        ->assertSet('bulkSuccess', false)
        ->assertSet('bulkMessage', __('clearance::ui.settings.bulk_no_user_model'));
});

it('assigns the default role only to users who do not already have it', function (): void {
    ClearanceSettings::set('default_role', 'member');
    $role = Role::create(['name' => 'member', 'guard_name' => 'web']);

    $withRole = FakeEloquentUser::create(['name' => 'Has', 'email' => 't61-1@example.com', 'password' => 'x']);
    $withRole->assignRole($role);

    $withoutRoleA = FakeEloquentUser::create(['name' => 'A', 'email' => 't61-2@example.com', 'password' => 'x']);
    $withoutRoleB = FakeEloquentUser::create(['name' => 'B', 'email' => 't61-3@example.com', 'password' => 'x']);

    Livewire::test(BulkAssignDefaultRole::class)
        ->call('bulkAssignDefaultRole')
        ->assertSet('bulkSuccess', true)
        ->assertSet('bulkMessage', __('clearance::ui.settings.bulk_done', ['count' => 2]));

    expect($withoutRoleA->fresh()->hasRole('member'))->toBeTrue()
        ->and($withoutRoleB->fresh()->hasRole('member'))->toBeTrue();
});

it('renders the bulk-assign-default-role view', function (): void {
    Livewire::test(BulkAssignDefaultRole::class)
        ->assertViewIs('clearance::livewire.settings.bulk-assign-default-role');
});
