<?php

declare(strict_types=1);

use Illuminate\Auth\Events\Registered;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Rivalex\Clearance\Listeners\AssignDefaultRole;
use Rivalex\Clearance\Livewire\Settings;
use Rivalex\Clearance\Livewire\Settings\BulkAssignDefaultRole;
use Rivalex\Clearance\Livewire\Settings\GeneralSettings;
use Rivalex\Clearance\Models\ClearanceSettings;
use Rivalex\Clearance\Models\RoleMeta;
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
});

function t76User(string $email): FakeEloquentUser
{
    return FakeEloquentUser::create(['name' => $email, 'email' => $email, 'password' => 'x']);
}

/** A non-super_admin user holding clearance-access, the actor of every component case. */
function t76Actor(): FakeEloquentUser
{
    $actor = t76User('t76-actor@example.com');
    $actor->givePermissionTo(Permission::findOrCreate('clearance-access', 'web'));

    return $actor;
}

function t76Role(string $name, string ...$permissions): Role
{
    $role = Role::create(['name' => $name, 'guard_name' => 'web']);

    foreach ($permissions as $permission) {
        $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    return $role;
}

it('refuses super_admin as default role in GeneralSettings and keeps the stored setting', function (): void {
    $super = t76Role('super_admin');
    $member = t76Role('member');
    ClearanceSettings::set('default_role_id', (string) $member->id);

    Livewire::actingAs(t76Actor())->test(GeneralSettings::class)
        ->set('defaultRole', (string) $super->id)
        ->call('saveGeneral')
        ->assertHasErrors('defaultRole');

    expect(ClearanceSettings::get('default_role_id'))->toBe((string) $member->id);
});

it('refuses a default role with a permission the actor does not hold in GeneralSettings', function (): void {
    $member = t76Role('member');
    $editor = t76Role('editor', 'posts-edit');
    ClearanceSettings::set('default_role_id', (string) $member->id);

    Livewire::actingAs(t76Actor())->test(GeneralSettings::class)
        ->set('defaultRole', (string) $editor->id)
        ->call('saveGeneral')
        ->assertHasErrors('defaultRole');

    expect(ClearanceSettings::get('default_role_id'))->toBe((string) $member->id);
});

it('refuses a contextual role as default role in GeneralSettings', function (): void {
    $role = t76Role('tenant-staff');
    RoleMeta::create(['role_id' => $role->id, 'scope' => RoleMeta::SCOPE_CONTEXTUAL]);

    Livewire::actingAs(t76Actor())->test(GeneralSettings::class)
        ->set('defaultRole', (string) $role->id)
        ->call('saveGeneral')
        ->assertHasErrors('defaultRole');

    expect(ClearanceSettings::get('default_role_id'))->toBeNull();
});

it('assigns nobody when the stored default role holds a permission the actor lacks', function (): void {
    $editor = t76Role('editor', 'posts-edit');
    ClearanceSettings::set('default_role_id', (string) $editor->id);
    $other = t76User('t76-other@example.com');

    Livewire::actingAs(t76Actor())->test(BulkAssignDefaultRole::class)
        ->call('bulkAssignDefaultRole')
        ->assertSet('bulkSuccess', false);

    expect($other->fresh()->hasRole('editor'))->toBeFalse();
});

it('assigns nobody when the stored default role is super_admin', function (): void {
    $super = t76Role('super_admin');
    ClearanceSettings::set('default_role_id', (string) $super->id);
    $other = t76User('t76-other@example.com');

    Livewire::actingAs(t76Actor())->test(BulkAssignDefaultRole::class)
        ->call('bulkAssignDefaultRole')
        ->assertSet('bulkSuccess', false);

    expect($other->fresh()->hasRole('super_admin'))->toBeFalse();
});

it('uses the stored default role, not the client property, in the legacy Settings bulk assign', function (): void {
    Livewire::withoutLazyLoading();

    $super = t76Role('super_admin');
    $member = t76Role('member');
    ClearanceSettings::set('default_role_id', (string) $member->id);
    $other = t76User('t76-other@example.com');

    Livewire::actingAs(t76Actor())->test(Settings::class)
        ->set('defaultRole', (string) $super->id)
        ->call('bulkAssignDefaultRole');

    expect($other->fresh()->hasRole('super_admin'))->toBeFalse()
        ->and($other->fresh()->hasRole('member'))->toBeTrue();
});

it('stores nothing when the legacy Settings saves super_admin as default role', function (): void {
    Livewire::withoutLazyLoading();

    $super = t76Role('super_admin');

    Livewire::actingAs(t76Actor())->test(Settings::class)
        ->set('defaultRole', (string) $super->id)
        ->call('saveGeneral')
        ->assertHasErrors('defaultRole');

    expect(ClearanceSettings::get('default_role_id'))->toBeNull();
});

it('renders the legacy Settings component', function (): void {
    Livewire::withoutLazyLoading();

    Livewire::actingAs(t76Actor())->test(Settings::class)->assertOk();
});

it('skips super_admin, system-assigned-only and contextual default roles in the listener without throwing', function (): void {
    config()->set('clearance.auto_assign_default_role', true);
    config()->set('clearance.non_delegable', ['billing-*']);

    $superAdmin = t76Role('super_admin');
    $billingAdmin = t76Role('billing-admin', 'billing-update');
    $contextual = t76Role('tenant-staff');
    RoleMeta::create(['role_id' => $contextual->id, 'scope' => RoleMeta::SCOPE_CONTEXTUAL]);
    $member = t76Role('member');

    foreach (['super_admin' => $superAdmin, 'billing-admin' => $billingAdmin, 'tenant-staff' => $contextual] as $name => $skipped) {
        $index = $skipped->id;
        ClearanceSettings::set('default_role_id', (string) $skipped->id);
        $user = t76User("t76-skip-{$index}@example.com");

        app(AssignDefaultRole::class)->handle(new Registered($user));

        expect($user->fresh()->hasRole($name))->toBeFalse();
    }

    ClearanceSettings::set('default_role_id', (string) $member->id);
    $user = t76User('t76-ok@example.com');

    app(AssignDefaultRole::class)->handle(new Registered($user));

    expect($user->fresh()->hasRole('member'))->toBeTrue();
});

it('includes a super_admin actor in a bulk assignment and skips a non-super_admin actor', function (): void {
    $role = t76Role('member');
    $service = app(UserClearanceService::class);

    $admin = t76User('t76-admin@example.com');
    t76Role('super_admin');
    $admin->assignRole('super_admin');
    $target = t76User('t76-target@example.com');

    expect($service->bulkAssignGlobalRoleAs($admin, $role, [$admin, $target]))->toBe(2)
        ->and($admin->fresh()->hasRole('member'))->toBeTrue();

    $actor = t76Actor();
    $second = t76User('t76-second@example.com');

    expect($service->bulkAssignGlobalRoleAs($actor, $role, [$actor, $second]))->toBe(1)
        ->and($actor->fresh()->hasRole('member'))->toBeFalse()
        ->and($second->fresh()->hasRole('member'))->toBeTrue();
});
