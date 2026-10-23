<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Rivalex\Clearance\Livewire\Settings\GeneralSettings;
use Rivalex\Clearance\Models\ClearanceSettings;
use Rivalex\Clearance\Tests\Support\FakeEloquentUser;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $this->runMigrations();
});

/** A non-super_admin user holding clearance-access, the actor of the successful save. */
function t56Actor(): FakeEloquentUser
{
    if (! Schema::hasTable('fake_users')) {
        Schema::create('fake_users', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamps();
        });
    }

    $actor = FakeEloquentUser::create(['name' => 'Actor', 'email' => 't56-actor@example.com', 'password' => 'x']);
    $actor->givePermissionTo(Permission::findOrCreate('clearance-access', 'web'));

    return $actor;
}

it('mount defaults to no default role and icons shown when nothing is stored', function (): void {
    Livewire::test(GeneralSettings::class)
        ->assertSet('defaultRole', '')
        ->assertSet('showIcons', true);
});

it('mount hydrates from stored settings', function (): void {
    $manager = Role::create(['name' => 'manager', 'guard_name' => 'web']);
    ClearanceSettings::set('default_role_id', (string) $manager->id);
    ClearanceSettings::set('show_icons', '0');

    Livewire::test(GeneralSettings::class)
        ->assertSet('defaultRole', (string) $manager->id)
        ->assertSet('showIcons', false);
});

it('renders roles ordered by name', function (): void {
    Role::create(['name' => 'zebra', 'guard_name' => 'web']);
    Role::create(['name' => 'alpha', 'guard_name' => 'web']);

    Livewire::test(GeneralSettings::class)
        ->assertViewHas('roles', fn ($roles) => $roles->pluck('name')->all() === ['alpha', 'zebra']);
});

it('rejects saving a default role that does not exist', function (): void {
    Livewire::test(GeneralSettings::class)
        ->set('defaultRole', 'ghost')
        ->call('saveGeneral')
        ->assertHasErrors('defaultRole');

    expect(ClearanceSettings::get('default_role_id'))->toBeNull();
});

it('persists default role and show_icons on successful save', function (): void {
    $role = Role::create(['name' => 'manager', 'guard_name' => 'web']);

    Livewire::actingAs(t56Actor())->test(GeneralSettings::class)
        ->set('defaultRole', (string) $role->id)
        ->set('showIcons', false)
        ->call('saveGeneral')
        ->assertHasNoErrors()
        ->assertSet('saveMessage', __('clearance::ui.settings.general.saved'));

    expect(ClearanceSettings::get('default_role_id'))->toBe((string) $role->id)
        ->and(ClearanceSettings::get('show_icons'))->toBe('0');
});

it('stores null default_role when the field is cleared', function (): void {
    $manager = Role::create(['name' => 'manager', 'guard_name' => 'web']);
    ClearanceSettings::set('default_role_id', (string) $manager->id);

    Livewire::test(GeneralSettings::class)
        ->set('defaultRole', '')
        ->call('saveGeneral')
        ->assertHasNoErrors();

    expect(ClearanceSettings::get('default_role_id'))->toBeNull();
});
