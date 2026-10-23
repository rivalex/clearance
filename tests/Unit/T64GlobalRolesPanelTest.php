<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Rivalex\Clearance\Livewire\Users\GlobalRolesPanel;
use Rivalex\Clearance\Models\RoleMeta;
use Rivalex\Clearance\Tests\Support\FakeEloquentUser;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $this->runMigrations();
    config()->set('clearance.modules.users', true);

    // GlobalRolesPanel is #[Lazy]; disable it so mount() runs on Livewire::test().
    Livewire::withoutLazyLoading();

    Schema::create('fake_users', static function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->string('password');
        $table->timestamps();
    });

    config()->set('clearance.user_model', FakeEloquentUser::class);

    Permission::create(['name' => 'clearance-access', 'guard_name' => 'web']);

    $this->actor = FakeEloquentUser::create(['name' => 'Actor', 'email' => 't64-actor@example.com', 'password' => 'x']);
    $this->actor->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));
    $this->actor->givePermissionTo('clearance-access');

    $this->user = FakeEloquentUser::create(['name' => 'U', 'email' => 't64-user@example.com', 'password' => 'x']);
});

it('renders assigned roles with their guard permissions', function (): void {
    $role = Role::create(['name' => 'manager', 'guard_name' => 'web']);
    Permission::create(['name' => 'orders-read', 'guard_name' => 'web']);
    $this->user->assignRole($role);

    Livewire::test(GlobalRolesPanel::class, ['userId' => $this->user->id])
        ->assertViewHas('roleCards', fn ($cards) => count($cards) === 1 && $cards[0]['role']->id === $role->id);
});

it('excludes contextual-only roles from available master roles', function (): void {
    $globalRole = Role::create(['name' => 'staff', 'guard_name' => 'web']);
    $contextualRole = Role::create(['name' => 'store-manager', 'guard_name' => 'web']);
    RoleMeta::create(['role_id' => $contextualRole->id, 'scope' => RoleMeta::SCOPE_CONTEXTUAL, 'context_types' => []]);

    $names = collect(
        Livewire::test(GlobalRolesPanel::class, ['userId' => $this->user->id])
            ->viewData('availableMasterRoles')
    )->pluck('name')->all();

    expect($names)->toContain('staff')
        ->not->toContain('store-manager');
});

it('initializes manualPermissions with the directly held extra permissions only', function (): void {
    $role = Role::create(['name' => 'manager', 'guard_name' => 'web']);
    $rolePerm = Permission::create(['name' => 'orders-read', 'guard_name' => 'web']);
    $extraPerm = Permission::create(['name' => 'orders-delete', 'guard_name' => 'web']);
    $direct = Permission::create(['name' => 'orders-update', 'guard_name' => 'web']);
    $role->givePermissionTo($rolePerm);
    $this->user->assignRole($role);
    $this->user->givePermissionTo($direct);

    $component = Livewire::test(GlobalRolesPanel::class, ['userId' => $this->user->id]);

    $manual = $component->get('manualPermissions');

    expect($manual[$role->id])->toBe([$direct->id => true]);
});

it('saveExtraPerms grants a direct permission when the actor is entitled to it', function (): void {
    $role = Role::create(['name' => 'manager', 'guard_name' => 'web']);
    $extraPerm = Permission::create(['name' => 'orders-delete', 'guard_name' => 'web']);
    $this->user->assignRole($role);
    $this->actor->givePermissionTo($extraPerm);

    $this->actingAs($this->actor, 'web');

    Livewire::test(GlobalRolesPanel::class, ['userId' => $this->user->id])
        ->set("manualPermissions.{$role->id}.{$extraPerm->id}", true)
        ->call('saveExtraPerms', $role->id);

    expect($this->user->fresh()->hasDirectPermission('orders-delete'))->toBeTrue();
});

it('renders the global-roles-panel view', function (): void {
    Livewire::test(GlobalRolesPanel::class, ['userId' => $this->user->id])
        ->assertViewIs('clearance::livewire.users.global-roles-panel');
});
